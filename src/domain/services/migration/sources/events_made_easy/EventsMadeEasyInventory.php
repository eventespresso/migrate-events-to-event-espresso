<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventsMadeEasyInventory
 *
 * The pre-flight counts for the chosen options (REQ-003) over the same
 * occurrences EventsMadeEasyScope selects. EME has no ticket table, so the
 * tickets are the price slots of the events that take bookings (a recurrence
 * counts once); categories are the distinct ids in the events' category lists.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventsMadeEasyInventory
{
    private SourceDatabase $db;

    private EventsMadeEasyScope $scope;


    public function __construct(SourceDatabase $db, EventsMadeEasyScope $scope)
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
        $bookings      = $this->db->table(EventsMadeEasySchema::BOOKINGS);
        $rows          = $this->db->rows(
            'SELECT ' . EventsMadeEasyScope::eventIdExpression($options) . ' AS migrated_id, e.event_rsvp, e.price, e.event_category_ids'
            . $from . ' ORDER BY e.event_start, e.event_id',
            $args
        );
        $groups     = [];
        $categories = [];
        foreach ($rows as $row) {
            $groups[ $row['migrated_id'] ] = $groups[ $row['migrated_id'] ]
                ?? ((int) $row['event_rsvp'] === 1 ? max(1, count(EventsMadeEasyProps::slots($row['price']))) : 0);
            foreach (explode(',', (string) $row['event_category_ids']) as $id) {
                if ((int) $id > 0) {
                    $categories[ (int) $id ] = true;
                }
            }
        }
        $counts = [
            SourceInventory::COUNT_EVENTS      => count($groups),
            SourceInventory::COUNT_OCCURRENCES => count($rows),
            SourceInventory::COUNT_VENUES      => $this->db->countRows('SELECT COUNT(DISTINCT e.location_id)' . $from . ' AND e.location_id > 0', $args),
            SourceInventory::COUNT_CATEGORIES  => count($categories),
            SourceInventory::COUNT_TICKETS     => array_sum($groups),
            SourceInventory::COUNT_BOOKINGS    => $this->db->countRows(
                'SELECT COUNT(*) FROM ' . $bookings . ' WHERE event_id IN (' . $occurrences . ')',
                $args
            ),
            SourceInventory::COUNT_ATTENDEES   => $this->db->countRows(
                'SELECT COUNT(DISTINCT person_id) FROM ' . $bookings . ' WHERE event_id IN (' . $occurrences . ')',
                $args
            ),
            SourceInventory::COUNT_PAYMENTS    => $this->db->countRows(
                'SELECT COUNT(*) FROM ' . $bookings . " WHERE (booking_paid = 1 OR COALESCE(NULLIF(received, ''), '0') + 0 > 0)"
                . ' AND event_id IN (' . $occurrences . ')',
                $args
            ),
        ];
        return new SourceInventory(
            $counts,
            [],
            [
                __('Discounts and extra charges', 'migrate-events-to-event-espresso'),
                __('Per-seat answers', 'migrate-events-to-event-espresso'),
                __('Members, tasks and mailings', 'migrate-events-to-event-espresso'),
                __('Event contact persons', 'migrate-events-to-event-espresso'),
            ],
            $version,
            TimezoneModel::SITE_LOCAL
        );
    }
}
// End of file EventsMadeEasyInventory.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy/EventsMadeEasyInventory.php
