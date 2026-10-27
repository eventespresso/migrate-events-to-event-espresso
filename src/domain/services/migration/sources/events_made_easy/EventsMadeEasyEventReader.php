<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy;

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
 * Class EventsMadeEasyEventReader
 *
 * Reads one EME event into a SourceEventRecord (research profile §8): the
 * name, notes, author, status and categories of the event row; one datetime per
 * row (the event, or every date of a recurrence), in the site's local time;
 * its location; and, for an event that takes bookings, the implicit ticket
 * its price and seats describe (single or `||` multi-price, Phase 2
 * implicit-ticket contract). A single seat count shared by several prices is a
 * pool and goes on the dates.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventsMadeEasyEventReader
{
    private SourceDatabase $db;

    private EventsMadeEasyScope $scope;


    public function __construct(SourceDatabase $db, EventsMadeEasyScope $scope)
    {
        $this->db    = $db;
        $this->scope = $scope;
    }


    /**
     * @throws MigrationRecordException When the event does not exist or a read fails.
     */
    public function read(string $source_event_id): SourceEventRecord
    {
        $ids  = $this->scope->occurrenceIdsOf($source_event_id);
        $rows = $ids === [] ? [] : $this->db->rows(
            'SELECT * FROM ' . $this->db->table(EventsMadeEasySchema::EVENTS)
            . ' WHERE event_id IN (' . SourceDatabase::placeholders($ids) . ') ORDER BY event_start, event_id',
            $ids
        );
        if ($rows === []) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new MigrationRecordException(
                esc_html__('The source event no longer exists.', 'migrate-events-to-event-espresso'),
                WarningRule::RECORD_FAILED,
                CrosswalkKeys::TYPE_EVENT,
                $source_event_id
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $owner = $rows[0];
        $props = EventsMadeEasyProps::decode($owner['event_properties']);
        return new SourceEventRecord(
            $source_event_id,
            (string) $owner['event_name'],
            [
                'description'       => (string) $owner['event_notes'],
                'status'            => (string) (int) $owner['event_status'],
                'author_wp_user_id' => (int) $owner['event_author'],
                'featured_image_id' => (int) $owner['event_image_id'],
                'categories'        => $this->categories($rows),
                'venue'             => $this->venue((int) $owner['location_id']),
                'datetimes'         => $this->datetimes($rows, $props),
                'tickets'           => $this->tickets($source_event_id, $rows, $owner, $props),
                'unmapped'          => $this->unmapped($owner),
                'series_id'         => EventsMadeEasyScope::isSeries($source_event_id) ? (string) $owner['recurrence_id'] : null,
                'created_at'        => $this->local((string) $owner['creation_date']),
                'slug'              => (string) $owner['event_slug'],
            ]
        );
    }


    /**
     * One datetime per event row; a single seat count shared by several prices is the pool of every date.
     *
     * @param array[] $rows
     * @return SourceDatetimeRecord[]
     */
    private function datetimes(array $rows, array $props): array
    {
        $records = [];
        foreach ($rows as $row) {
            $props_row = $row === $rows[0] ? $props : EventsMadeEasyProps::decode($row['event_properties']);
            $all_day   = ! empty($props_row['all_day']);
            $seats     = EventsMadeEasyProps::slots($row['event_seats']);
            $pooled    = (int) $row['event_rsvp'] === 1 && count($seats) === 1 && count(EventsMadeEasyProps::slots($row['price'])) > 1;
            $start     = new SourceDateTime((string) $row['event_start'], TimezoneModel::SITE_LOCAL, null, $all_day);
            $end       = trim((string) $row['event_end']) !== '' && strpos((string) $row['event_end'], '0000-00-00') !== 0
                ? new SourceDateTime((string) $row['event_end'], TimezoneModel::SITE_LOCAL, null, $all_day)
                : $start;
            $records[] = new SourceDatetimeRecord(
                (string) $row['event_id'],
                $start,
                $end,
                ['capacity' => $pooled && (int) $seats[0] > 0 ? (int) $seats[0] : null]
            );
        }
        return $records;
    }


    /**
     * The implicit ticket of an event that takes bookings: its price(s), seats and registration window.
     *
     * @param string  $source_event_id The migrated event id.
     * @param array[] $rows            The event's eme_events rows.
     * @param array   $owner           The first row, which owns the event.
     * @param array   $props           The owner's decoded event_properties.
     * @return SourceTicketRecord[]
     */
    private function tickets(string $source_event_id, array $rows, array $owner, array $props): array
    {
        if ((int) $owner['event_rsvp'] !== 1) {
            return [];
        }
        $prices = EventsMadeEasyProps::slots($owner['price']);
        $max    = $props['max_allowed'] ?? '';
        return [
            new SourceTicketRecord(
                EventsMadeEasySchema::TICKET_PREFIX . $source_event_id,
                (string) ($props['multiprice_desc'] ?? ''),
                [
                    'implicit'            => true,
                    'price'               => isset($prices[0]) && is_numeric($prices[0]) ? (float) $prices[0] : null,
                    'raw_price'           => (string) $owner['price'],
                    'raw_quantity'        => (string) $owner['event_seats'],
                    'currency'            => strtoupper(trim((string) $owner['currency'])),
                    'min'                 => count($prices) <= 1 ? (int) ($props['min_allowed'] ?? 0) : 0,
                    'max'                 => count($prices) <= 1 && is_numeric($max) && (int) $max > 0 ? (int) $max : null,
                    'sale_start'          => EventsMadeEasyProps::window($props, $owner, 'start'),
                    'sale_end'            => EventsMadeEasyProps::window($props, $owner, 'end'),
                    'datetime_source_ids' => array_map('strval', array_column($rows, 'event_id')),
                ]
            ),
        ];
    }


    /**
     * The category names of the events (their `event_category_ids` lists), each once.
     *
     * @param array[] $rows
     * @return string[]
     * @throws MigrationRecordException
     */
    private function categories(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach (explode(',', (string) $row['event_category_ids']) as $id) {
                if ((int) $id > 0) {
                    $ids[ (int) $id ] = (int) $id;
                }
            }
        }
        if ($ids === []) {
            return [];
        }
        $names = $this->db->column(
            'SELECT category_name FROM ' . $this->db->table(EventsMadeEasySchema::CATEGORIES)
            . ' WHERE category_id IN (' . SourceDatabase::placeholders($ids) . ') ORDER BY category_id',
            array_values($ids)
        );
        return array_values(array_unique(array_filter(array_map('trim', $names))));
    }


    /**
     * @throws MigrationRecordException
     */
    private function venue(int $location_id): ?SourceVenueRecord
    {
        if ($location_id <= 0) {
            return null;
        }
        $location = $this->db->rows(
            'SELECT * FROM ' . $this->db->table(EventsMadeEasySchema::LOCATIONS) . ' WHERE location_id = %d',
            [$location_id]
        )[0] ?? null;
        if ($location === null) {
            return null;
        }
        $lat = $location['location_latitude'];
        $lng = $location['location_longitude'];
        return new SourceVenueRecord(
            (string) $location_id,
            (string) $location['location_name'],
            [
                'description' => (string) $location['location_description'],
                'address'     => (string) $location['location_address1'],
                'address2'    => (string) $location['location_address2'],
                'city'        => (string) $location['location_city'],
                'state'       => (string) $location['location_state'],
                'zip'         => (string) $location['location_zip'],
                'country'     => (string) $location['location_country'],
                'url'         => (string) $location['location_url'],
                'latitude'    => is_numeric($lat) && (float) $lat !== 0.0 ? (float) $lat : null,
                'longitude'   => is_numeric($lng) && (float) $lng !== 0.0 ? (float) $lng : null,
            ]
        );
    }


    /**
     * @return UnmappedConcept[]
     */
    private function unmapped(array $owner): array
    {
        $concepts = [];
        $url      = trim((string) $owner['event_url']);
        if ($url !== '') {
            $concepts[] = new UnmappedConcept('external-link', __('External link', 'migrate-events-to-event-espresso'), [$url]);
        }
        if ((int) $owner['registration_requires_approval'] === 1) {
            $concepts[] = new UnmappedConcept('requires-approval', __('Bookings need approval', 'migrate-events-to-event-espresso'), ['1']);
        }
        return $concepts;
    }


    private function local(string $value): ?SourceDateTime
    {
        $value = trim($value);
        return $value !== '' && strpos($value, '0000-00-00') !== 0 ? new SourceDateTime($value, TimezoneModel::SITE_LOCAL) : null;
    }
}
// End of file EventsMadeEasyEventReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy/EventsMadeEasyEventReader.php
