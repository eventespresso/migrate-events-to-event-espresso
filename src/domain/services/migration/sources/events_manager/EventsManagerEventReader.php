<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_manager;

use EventEspresso\EventMigrator\application\services\migration\records\SourceDatetimeRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\records\SourceEventRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceVenueRecord;
use EventEspresso\EventMigrator\application\services\migration\records\UnmappedConcept;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventsManagerEventReader
 *
 * Reads one Events Manager event into a SourceEventRecord (research profile
 * §8): post title, content, excerpt, author, status and categories from the
 * owning post; one datetime per date (the event itself, or every child of a
 * series parent); its location; its tickets (EventsManagerTicketReader); and
 * the concepts Event Espresso has no counterpart for (tags, the anonymous
 * submitter, members-only tickets, timeslots).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventsManagerEventReader
{
    private const POST_META_KEYS = [
        '_thumbnail_id',
        '_event_owner_name',
        '_event_location_url',
        '_event_location_url_text',
    ];

    private SourceDatabase $db;

    private EventsManagerScope $scope;

    private EventsManagerTicketReader $tickets;


    public function __construct(SourceDatabase $db, EventsManagerScope $scope, EventsManagerTicketReader $tickets)
    {
        $this->db      = $db;
        $this->scope   = $scope;
        $this->tickets = $tickets;
    }


    /**
     * @throws MigrationRecordException When the event does not exist or a read fails.
     */
    public function read(string $source_event_id): SourceEventRecord
    {
        $row = $this->eventRow((int) $source_event_id);
        if ($row === null) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new MigrationRecordException(
                esc_html__('The source event no longer exists.', 'migrate-events-to-event-espresso'),
                WarningRule::RECORD_FAILED,
                CrosswalkKeys::TYPE_EVENT,
                $source_event_id
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $owner       = $this->ownerRow($row);
        $occurrences = $this->occurrenceRows($row);
        $post_id     = (int) ($owner['post_id'] ?? 0);
        $meta        = $this->db->postMeta([$post_id], self::POST_META_KEYS)[ $post_id ] ?? [];
        $tickets     = $this->tickets->read($occurrences, $owner);
        $is_series   = in_array($owner['event_type'], EventsManagerSchema::seriesTypes(), true);
        return new SourceEventRecord(
            (string) $row['event_id'],
            (string) ($row['event_name'] ?: $owner['post_title']),
            [
                'description'        => (string) ($owner['post_content_live'] ?? $owner['post_content'] ?? ''),
                'excerpt'            => (string) ($owner['post_excerpt'] ?? ''),
                'status'             => $this->status($row, $owner),
                'trashed'            => $this->isTrashed($row) || $this->isTrashed($owner),
                'author_wp_user_id'  => (int) ($owner['post_author'] ?: $owner['event_owner']),
                'featured_image_id'  => (int) ($meta['_thumbnail_id'] ?? 0),
                'categories'         => $this->db->postTerms([$post_id], EventsManagerSchema::TAXONOMY_CATEGORY)[ $post_id ] ?? [],
                'venue'              => $this->venue($row, $owner, $meta),
                'datetimes'          => array_map([$this, 'datetime'], $occurrences),
                'tickets'            => $tickets['records'],
                'unmapped'           => $this->unmapped($post_id, $meta, $tickets['members_only'], $occurrences),
                'series_id'          => $is_series ? (string) $owner['event_id'] : null,
                'timezone'           => EventsManagerDates::zone($row['event_timezone'] ?? null),
                'created_at'         => ($owner['post_date'] ?? '') !== ''
                    ? new SourceDateTime((string) $owner['post_date'], TimezoneModel::SITE_LOCAL)
                    : null,
                'slug'               => (string) ($owner['post_name'] ?: $owner['event_slug']),
            ]
        );
    }


    /**
     * An em_events row with its own post's fields, or null.
     *
     * @throws MigrationRecordException
     */
    private function eventRow(int $event_id): ?array
    {
        $rows = $this->db->rows(
            'SELECT e.*, p.post_title, p.post_content AS post_content_live, p.post_excerpt, p.post_status,'
            . ' p.post_author, p.post_name, p.post_date'
            . ' FROM ' . $this->db->table(EventsManagerSchema::EVENTS) . ' e'
            . ' LEFT JOIN ' . $this->db->table('posts') . ' p ON p.ID = e.post_id'
            . ' WHERE e.event_id = %d',
            [$event_id]
        );
        return $rows[0] ?? null;
    }


    /**
     * The row whose post carries the event's title, content, author and terms: a child's series parent, or
     * the row itself.
     *
     * @throws MigrationRecordException
     */
    private function ownerRow(array $row): array
    {
        if ($row['event_type'] !== EventsManagerSchema::TYPE_RECURRENCE || (int) $row['recurrence_set_id'] === 0) {
            return $row;
        }
        $parent_id = (int) $this->db->scalar(
            'SELECT event_id FROM ' . $this->db->table(EventsManagerSchema::RECURRENCES) . ' WHERE recurrence_set_id = %d',
            [(int) $row['recurrence_set_id']]
        );
        $parent    = $parent_id > 0 ? $this->eventRow($parent_id) : null;
        return $parent ?? $row;
    }


    /**
     * @return array[] em_events rows, one per date
     * @throws MigrationRecordException
     */
    private function occurrenceRows(array $row): array
    {
        if (! in_array($row['event_type'], EventsManagerSchema::seriesTypes(), true)) {
            return [$row];
        }
        $ids = $this->scope->occurrenceIdsOf((int) $row['event_id']);
        if ($ids === []) {
            return [];
        }
        return $this->db->rows(
            'SELECT * FROM ' . $this->db->table(EventsManagerSchema::EVENTS)
            . ' WHERE event_id IN (' . SourceDatabase::placeholders($ids) . ') ORDER BY event_start, event_id',
            $ids
        );
    }


    private function datetime(array $row): SourceDatetimeRecord
    {
        $spaces = (int) ($row['event_spaces'] ?? 0);
        return new SourceDatetimeRecord(
            (string) $row['event_id'],
            EventsManagerDates::edge($row, 'start'),
            EventsManagerDates::edge($row, 'end'),
            ['capacity' => (int) $row['event_rsvp'] === 1 && $spaces > 0 ? $spaces : null]
        );
    }


    /**
     * The raw status: "cancelled" for an event Events Manager marks cancelled, otherwise the owning post's.
     */
    private function status(array $row, array $owner): string
    {
        if ($this->isTrashed($row) || $this->isTrashed($owner)) {
            return 'trash';
        }
        if (($row['event_active_status'] ?? '1') === '0' || ($owner['event_active_status'] ?? '1') === '0') {
            return EventsManagerStatusTable::EVENT_CANCELLED;
        }
        return strtolower((string) ($owner['post_status'] ?? 'draft'));
    }


    private function isTrashed(array $row): bool
    {
        return ($row['post_status'] ?? '') === 'trash' || (string) ($row['event_status'] ?? '') === '-1';
    }


    /**
     * The event's location, or its URL for an online event, or null.
     *
     * @throws MigrationRecordException
     */
    private function venue(array $row, array $owner, array $meta): ?SourceVenueRecord
    {
        $location_id = (int) ($row['location_id'] ?: $owner['location_id']);
        if ($location_id > 0) {
            $location = $this->db->rows(
                'SELECT * FROM ' . $this->db->table(EventsManagerSchema::LOCATIONS) . ' WHERE location_id = %d',
                [$location_id]
            )[0] ?? null;
            if ($location !== null) {
                return new SourceVenueRecord(
                    (string) $location_id,
                    (string) $location['location_name'],
                    [
                        'description' => (string) $location['post_content'],
                        'address'     => (string) $location['location_address'],
                        'city'        => (string) $location['location_town'],
                        'state'       => (string) $location['location_state'],
                        'zip'         => (string) $location['location_postcode'],
                        'country'     => (string) $location['location_country'],
                        'latitude'    => $location['location_latitude'] !== null ? (float) $location['location_latitude'] : null,
                        'longitude'   => $location['location_longitude'] !== null ? (float) $location['location_longitude'] : null,
                    ]
                );
            }
        }
        $url = trim((string) ($meta['_event_location_url'] ?? ''));
        if (($owner['event_location_type'] ?? '') === 'url' && $url !== '') {
            $label = trim((string) ($meta['_event_location_url_text'] ?? ''));
            return new SourceVenueRecord('url:' . md5($url), $label !== '' ? $label : $url, ['virtual_url' => $url]);
        }
        return null;
    }


    /**
     * @param int      $post_id      The owning post.
     * @param array    $meta         Its post meta.
     * @param string[] $members_only Names of tickets only logged-in users could buy.
     * @param array[]  $occurrences  The event's date rows.
     * @return UnmappedConcept[]
     * @throws MigrationRecordException
     */
    private function unmapped(int $post_id, array $meta, array $members_only, array $occurrences): array
    {
        $concepts = [];
        $tags     = $this->db->postTerms([$post_id], EventsManagerSchema::TAXONOMY_TAG)[ $post_id ] ?? [];
        if ($tags !== []) {
            $concepts[] = new UnmappedConcept('tags', __('Tags', 'migrate-events-to-event-espresso'), $tags);
        }
        $submitter = trim((string) ($meta['_event_owner_name'] ?? ''));
        if ($submitter !== '') {
            $concepts[] = new UnmappedConcept('organizer', __('Submitted by', 'migrate-events-to-event-espresso'), [$submitter]);
        }
        if ($members_only !== []) {
            $concepts[] = new UnmappedConcept('members-only', __('Members-only tickets', 'migrate-events-to-event-espresso'), $members_only);
        }
        if ($this->db->tableExists(EventsManagerSchema::TIMESLOTS) && $occurrences !== []) {
            $ids   = array_map('intval', array_column($occurrences, 'event_id'));
            $slots = $this->db->countRows(
                'SELECT COUNT(*) FROM ' . $this->db->table(EventsManagerSchema::TIMESLOTS)
                . ' WHERE event_id IN (' . SourceDatabase::placeholders($ids) . ')',
                $ids
            );
            if ($slots > 0) {
                $concepts[] = new UnmappedConcept('timeslots', __('Timeslots', 'migrate-events-to-event-espresso'), [(string) $slots]);
            }
        }
        return $concepts;
    }
}
// End of file EventsManagerEventReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_manager/EventsManagerEventReader.php
