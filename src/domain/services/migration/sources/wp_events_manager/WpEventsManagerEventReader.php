<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\wp_events_manager;

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

/**
 * Class WpEventsManagerEventReader
 *
 * Reads one WP Events Manager event into a SourceEventRecord (research profile
 * §8): the post's title, content, excerpt, author and status; one datetime
 * from the local start and end date and time meta (no zone, no all-day flag);
 * the event's address text as a text-only venue; the category terms; and the
 * implicit ticket its price, seat count and registration deadline describe
 * (Phase 2 implicit-ticket contract). Tags, types, the map iframe and a parent
 * event are unmapped concepts.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class WpEventsManagerEventReader
{
    private SourceDatabase $db;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * @throws MigrationRecordException When the event does not exist, has no readable date, or a read fails.
     */
    public function read(string $source_event_id): SourceEventRecord
    {
        $id     = (int) $source_event_id;
        $posts  = $this->db->posts([$id]);
        $fields = $this->db->postMeta([$id], WpEventsManagerSchema::eventMetaKeys())[ $id ] ?? [];
        $start  = $this->moment((string) ($fields['tp_event_date_start'] ?? ''), (string) ($fields['tp_event_time_start'] ?? ''));
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        if (! isset($posts[ $id ]) || $posts[ $id ]['post_type'] !== WpEventsManagerSchema::POST_TYPE_EVENT) {
            throw $this->failure(esc_html__('The source event no longer exists.', 'migrate-events-to-event-espresso'), $source_event_id);
        }
        if ($start === null) {
            throw $this->failure(esc_html__('The source event has no readable date.', 'migrate-events-to-event-espresso'), $source_event_id);
        }
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        $post = $posts[ $id ];
        $end  = $this->moment(
            (string) ($fields['tp_event_date_end'] ?? '') ?: (string) $fields['tp_event_date_start'],
            (string) ($fields['tp_event_time_end'] ?? ''),
            '23:59:59'
        ) ?? $start;
        return new SourceEventRecord(
            $source_event_id,
            (string) $post['post_title'],
            [
                'description'       => (string) $post['post_content'],
                'excerpt'           => (string) $post['post_excerpt'],
                'status'            => strtolower((string) $post['post_status']),
                'author_wp_user_id' => (int) $post['post_author'],
                'featured_image_id' => (int) ($fields['_thumbnail_id'] ?? 0),
                'categories'        => $this->terms($id, WpEventsManagerSchema::TAXONOMY_CATEGORY),
                'venue'             => $this->venue($fields),
                'datetimes'         => [new SourceDatetimeRecord($source_event_id, $start, $end)],
                'tickets'           => [$this->ticket($source_event_id, $fields)],
                'unmapped'          => $this->unmapped($id, $post, $fields),
                'created_at'        => (string) $post['post_date'] !== ''
                    ? new SourceDateTime((string) $post['post_date'], TimezoneModel::SITE_LOCAL)
                    : null,
                'slug'              => (string) $post['post_name'],
            ]
        );
    }


    /**
     * A site-local date (`Y-m-d`) and time (`H:i`, the default time when blank: midnight for a start, the end of
     * the day for an end, so an end with no time does not fall before its start); null when the date is unreadable.
     */
    private function moment(string $date, string $time, string $default_time = '00:00:00'): ?SourceDateTime
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date) !== 1) {
            return null;
        }
        $time = preg_match('/^\d{1,2}:\d{2}/', trim($time)) === 1 ? substr(trim($time) . ':00', 0, 8) : $default_time;
        return new SourceDateTime(substr($date, 0, 10) . ' ' . $time, TimezoneModel::SITE_LOCAL);
    }


    /**
     * The one ticket: the price per seat, the seat count (0 is unlimited) and the end of registration.
     */
    private function ticket(string $source_event_id, array $fields): SourceTicketRecord
    {
        $price = trim((string) ($fields['tp_event_price'] ?? ''));
        return new SourceTicketRecord(
            'event:' . $source_event_id,
            '',
            [
                'implicit'            => true,
                'raw_price'           => $price !== '' ? $price : '0',
                'raw_quantity'        => (string) ($fields['tp_event_qty'] ?? ''),
                'sale_end'            => $this->moment(
                    (string) ($fields['tp_event_registration_end_date'] ?? ''),
                    (string) ($fields['tp_event_registration_end_time'] ?? '')
                ),
                'datetime_source_ids' => [$source_event_id],
            ]
        );
    }


    /**
     * The event's address text as a text-only venue; null when blank.
     */
    private function venue(array $fields): ?SourceVenueRecord
    {
        $address = trim((string) ($fields['tp_event_location'] ?? ''));
        return $address !== ''
            ? new SourceVenueRecord('loc:' . md5(strtolower($address)), $address, ['address' => $address, 'text_only' => true])
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
     * @param int   $post_id The event post id.
     * @param array $post    The event post row.
     * @param array $fields  Its meta.
     * @return UnmappedConcept[]
     * @throws MigrationRecordException
     */
    private function unmapped(int $post_id, array $post, array $fields): array
    {
        $concepts = [];
        foreach (
            [
                'tags'        => [WpEventsManagerSchema::TAXONOMY_TAG, __('Tags', 'migrate-events-to-event-espresso')],
                'event-types' => [WpEventsManagerSchema::TAXONOMY_TYPE, __('Event types', 'migrate-events-to-event-espresso')],
            ] as $concept => [$taxonomy, $label]
        ) {
            $names = $this->terms($post_id, $taxonomy);
            if ($names !== []) {
                $concepts[] = new UnmappedConcept($concept, $label, $names);
            }
        }
        if (trim((string) ($fields['tp_event_iframe'] ?? '')) !== '') {
            $concepts[] = new UnmappedConcept('map', __('Map embed', 'migrate-events-to-event-espresso'), [trim(wp_strip_all_tags((string) $fields['tp_event_iframe'])) ?: 'iframe']);
        }
        if ((int) $post['post_parent'] > 0) {
            $concepts[] = new UnmappedConcept('parent-event', __('Parent event', 'migrate-events-to-event-espresso'), [(string) $post['post_parent']]);
        }
        return $concepts;
    }


    private function failure(string $message, string $source_event_id): MigrationRecordException
    {
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        return new MigrationRecordException($message, WarningRule::RECORD_FAILED, CrosswalkKeys::TYPE_EVENT, $source_event_id);
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }
}
// End of file WpEventsManagerEventReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\wp_events_manager/WpEventsManagerEventReader.php
