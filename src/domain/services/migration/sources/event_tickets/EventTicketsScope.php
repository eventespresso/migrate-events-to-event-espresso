<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\event_tickets;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;
use EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar\TheEventsCalendarScope;
use EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar\TheEventsCalendarSchema;

/**
 * Class EventTicketsScope
 *
 * Which events a run covers: the Events Calendar events (TheEventsCalendarScope:
 * not trashed, not ignored, ending after the horizon) that have at least one
 * ticket that is not trashed. Tickets on other post types are never migrated
 * (plan decision); exclusions() lists their host posts. The same FROM and
 * WHERE serve the event ids, the tickets and the inventory.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventTicketsScope extends TheEventsCalendarScope
{
    private SourceDatabase $database;


    public function __construct(SourceDatabase $db)
    {
        parent::__construct($db);
        $this->database = $db;
    }


    /**
     * FROM and WHERE for the events in scope, with their arguments; aliases as in TheEventsCalendarScope.
     *
     * @return array{0: string, 1: array}
     */
    public function eventFrom(MigrationOptions $options): array
    {
        [$sql, $args] = parent::eventFrom($options);
        $excluded     = TheEventsCalendarSchema::EXCLUDED_POST_STATUSES;
        $sql         .= ' AND EXISTS (SELECT 1 FROM ' . $this->database->table('postmeta') . ' tm'
            . ' JOIN ' . $this->database->table('posts') . ' tp ON tp.ID = tm.post_id'
            . ' WHERE tm.meta_key = %s AND tm.meta_value = p.ID AND tp.post_type = %s'
            . ' AND tp.post_status NOT IN (' . SourceDatabase::placeholders($excluded, '%s') . '))';
        return [$sql, array_merge($args, [EventTicketsSchema::TICKET_EVENT, EventTicketsSchema::POST_TYPE_TICKET], $excluded)];
    }


    /**
     * The host posts that have tickets but are not Events Calendar events (and not trashed events).
     *
     * @return string[] Post ids, ascending.
     * @throws MigrationRecordException When the read fails.
     */
    public function excludedHostIds(): array
    {
        $excluded = TheEventsCalendarSchema::EXCLUDED_POST_STATUSES;
        return $this->database->column(
            'SELECT DISTINCT tm.meta_value AS host FROM ' . $this->database->table('postmeta') . ' tm'
            . ' JOIN ' . $this->database->table('posts') . ' tp ON tp.ID = tm.post_id'
            . ' LEFT JOIN ' . $this->database->table('posts') . ' hp ON hp.ID = tm.meta_value'
            . ' WHERE tm.meta_key = %s AND tp.post_type = %s AND tp.post_status NOT IN (' . SourceDatabase::placeholders($excluded, '%s') . ')'
            . ' AND (hp.ID IS NULL OR hp.post_type <> %s) ORDER BY tm.meta_value + 0',
            array_merge(
                [EventTicketsSchema::TICKET_EVENT, EventTicketsSchema::POST_TYPE_TICKET],
                $excluded,
                [EventTicketsSchema::HOST_POST_TYPE]
            )
        );
    }


    /**
     * Ticket posts of one event in display order: id => row.
     *
     * @return array<int, array<string, string>>
     * @throws MigrationRecordException When the read fails.
     */
    public function tickets(int $event_id): array
    {
        $excluded = TheEventsCalendarSchema::EXCLUDED_POST_STATUSES;
        $rows     = $this->database->rows(
            'SELECT tp.ID, tp.post_title, tp.post_excerpt, tp.post_status, tp.menu_order FROM ' . $this->database->table('posts') . ' tp'
            . ' JOIN ' . $this->database->table('postmeta') . ' tm ON tm.post_id = tp.ID AND tm.meta_key = %s AND tm.meta_value = %s'
            . ' WHERE tp.post_type = %s AND tp.post_status NOT IN (' . SourceDatabase::placeholders($excluded, '%s') . ')'
            . ' ORDER BY tp.menu_order, tp.ID',
            array_merge([EventTicketsSchema::TICKET_EVENT, (string) $event_id, EventTicketsSchema::POST_TYPE_TICKET], $excluded)
        );
        $tickets  = [];
        foreach ($rows as $row) {
            $tickets[ (int) $row['ID'] ] = array_map('strval', $row);
        }
        return $tickets;
    }
}
// End of file EventTicketsScope.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\event_tickets/EventTicketsScope.php
