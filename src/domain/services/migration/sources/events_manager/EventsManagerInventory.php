<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_manager;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventsManagerInventory
 *
 * The pre-flight counts for the chosen options (REQ-003): COUNT queries over
 * the same occurrences EventsManagerScope selects, never row loads. Payments
 * are "not available" unless Events Manager Pro's em_transactions exists.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventsManagerInventory
{
    private SourceDatabase $db;

    private EventsManagerScope $scope;


    public function __construct(SourceDatabase $db, EventsManagerScope $scope)
    {
        $this->db    = $db;
        $this->scope = $scope;
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function build(MigrationOptions $options, string $version): SourceInventory
    {
        [$from, $args] = $this->scope->occurrenceFrom($options);
        $occurrences   = 'SELECT e.event_id' . $from;
        $bookings      = $this->db->table(EventsManagerSchema::BOOKINGS);
        $counts        = [
            SourceInventory::COUNT_EVENTS      => $this->db->countRows(
                'SELECT COUNT(DISTINCT ' . EventsManagerScope::eventIdExpression($options) . ')' . $from,
                $args
            ),
            SourceInventory::COUNT_OCCURRENCES => $this->db->countRows('SELECT COUNT(*)' . $from, $args),
            SourceInventory::COUNT_VENUES      => $this->db->countRows(
                'SELECT COUNT(DISTINCT e.location_id)' . $from . ' AND e.location_id > 0',
                $args
            ),
            SourceInventory::COUNT_CATEGORIES  => $this->db->countRows(
                'SELECT COUNT(DISTINCT tt.term_id) FROM ' . $this->db->table('term_relationships') . ' tr'
                . ' JOIN ' . $this->db->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
                . ' WHERE tt.taxonomy = %s AND tr.object_id IN (SELECT p.ID' . $from . ')',
                array_merge([EventsManagerSchema::TAXONOMY_CATEGORY], $args)
            ),
            SourceInventory::COUNT_TICKETS     => $this->db->countRows(
                'SELECT COUNT(*) FROM ' . $this->db->table(EventsManagerSchema::TICKETS)
                . ' WHERE event_id IN (' . $occurrences . ')',
                $args
            ),
            SourceInventory::COUNT_BOOKINGS    => $this->db->countRows(
                'SELECT COUNT(*) FROM ' . $bookings . ' WHERE event_id IN (' . $occurrences . ')',
                $args
            ),
            SourceInventory::COUNT_ATTENDEES   => $this->db->countRows(
                "SELECT COUNT(DISTINCT CASE WHEN b.person_id > 0 THEN CONCAT('u', b.person_id)"
                . " ELSE CONCAT('g', LOWER(COALESCE(m.meta_value, b.booking_id))) END)"
                . ' FROM ' . $bookings . ' b'
                . ' LEFT JOIN ' . $this->db->table(EventsManagerSchema::BOOKINGS_META) . ' m'
                . ' ON m.booking_id = b.booking_id AND m.meta_key = %s'
                . ' WHERE b.event_id IN (' . $occurrences . ')',
                array_merge([EventsManagerSchema::GUEST_META_PREFIX . 'user_email'], $args)
            ),
        ];
        $not_available = [];
        if ($this->db->tableExists(EventsManagerSchema::TRANSACTIONS)) {
            $counts[ SourceInventory::COUNT_PAYMENTS ] = $this->db->countRows(
                'SELECT COUNT(*) FROM ' . $this->db->table(EventsManagerSchema::TRANSACTIONS)
                . ' WHERE booking_id IN (SELECT booking_id FROM ' . $bookings . ' WHERE event_id IN (' . $occurrences . '))',
                $args
            );
        } else {
            $not_available[] = SourceInventory::COUNT_PAYMENTS;
        }
        return new SourceInventory(
            $counts,
            $not_available,
            [
                __('Event tags', 'migrate-events-to-event-espresso'),
                __('Event attributes', 'migrate-events-to-event-espresso'),
                __('Timeslots', 'migrate-events-to-event-espresso'),
                __('Coupons and attendee forms (Events Manager Pro)', 'migrate-events-to-event-espresso'),
            ],
            $version,
            TimezoneModel::PER_EVENT
        );
    }
}
// End of file EventsManagerInventory.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_manager/EventsManagerInventory.php
