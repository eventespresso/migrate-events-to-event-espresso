<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class TheEventsCalendarInventory
 *
 * The pre-flight counts for the chosen options (REQ-003): COUNT queries over
 * the events TheEventsCalendarScope selects. The Events Calendar has no
 * tickets, bookings, contacts or payments of its own (Event Tickets does),
 * so those rows are "not available".
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class TheEventsCalendarInventory
{
    private SourceDatabase $db;

    private TheEventsCalendarScope $scope;


    public function __construct(SourceDatabase $db, TheEventsCalendarScope $scope)
    {
        $this->db    = $db;
        $this->scope = $scope;
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function build(MigrationOptions $options, string $version): SourceInventory
    {
        [$from, $args] = $this->scope->eventFrom($options);
        $events        = $this->db->countRows('SELECT COUNT(*)' . $from, $args);
        $in_scope      = 'SELECT p.ID' . $from;
        $counts        = [
            SourceInventory::COUNT_EVENTS      => $events,
            SourceInventory::COUNT_OCCURRENCES => $events,
            SourceInventory::COUNT_VENUES      => $this->db->countRows(
                'SELECT COUNT(DISTINCT v.meta_value) FROM ' . $this->db->table('postmeta') . ' v'
                . ' JOIN ' . $this->db->table('posts') . ' vp ON vp.ID = v.meta_value AND vp.post_type = %s'
                . " AND vp.post_status <> 'trash'"
                . ' WHERE v.meta_key = %s AND v.post_id IN (' . $in_scope . ')',
                array_merge([TheEventsCalendarSchema::POST_TYPE_VENUE, TheEventsCalendarSchema::META_VENUE], $args)
            ),
            SourceInventory::COUNT_CATEGORIES  => $this->db->countRows(
                'SELECT COUNT(DISTINCT tt.term_id) FROM ' . $this->db->table('term_relationships') . ' tr'
                . ' JOIN ' . $this->db->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
                . ' WHERE tt.taxonomy = %s AND tr.object_id IN (' . $in_scope . ')',
                array_merge([TheEventsCalendarSchema::TAXONOMY_CATEGORY], $args)
            ),
        ];
        return new SourceInventory(
            $counts,
            [
                SourceInventory::COUNT_TICKETS,
                SourceInventory::COUNT_ATTENDEES,
                SourceInventory::COUNT_BOOKINGS,
                SourceInventory::COUNT_PAYMENTS,
            ],
            [
                __('Organizers (kept as text in the description)', 'migrate-events-to-event-espresso'),
                __('Event tags', 'migrate-events-to-event-espresso'),
                __('Recurring events (Events Calendar Pro)', 'migrate-events-to-event-espresso'),
                __('Featured and hidden-from-upcoming flags', 'migrate-events-to-event-espresso'),
            ],
            $version,
            TimezoneModel::PER_EVENT
        );
    }
}
// End of file TheEventsCalendarInventory.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar/TheEventsCalendarInventory.php
