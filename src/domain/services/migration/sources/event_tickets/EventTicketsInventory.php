<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\event_tickets;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;
use EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar\TheEventsCalendarInventory;
use EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar\TheEventsCalendarSchema;

/**
 * Class EventTicketsInventory
 *
 * The pre-flight counts for the chosen options (REQ-003): COUNT queries over
 * the events EventTicketsScope selects. Events, venues and categories are
 * counted as for The Events Calendar; tickets, bookings (orders), contacts
 * and payments from the Tickets Commerce posts. Tickets on other post types
 * and RSVPs answered "not going" are not counted.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventTicketsInventory
{
    private SourceDatabase $db;

    private EventTicketsScope $scope;


    public function __construct(SourceDatabase $db, EventTicketsScope $scope)
    {
        $this->db    = $db;
        $this->scope = $scope;
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function build(MigrationOptions $options, string $version): SourceInventory
    {
        $events        = (new TheEventsCalendarInventory($this->db, $this->scope))->build($options, $version)->counts();
        [$from, $args] = $this->scope->eventFrom($options);
        $in_scope      = 'SELECT p.ID' . $from;
        $posts         = $this->db->table('posts');
        $meta          = $this->db->table('postmeta');
        $joins         = ' FROM ' . $posts . ' a'
            . ' JOIN ' . $meta . ' ev ON ev.post_id = a.ID AND ev.meta_key = %s AND ev.meta_value IN (' . $in_scope . ')'
            . ' LEFT JOIN ' . $meta . ' rs ON rs.post_id = a.ID AND rs.meta_key = %s'
            . ' JOIN ' . $posts . ' o ON o.ID = a.post_parent AND o.post_type = %s AND o.post_status <> %s';
        $where         = ' WHERE a.post_type = %s AND (rs.meta_value IS NULL OR rs.meta_value <> %s)';
        $join_args     = array_merge(
            [EventTicketsSchema::ATTENDEE_EVENT],
            $args,
            [EventTicketsSchema::ATTENDEE_RSVP_STATUS, EventTicketsSchema::POST_TYPE_ORDER, 'trash']
        );
        $where_args    = [EventTicketsSchema::POST_TYPE_ATTENDEE, 'no'];
        $excluded      = TheEventsCalendarSchema::EXCLUDED_POST_STATUSES;
        $prefix        = EventTicketsSchema::ORDER_STATUS_PREFIX;
        $counts        = $events + [
            SourceInventory::COUNT_TICKETS   => $this->db->countRows(
                'SELECT COUNT(*) FROM ' . $posts . ' tp JOIN ' . $meta . ' tm ON tm.post_id = tp.ID AND tm.meta_key = %s'
                . ' WHERE tp.post_type = %s AND tp.post_status NOT IN (' . SourceDatabase::placeholders($excluded, '%s') . ')'
                . ' AND tm.meta_value IN (' . $in_scope . ')',
                array_merge([EventTicketsSchema::TICKET_EVENT, EventTicketsSchema::POST_TYPE_TICKET], $excluded, $args)
            ),
            SourceInventory::COUNT_BOOKINGS  => $this->db->countRows(
                "SELECT COUNT(DISTINCT CONCAT(a.post_parent, '-', ev.meta_value))" . $joins . $where,
                array_merge($join_args, $where_args)
            ),
            SourceInventory::COUNT_ATTENDEES => $this->db->countRows(
                "SELECT COUNT(DISTINCT CASE WHEN uid.meta_value + 0 > 0 THEN CONCAT('u', uid.meta_value)"
                . " ELSE CONCAT('g', LOWER(COALESCE(em.meta_value, a.post_parent))) END)"
                . $joins
                . ' LEFT JOIN ' . $meta . ' uid ON uid.post_id = a.post_parent AND uid.meta_key = %s'
                . ' LEFT JOIN ' . $meta . ' em ON em.post_id = a.post_parent AND em.meta_key = %s'
                . $where,
                array_merge($join_args, [EventTicketsSchema::ORDER_USER_ID, EventTicketsSchema::ORDER_EMAIL], $where_args)
            ),
            SourceInventory::COUNT_PAYMENTS  => $this->db->countRows(
                'SELECT COALESCE(SUM(CASE WHEN o.post_status IN (%s, %s) THEN 2'
                . ' WHEN o.post_status IN (%s, %s, %s, %s, %s, %s, %s) THEN 1 ELSE 0 END), 0)'
                . ' FROM (SELECT DISTINCT a.post_parent AS order_id, ev.meta_value AS event_id' . $joins . $where . ') b'
                . ' JOIN ' . $posts . ' o ON o.ID = b.order_id'
                . ' JOIN ' . $meta . ' total ON total.post_id = o.ID AND total.meta_key = %s AND total.meta_value + 0 > 0',
                array_merge(
                    [$prefix . 'refunded', $prefix . 'reversed'],
                    array_map(
                        static fn(string $slug): string => $prefix . $slug,
                        ['completed', 'pending', 'action-req', 'approved', 'denied', 'not-completed', 'voided']
                    ),
                    $join_args,
                    $where_args,
                    [EventTicketsSchema::ORDER_TOTAL]
                )
            ),
        ];
        return new SourceInventory(
            $counts,
            [],
            [
                __('Tickets, orders and attendees on pages and other post types', 'migrate-events-to-event-espresso'),
                __('RSVP v1 tickets and attendees', 'migrate-events-to-event-espresso'),
                __('Coupons and fees', 'migrate-events-to-event-espresso'),
                __('Seating', 'migrate-events-to-event-espresso'),
                __('Event Tickets Plus attendee fields and WooCommerce tickets', 'migrate-events-to-event-espresso'),
                __('RSVP replies of "not going"', 'migrate-events-to-event-espresso'),
            ],
            $version,
            TimezoneModel::PER_EVENT
        );
    }
}
// End of file EventTicketsInventory.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\event_tickets/EventTicketsInventory.php
