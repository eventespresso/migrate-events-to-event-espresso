<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\wp_event_manager;

use EventEspresso\EventMigrator\application\services\migration\records\SourceDatetimeRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\records\SourceEventRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceTicketRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceVenueRecord;
use EventEspresso\EventMigrator\application\services\migration\records\UnmappedConcept;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceZone;

/**
 * Class WpEventManagerEventReader
 *
 * Reads one WP Event Manager event into a SourceEventRecord (research profile
 * §8): the post's title, content, author and status (`_cancelled` replaces
 * it); one datetime from the local start and end meta, in the site's zone or,
 * when the plugin is set to give each event a zone, in `_event_timezone`; the
 * first venue post, or the event's free-text location, or its video link as a
 * virtual venue; the categories; the implicit ticket its free/paid option and
 * free-text price describe (Phase 2 implicit-ticket contract); and the concepts
 * Event Espresso has no counterpart for (organizers, registration method,
 * event types, tags, banner).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class WpEventManagerEventReader
{
    private SourceDatabase $db;

    private ?bool $each_event_zone = null;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * The first venue id of an event's `_event_venue_ids` list; 0 when it has none.
     *
     * @param array $stored The unserialised meta value.
     */
    public static function firstId(array $stored): int
    {
        $ids = array_values($stored);
        return (int) ($ids[0] ?? 0);
    }


    /**
     * @throws MigrationRecordException When the event does not exist, has no readable date, or a read fails.
     */
    public function read(string $source_event_id): SourceEventRecord
    {
        $id     = (int) $source_event_id;
        $posts  = $this->db->posts([$id]);
        $fields = $this->db->postMeta([$id], WpEventManagerSchema::eventMetaKeys())[ $id ] ?? [];
        $zone   = $this->zone($fields);
        $start  = $this->moment((string) ($fields['_event_start_date'] ?? ''), (string) ($fields['_event_start_time'] ?? ''), $zone);
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        if (! isset($posts[ $id ]) || $posts[ $id ]['post_type'] !== WpEventManagerSchema::POST_TYPE_EVENT) {
            throw $this->failure(esc_html__('The source event no longer exists.', 'migrate-events-to-event-espresso'), $source_event_id);
        }
        if ($start === null) {
            throw $this->failure(esc_html__('The source event has no readable date.', 'migrate-events-to-event-espresso'), $source_event_id);
        }
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        $end  = $this->moment((string) ($fields['_event_end_date'] ?? ''), (string) ($fields['_event_end_time'] ?? ''), $zone) ?? $start;
        $post = $posts[ $id ];
        return new SourceEventRecord(
            $source_event_id,
            (string) $post['post_title'],
            [
                'description'       => (string) $post['post_content'],
                'status'            => ((string) ($fields['_cancelled'] ?? '0')) === '1'
                    ? WpEventManagerStatusTable::CANCELLED
                    : strtolower((string) $post['post_status']),
                'author_wp_user_id' => (int) $post['post_author'],
                'featured_image_id' => (int) ($fields['_thumbnail_id'] ?? 0),
                'categories'        => $this->terms($id, WpEventManagerSchema::TAXONOMY_CATEGORY),
                'venue'             => $this->venue($fields),
                'datetimes'         => [new SourceDatetimeRecord($source_event_id, $start, $end)],
                'tickets'           => $this->tickets($source_event_id, $fields, $zone),
                'unmapped'          => $this->unmapped($id, $fields),
                'timezone'          => $zone,
                'created_at'        => (string) $post['post_date'] !== ''
                    ? new SourceDateTime((string) $post['post_date'], TimezoneModel::SITE_LOCAL)
                    : null,
                'slug'              => (string) $post['post_name'],
            ]
        );
    }


    /**
     * The event's own zone when the plugin is set to give each event one and the zone is readable; null (the
     * site's zone) otherwise.
     *
     * @throws MigrationRecordException
     */
    private function zone(array $fields): ?string
    {
        if ($this->each_event_zone === null) {
            $this->each_event_zone = trim((string) $this->db->option(WpEventManagerSchema::TIMEZONE_OPTION)) === WpEventManagerSchema::TIMEZONE_EACH_EVENT;
        }
        return $this->each_event_zone ? SourceZone::normalize((string) ($fields['_event_timezone'] ?? '')) : null;
    }


    /**
     * A local datetime: `Y-m-d H:i:s`, or a date (older versions) with the time kept in its own meta; null when
     * unreadable.
     */
    private function moment(string $date, string $time, ?string $zone): ?SourceDateTime
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date) !== 1) {
            return null;
        }
        $value = strlen($date) > 10 ? substr($date, 0, 19) : $date . ' ' . (trim($time) !== '' ? trim($time) : '00:00:00');
        return $zone !== null
            ? new SourceDateTime($value, TimezoneModel::PER_EVENT, $zone)
            : new SourceDateTime($value, TimezoneModel::SITE_LOCAL);
    }


    /**
     * The implicit ticket of an event with a free/paid option: its price text (blank or "free" for a free event),
     * and the registration deadline as the end of sales. An event without the option takes no registrations.
     *
     * @return SourceTicketRecord[]
     */
    private function tickets(string $source_event_id, array $fields, ?string $zone): array
    {
        $option = strtolower(trim((string) ($fields['_event_ticket_options'] ?? '')));
        if ($option === '') {
            return [];
        }
        $price    = $option === 'free' ? '0' : trim((string) ($fields['_event_ticket_price'] ?? ''));
        $deadline = trim((string) ($fields['_event_registration_deadline'] ?? ''));
        $end      = preg_match('/^\d{4}-\d{2}-\d{2}/', $deadline) === 1 ? substr($deadline, 0, 10) . ' 23:59:59' : '';
        return [
            new SourceTicketRecord(
                'event:' . $source_event_id,
                '',
                [
                    'implicit'            => true,
                    'raw_price'           => $price,
                    'sale_end'            => $end !== '' ? $this->moment($end, '', $zone) : null,
                    'datetime_source_ids' => [$source_event_id],
                ]
            ),
        ];
    }


    /**
     * The first venue post (name, address text and zip), or the event's own address text, or its video link.
     * The country is the event's code.
     *
     * @throws MigrationRecordException
     */
    private function venue(array $fields): ?SourceVenueRecord
    {
        $venue_id = self::firstId((array) ($fields['_event_venue_ids'] ?? []));
        $venue    = $venue_id > 0 ? ($this->db->posts([$venue_id])[ $venue_id ] ?? null) : null;
        $country  = trim((string) ($fields['_event_country'] ?? ''));
        if ($venue !== null) {
            $meta = $this->db->postMeta([$venue_id], ['_venue_name', '_venue_location', '_venue_zipcode', '_venue_website'])[ $venue_id ] ?? [];
            return new SourceVenueRecord(
                (string) $venue_id,
                trim((string) ($meta['_venue_name'] ?? '')) ?: (string) $venue['post_title'],
                [
                    'description' => (string) $venue['post_content'],
                    'address'     => (string) ($meta['_venue_location'] ?? ''),
                    'zip'         => (string) ($meta['_venue_zipcode'] ?? ''),
                    'country'     => $country,
                    'url'         => (string) ($meta['_venue_website'] ?? ''),
                ]
            );
        }
        $address = trim((string) ($fields['_event_location'] ?? ''));
        if ($address !== '') {
            return new SourceVenueRecord(
                'loc:' . md5(strtolower($address)),
                $address,
                ['address' => $address, 'zip' => (string) ($fields['_event_pincode'] ?? ''), 'country' => $country, 'text_only' => true]
            );
        }
        $link = trim((string) ($fields['_event_video_url'] ?? ''));
        return ($fields['_event_online'] ?? '') === 'yes' && $link !== ''
            ? new SourceVenueRecord('url:' . md5($link), $link, ['virtual_url' => $link])
            : null;
    }


    /**
     * @return string[] The names of a post's terms of one taxonomy
     * @throws MigrationRecordException
     */
    private function terms(int $post_id, string $taxonomy): array
    {
        return array_values(array_filter(array_map('trim', $this->db->postTerms([$post_id], $taxonomy)[ $post_id ] ?? [])));
    }


    /**
     * @return UnmappedConcept[]
     * @throws MigrationRecordException
     */
    private function unmapped(int $post_id, array $fields): array
    {
        $concepts   = [];
        $organizers = $this->organizers($fields);
        if ($organizers !== []) {
            $concepts[] = new UnmappedConcept('organizer', __('Organizers', 'migrate-events-to-event-espresso'), $organizers);
        }
        $registration = trim((string) ($fields['_registration'] ?? ''));
        if ($registration !== '') {
            $concepts[] = new UnmappedConcept('registration-method', __('How to register', 'migrate-events-to-event-espresso'), [$registration]);
        }
        $types = $this->terms($post_id, WpEventManagerSchema::TAXONOMY_TYPE);
        if ($types !== []) {
            $concepts[] = new UnmappedConcept('event-types', __('Event types', 'migrate-events-to-event-espresso'), $types);
        }
        $tags = trim((string) ($fields['_event_tags'] ?? ''));
        if ($tags !== '') {
            $concepts[] = new UnmappedConcept('tags', __('Tags', 'migrate-events-to-event-espresso'), array_values(array_filter(array_map('trim', explode(',', $tags)))));
        }
        $video = trim((string) ($fields['_event_video_url'] ?? ''));
        if ($video !== '' && ($fields['_event_online'] ?? '') !== 'yes') {
            $concepts[] = new UnmappedConcept('video-link', __('Video link', 'migrate-events-to-event-espresso'), [$video]);
        }
        $banner = $fields['_event_banner'] ?? '';
        $banner = is_array($banner) ? (string) reset($banner) : trim((string) $banner);
        if ($banner !== '' && (int) ($fields['_thumbnail_id'] ?? 0) === 0) {
            $concepts[] = new UnmappedConcept('banner', __('Banner image', 'migrate-events-to-event-espresso'), [$banner]);
        }
        return $concepts;
    }


    /**
     * The organizer names: the organizer posts the event lists, or the organizer fields older versions kept on
     * the event itself.
     *
     * @return string[]
     * @throws MigrationRecordException
     */
    private function organizers(array $fields): array
    {
        $ids = is_array($fields['_event_organizer_ids'] ?? null) ? array_map('intval', $fields['_event_organizer_ids']) : [];
        $out = [];
        foreach ($this->db->postMeta($ids, ['_organizer_name', '_organizer_email']) as $meta) {
            $out[] = trim(($meta['_organizer_name'] ?? '') . ' ' . ($meta['_organizer_email'] ?? ''));
        }
        if ($out === [] && trim((string) ($fields['_organizer_name'] ?? '')) !== '') {
            $out[] = trim($fields['_organizer_name'] . ' ' . ($fields['_organizer_email'] ?? ''));
        }
        return array_values(array_filter($out));
    }


    private function failure(string $message, string $source_event_id): MigrationRecordException
    {
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        return new MigrationRecordException($message, WarningRule::RECORD_FAILED, CrosswalkKeys::TYPE_EVENT, $source_event_id);
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }
}
// End of file WpEventManagerEventReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\wp_event_manager/WpEventManagerEventReader.php
