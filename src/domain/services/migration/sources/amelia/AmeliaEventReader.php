<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\amelia;

use EventEspresso\EventMigrator\application\services\migration\records\SourceDatetimeRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceEventRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceVenueRecord;
use EventEspresso\EventMigrator\application\services\migration\records\UnmappedConcept;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class AmeliaEventReader
 *
 * Reads one Amelia event into a SourceEventRecord (research profile §8): the
 * event row's name, description and status; one datetime per period of the
 * event (or of every occurrence of a recurring series, in UTC); its location;
 * its tags as categories; its tickets (AmeliaTicketReader); and the concepts
 * Event Espresso has no counterpart for (organizer, deposits, wait list).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class AmeliaEventReader
{
    private SourceDatabase $db;

    private AmeliaScope $scope;

    private AmeliaTicketReader $tickets;


    public function __construct(SourceDatabase $db, AmeliaScope $scope, AmeliaTicketReader $tickets)
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
        $ids    = $this->scope->occurrenceIdsOf($source_event_id);
        $events = $this->eventRows($ids);
        if ($events === []) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new MigrationRecordException(
                esc_html__('The source event no longer exists.', 'migrate-events-to-event-espresso'),
                WarningRule::RECORD_FAILED,
                CrosswalkKeys::TYPE_EVENT,
                $source_event_id
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $owner     = $events[0];
        $periods   = $this->periodRows($ids);
        $tickets   = $this->tickets->read($events, $periods);
        $organizer = $this->organizer((int) $owner['organizerId']);
        $is_series = $owner['parentId'] !== null;
        return new SourceEventRecord(
            $source_event_id,
            (string) $owner['name'],
            [
                'description'        => (string) $owner['description'],
                'status'             => $this->status($owner),
                'author_wp_user_id'  => (int) ($organizer['externalId'] ?? 0),
                'featured_image_url' => trim((string) $owner['pictureFullPath']),
                'categories'         => $this->tags($ids),
                'venue'              => $this->venue($events),
                'datetimes'          => $this->datetimes($events, $periods),
                'tickets'            => $tickets['records'],
                'unmapped'           => array_merge($tickets['unmapped'], $this->unmapped($owner, $organizer)),
                'series_id'          => $is_series ? (string) $owner['parentId'] : null,
                'created_at'         => AmeliaDates::utc($owner['created']),
            ]
        );
    }


    /**
     * @param int[] $ids
     * @return array[] amelia_events rows in id order
     * @throws MigrationRecordException
     */
    private function eventRows(array $ids): array
    {
        return $this->db->rows(
            'SELECT * FROM ' . $this->db->table(AmeliaSchema::EVENTS)
            . ' WHERE id IN (' . SourceDatabase::placeholders($ids) . ') ORDER BY id',
            $ids
        );
    }


    /**
     * @param int[] $ids
     * @return array[] amelia_events_periods rows, earliest first
     * @throws MigrationRecordException
     */
    private function periodRows(array $ids): array
    {
        return $this->db->rows(
            'SELECT * FROM ' . $this->db->table(AmeliaSchema::PERIODS)
            . ' WHERE eventId IN (' . SourceDatabase::placeholders($ids) . ') ORDER BY periodStart, id',
            $ids
        );
    }


    /**
     * One datetime per period; its capacity is its event's (custom tickets have their own total).
     *
     * @param array[] $events  The amelia_events rows.
     * @param array[] $periods Their amelia_events_periods rows.
     * @return SourceDatetimeRecord[]
     */
    private function datetimes(array $events, array $periods): array
    {
        $by_id = [];
        foreach ($events as $event) {
            $by_id[ (int) $event['id'] ] = $event;
        }
        $records = [];
        foreach ($periods as $period) {
            $event     = $by_id[ (int) $period['eventId'] ];
            $capacity  = (int) ($event['customPricing'] ? $event['maxCustomCapacity'] : $event['maxCapacity']);
            $records[] = new SourceDatetimeRecord(
                (string) $period['id'],
                AmeliaDates::utc($period['periodStart']),
                AmeliaDates::utc($period['periodEnd']),
                ['capacity' => $capacity > 0 ? $capacity : null]
            );
        }
        return $records;
    }


    /**
     * The raw status: "hidden" for a live event that is not shown on the front end, otherwise Amelia's own.
     */
    private function status(array $event): string
    {
        if ($event['status'] === 'approved' && (int) $event['show'] === 0) {
            return AmeliaSchema::STATUS_HIDDEN;
        }
        return (string) $event['status'];
    }


    /**
     * The tag names of the events, each once (case-insensitively), in first-seen order.
     *
     * @param int[] $ids
     * @return string[]
     * @throws MigrationRecordException
     */
    private function tags(array $ids): array
    {
        if (! $this->db->tableExists(AmeliaSchema::TAGS)) {
            return [];
        }
        $names = [];
        $rows  = $this->db->rows(
            'SELECT name FROM ' . $this->db->table(AmeliaSchema::TAGS)
            . ' WHERE eventId IN (' . SourceDatabase::placeholders($ids) . ') ORDER BY id',
            $ids
        );
        foreach ($rows as $row) {
            $name = trim((string) $row['name']);
            if ($name !== '') {
                $names[ strtolower($name) ] = $names[ strtolower($name) ] ?? $name;
            }
        }
        return array_values($names);
    }


    /**
     * The first event's location: a location row, or its free-text location.
     *
     * @param array[] $events
     * @throws MigrationRecordException
     */
    private function venue(array $events): ?SourceVenueRecord
    {
        foreach ($events as $event) {
            $location_id = (int) $event['locationId'];
            if ($location_id > 0 && $this->db->tableExists(AmeliaSchema::LOCATIONS)) {
                $location = $this->db->rows(
                    'SELECT * FROM ' . $this->db->table(AmeliaSchema::LOCATIONS) . ' WHERE id = %d',
                    [$location_id]
                )[0] ?? null;
                if ($location !== null) {
                    return new SourceVenueRecord(
                        (string) $location_id,
                        (string) $location['name'],
                        [
                            'description' => (string) $location['description'],
                            'address'     => (string) $location['address'],
                            'phone'       => (string) $location['phone'],
                            'latitude'    => (float) $location['latitude'] !== 0.0 ? (float) $location['latitude'] : null,
                            'longitude'   => (float) $location['longitude'] !== 0.0 ? (float) $location['longitude'] : null,
                        ]
                    );
                }
            }
            $text = trim((string) $event['customLocation']);
            if ($text !== '') {
                return new SourceVenueRecord('custom:' . md5(strtolower($text)), $text, ['text_only' => true]);
            }
        }
        return null;
    }


    /**
     * @return array|null The amelia_users row of the event's organizer.
     * @throws MigrationRecordException
     */
    private function organizer(int $user_id): ?array
    {
        if ($user_id <= 0) {
            return null;
        }
        return $this->db->rows('SELECT * FROM ' . $this->db->table(AmeliaSchema::USERS) . ' WHERE id = %d', [$user_id])[0] ?? null;
    }


    /**
     * @param array      $owner     The event's amelia_events row.
     * @param array|null $organizer The organizer's amelia_users row.
     * @return UnmappedConcept[]
     */
    private function unmapped(array $owner, ?array $organizer): array
    {
        $concepts = [];
        if ($organizer !== null) {
            $name = trim($organizer['firstName'] . ' ' . $organizer['lastName']);
            if ($name !== '') {
                $concepts[] = new UnmappedConcept('organizer', __('Organizer', 'migrate-events-to-event-espresso'), [$name]);
            }
        }
        if ($owner['depositPayment'] !== 'disabled') {
            $concepts[] = new UnmappedConcept(
                'deposit',
                __('Deposit payment', 'migrate-events-to-event-espresso'),
                [$owner['depositPayment'] . ': ' . (float) $owner['deposit']]
            );
        }
        $settings = json_decode((string) $owner['settings'], true);
        if (! empty($settings['waitingList']['enabled'])) {
            $concepts[] = new UnmappedConcept(
                'waiting-list',
                __('Wait list', 'migrate-events-to-event-espresso'),
                [(string) ($settings['waitingList']['maxCapacity'] ?? '')]
            );
        }
        return $concepts;
    }
}
// End of file AmeliaEventReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\amelia/AmeliaEventReader.php
