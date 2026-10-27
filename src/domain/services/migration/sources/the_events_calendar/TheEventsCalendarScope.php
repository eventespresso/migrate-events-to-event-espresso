<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterBase;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class TheEventsCalendarScope
 *
 * Which events a run covers: `tribe_events` posts that are not trashed,
 * auto drafts or ignored by the Event Aggregator, and that end after the
 * horizon (on the `_EventEndDateUTC` meta, REQ-004, REQ-025). The same FROM
 * and WHERE serve the event ids and every inventory count.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class TheEventsCalendarScope
{
    private SourceDatabase $db;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * FROM and WHERE for the events in scope, with their arguments. Aliases: p (the event post), ends (its UTC
     * end meta row, or NULL).
     *
     * @return array{0: string, 1: array}
     */
    public function eventFrom(MigrationOptions $options): array
    {
        $excluded = TheEventsCalendarSchema::EXCLUDED_POST_STATUSES;
        $sql      = ' FROM ' . $this->db->table('posts') . ' p'
            . ' LEFT JOIN ' . $this->db->table('postmeta') . ' ends ON ends.post_id = p.ID AND ends.meta_key = %s'
            . ' WHERE p.post_type = %s AND p.post_status NOT IN (' . SourceDatabase::placeholders($excluded, '%s') . ')'
            . SourceAdapterBase::horizonSql($options, 'ends.meta_value');
        $args     = array_merge([TheEventsCalendarSchema::META_END_UTC, TheEventsCalendarSchema::POST_TYPE_EVENT], $excluded);
        if (! $options->isAllEvents()) {
            $args[] = SourceAdapterBase::horizonBoundaryUtc($options);
        }
        return [$sql, $args];
    }


    /**
     * The event post ids to migrate, ascending.
     *
     * @return string[]
     * @throws MigrationRecordException When the read fails.
     */
    public function eventIds(MigrationOptions $options): array
    {
        [$from, $args] = $this->eventFrom($options);
        return $this->db->column('SELECT p.ID' . $from . ' ORDER BY p.ID', $args);
    }
}
// End of file TheEventsCalendarScope.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar/TheEventsCalendarScope.php
