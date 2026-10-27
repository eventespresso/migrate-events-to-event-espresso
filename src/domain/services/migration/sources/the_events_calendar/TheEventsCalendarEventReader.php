<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar;

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
 * Class TheEventsCalendarEventReader
 *
 * Reads one `tribe_events` post into a SourceEventRecord (research profile
 * §8): the post's title, content, excerpt, author and status; one datetime
 * from the local start/end meta and the event's own timezone (the UTC meta
 * when the zone is unreadable); the linked venue; categories; and the cost
 * text as the event's one implicit ticket. Tags, organizers, the event URL
 * and phone have no Event Espresso counterpart and are kept as unmapped
 * concepts.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class TheEventsCalendarEventReader
{
    private const MULTI_ROW_KEYS = [TheEventsCalendarSchema::META_COST, TheEventsCalendarSchema::META_ORGANIZER];

    private SourceDatabase $db;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * @throws MigrationRecordException When the event does not exist or a read fails.
     */
    public function read(string $source_event_id): SourceEventRecord
    {
        $post_id = (int) $source_event_id;
        $post    = $this->db->posts([$post_id])[ $post_id ] ?? null;
        if ($post === null || $post['post_type'] !== TheEventsCalendarSchema::POST_TYPE_EVENT) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new MigrationRecordException(
                esc_html__('The source event no longer exists.', 'migrate-events-to-event-espresso'),
                WarningRule::RECORD_FAILED,
                CrosswalkKeys::TYPE_EVENT,
                $source_event_id
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $meta   = $this->db->postMeta([$post_id], TheEventsCalendarSchema::eventMetaKeys())[ $post_id ] ?? [];
        $multi  = $this->db->postMetaAll([$post_id], self::MULTI_ROW_KEYS)[ $post_id ] ?? [];
        $zone   = SourceZone::normalize((string) ($meta[ TheEventsCalendarSchema::META_TIMEZONE ] ?? ''));
        $cost   = $this->cost((array) ($multi[ TheEventsCalendarSchema::META_COST ] ?? []));
        return new SourceEventRecord(
            $source_event_id,
            $post['post_title'],
            [
                'description'       => $post['post_content'],
                'excerpt'           => $post['post_excerpt'],
                'status'            => $this->status($post, $meta),
                'trashed'           => in_array($post['post_status'], ['trash', 'tribe-ignored'], true),
                'author_wp_user_id' => (int) $post['post_author'],
                'featured_image_id' => (int) ($meta[ TheEventsCalendarSchema::META_THUMBNAIL ] ?? 0),
                'categories'        => $this->db->postTerms([$post_id], TheEventsCalendarSchema::TAXONOMY_CATEGORY)[ $post_id ] ?? [],
                'venue'             => $this->venue((int) ($meta[ TheEventsCalendarSchema::META_VENUE ] ?? 0)),
                'datetimes'         => [$this->datetime($source_event_id, $meta, $zone)],
                'tickets'           => [
                    new SourceTicketRecord($source_event_id, '', ['implicit' => true, 'raw_price' => $cost]),
                ],
                'unmapped'          => $this->unmapped($post_id, $meta, (array) ($multi[ TheEventsCalendarSchema::META_ORGANIZER ] ?? [])),
                'timezone'          => $zone,
                'created_at'        => $post['post_date'] !== ''
                    ? new SourceDateTime($post['post_date'], TimezoneModel::SITE_LOCAL)
                    : null,
                'slug'              => $post['post_name'],
            ]
        );
    }


    /**
     * "cancelled" or "postponed" when the event's status meta says so, otherwise the post's status.
     */
    private function status(array $post, array $meta): string
    {
        $event_status = strtolower(trim((string) ($meta[ TheEventsCalendarSchema::META_STATUS ] ?? '')));
        if ($event_status === 'canceled' || $event_status === 'cancelled') {
            return 'cancelled';
        }
        return $event_status === 'postponed' ? 'postponed' : strtolower($post['post_status']);
    }


    private function datetime(string $id, array $meta, ?string $zone): SourceDatetimeRecord
    {
        $all_day = in_array(strtolower(trim((string) ($meta[ TheEventsCalendarSchema::META_ALL_DAY ] ?? ''))), ['yes', '1'], true);
        $start   = trim((string) ($meta[ TheEventsCalendarSchema::META_START ] ?? ''));
        $end     = trim((string) ($meta[ TheEventsCalendarSchema::META_END ] ?? ''));
        if ($zone !== null && $start !== '') {
            return new SourceDatetimeRecord(
                $id,
                new SourceDateTime($start, TimezoneModel::PER_EVENT, $zone, $all_day),
                new SourceDateTime($end !== '' ? $end : $start, TimezoneModel::PER_EVENT, $zone, $all_day)
            );
        }
        // No readable zone: the UTC meta is exact; without it the local value is read in the site timezone.
        $start_utc = trim((string) ($meta[ TheEventsCalendarSchema::META_START_UTC ] ?? ''));
        $end_utc   = trim((string) ($meta[ TheEventsCalendarSchema::META_END_UTC ] ?? ''));
        $model     = $start_utc !== '' ? TimezoneModel::UTC : TimezoneModel::SITE_LOCAL;
        $from      = $start_utc !== '' ? $start_utc : $start;
        $to        = $start_utc !== '' ? ($end_utc !== '' ? $end_utc : $start_utc) : ($end !== '' ? $end : $start);
        return new SourceDatetimeRecord(
            $id,
            new SourceDateTime($from, $model, null, $all_day),
            new SourceDateTime($to, $model, null, $all_day)
        );
    }


    /**
     * The first non-empty cost text; ticketing plugins can push several rows, but one implicit ticket stands for
     * the cost. Real tickets come from the Event Tickets adapter.
     *
     * @param array $rows The `_EventCost` values in row order.
     */
    private function cost(array $rows): string
    {
        foreach ($rows as $row) {
            if (is_scalar($row) && trim((string) $row) !== '') {
                return trim((string) $row);
            }
        }
        return '';
    }


    /**
     * The linked venue, or null when there is none or it no longer exists.
     *
     * @throws MigrationRecordException
     */
    private function venue(int $venue_id): ?SourceVenueRecord
    {
        $post = $venue_id > 0 ? ($this->db->posts([$venue_id])[ $venue_id ] ?? null) : null;
        if ($post === null || $post['post_type'] !== TheEventsCalendarSchema::POST_TYPE_VENUE || $post['post_status'] === 'trash') {
            return null;
        }
        $meta = $this->db->postMeta([$venue_id], TheEventsCalendarSchema::venueMetaKeys())[ $venue_id ] ?? [];
        $text = static fn(string $key): string => trim((string) ($meta[ $key ] ?? ''));
        $lat  = $text('_VenueLat');
        $lng  = $text('_VenueLng');
        return new SourceVenueRecord(
            (string) $venue_id,
            $post['post_title'],
            [
                'description' => $post['post_content'],
                'address'     => $text('_VenueAddress'),
                'city'        => $text('_VenueCity'),
                'state'       => $text('_VenueStateProvince') ?: ($text('_VenueState') ?: $text('_VenueProvince')),
                'zip'         => $text('_VenueZip'),
                'country'     => $text('_VenueCountry'),
                'phone'       => $text('_VenuePhone'),
                'url'         => $text('_VenueURL'),
                'latitude'    => is_numeric($lat) ? (float) $lat : null,
                'longitude'   => is_numeric($lng) ? (float) $lng : null,
            ]
        );
    }


    /**
     * @param int   $post_id       The event post.
     * @param array $meta          Its post meta.
     * @param array $organizer_ids The `_EventOrganizerID` values in row order.
     * @return UnmappedConcept[]
     * @throws MigrationRecordException
     */
    private function unmapped(int $post_id, array $meta, array $organizer_ids): array
    {
        $concepts = [];
        $tags     = $this->db->postTerms([$post_id], TheEventsCalendarSchema::TAXONOMY_TAG)[ $post_id ] ?? [];
        if ($tags !== []) {
            $concepts[] = new UnmappedConcept('tags', __('Tags', 'migrate-events-to-event-espresso'), $tags);
        }
        $organizers = $this->organizers($organizer_ids);
        if ($organizers !== []) {
            $concepts[] = new UnmappedConcept('organizer', __('Organizers', 'migrate-events-to-event-espresso'), $organizers);
        }
        foreach (
            [
                [TheEventsCalendarSchema::META_URL, 'event-url', __('Event website', 'migrate-events-to-event-espresso')],
                [TheEventsCalendarSchema::META_PHONE, 'event-phone', __('Event phone', 'migrate-events-to-event-espresso')],
                [TheEventsCalendarSchema::META_STATUS_REASON, 'status-reason', __('Status reason', 'migrate-events-to-event-espresso')],
            ] as [$key, $concept, $label]
        ) {
            $value = trim((string) ($meta[ $key ] ?? ''));
            if ($value !== '') {
                $concepts[] = new UnmappedConcept($concept, $label, [$value]);
            }
        }
        return $concepts;
    }


    /**
     * One text line per organizer post: name, email, phone and website.
     *
     * @param array $organizer_ids The organizer post ids.
     * @return string[]
     * @throws MigrationRecordException
     */
    private function organizers(array $organizer_ids): array
    {
        $ids   = array_values(array_unique(array_filter(array_map('intval', $organizer_ids))));
        $posts = $this->db->posts($ids);
        $meta  = $this->db->postMeta($ids, TheEventsCalendarSchema::organizerMetaKeys());
        $lines = [];
        foreach ($ids as $id) {
            if (! isset($posts[ $id ]) || $posts[ $id ]['post_status'] === 'trash') {
                continue;
            }
            $parts = [$posts[ $id ]['post_title']];
            foreach (TheEventsCalendarSchema::organizerMetaKeys() as $key) {
                $value = trim((string) ($meta[ $id ][ $key ] ?? ''));
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
            $lines[] = implode(' · ', $parts);
        }
        return $lines;
    }
}
// End of file TheEventsCalendarEventReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar/TheEventsCalendarEventReader.php
