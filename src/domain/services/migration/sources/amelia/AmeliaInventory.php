<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\amelia;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class AmeliaInventory
 *
 * The pre-flight counts for the chosen options (REQ-003): COUNT queries over
 * the same occurrences AmeliaScope selects, never row loads. Venues are the
 * distinct locations plus the distinct free-text locations; categories are
 * the distinct event tags.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class AmeliaInventory
{
    private SourceDatabase $db;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function build(MigrationOptions $options, string $version, AmeliaScope $scope): SourceInventory
    {
        [$from, $args] = $scope->occurrenceFrom($options);
        $occurrences   = 'SELECT e.id' . $from;
        $linked        = 'SELECT bp.customerBookingId FROM ' . $this->db->table(AmeliaSchema::BOOKING_PERIODS) . ' bp'
            . ' JOIN ' . $this->db->table(AmeliaSchema::PERIODS) . ' ep ON ep.id = bp.eventPeriodId'
            . ' WHERE ep.eventId IN (' . $occurrences . ')';
        $bookings      = $this->db->table(AmeliaSchema::BOOKINGS);
        $counts        = [
            SourceInventory::COUNT_EVENTS      => $this->db->countRows(
                'SELECT COUNT(DISTINCT ' . AmeliaScope::eventIdExpression($options) . ')' . $from,
                $args
            ),
            SourceInventory::COUNT_OCCURRENCES => $this->db->countRows('SELECT COUNT(*)' . $from, $args),
            SourceInventory::COUNT_VENUES      => $this->db->countRows(
                "SELECT COUNT(DISTINCT CASE WHEN e.locationId > 0 THEN CONCAT('l', e.locationId)"
                . " WHEN TRIM(COALESCE(e.customLocation, '')) <> '' THEN CONCAT('c', LOWER(TRIM(e.customLocation))) END)"
                . $from,
                $args
            ),
            SourceInventory::COUNT_CATEGORIES  => $this->db->tableExists(AmeliaSchema::TAGS)
                ? $this->db->countRows(
                    'SELECT COUNT(DISTINCT LOWER(TRIM(name))) FROM ' . $this->db->table(AmeliaSchema::TAGS)
                    . " WHERE TRIM(name) <> '' AND eventId IN (" . $occurrences . ')',
                    $args
                )
                : 0,
            SourceInventory::COUNT_TICKETS     => $this->db->countRows(
                'SELECT COUNT(*) FROM ' . $this->db->table(AmeliaSchema::TICKETS) . ' WHERE eventId IN (' . $occurrences . ')',
                $args
            ),
            SourceInventory::COUNT_BOOKINGS    => $this->db->countRows(
                'SELECT COUNT(*) FROM ' . $bookings . ' WHERE id IN (' . $linked . ')',
                $args
            ),
            SourceInventory::COUNT_ATTENDEES   => $this->db->countRows(
                'SELECT COUNT(DISTINCT customerId) FROM ' . $bookings . ' WHERE id IN (' . $linked . ')',
                $args
            ),
            SourceInventory::COUNT_PAYMENTS    => $this->db->countRows(
                'SELECT COUNT(*) FROM ' . $this->db->table(AmeliaSchema::PAYMENTS)
                . ' WHERE customerBookingId IN (' . $linked . ')',
                $args
            ),
        ];
        return new SourceInventory(
            $counts,
            [],
            [
                __('Appointments, services and packages', 'migrate-events-to-event-espresso'),
                __('Coupons and taxes', 'migrate-events-to-event-espresso'),
                __('Staff and calendar assignments', 'migrate-events-to-event-espresso'),
                __('Event galleries and e-tickets', 'migrate-events-to-event-espresso'),
            ],
            $version,
            TimezoneModel::UTC
        );
    }
}
// End of file AmeliaInventory.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\amelia/AmeliaInventory.php
