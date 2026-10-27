<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_manager;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterBase;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventsManagerScope
 *
 * Which Events Manager rows a run covers. An "occurrence" is an em_events row
 * that is a date: a single event, or a child ("recurrence") of a recurring or
 * repeating series. Its owning post is its own for a single event and the
 * series parent's for a child. Trashed posts, auto-drafts, event_status -1
 * and occurrences that ended before the horizon (on the UTC event_end column)
 * are left out (REQ-004, REQ-025).
 *
 * In "single event" mode a series is one migrated event whose id is the
 * series parent's; in "event per occurrence" mode every occurrence is its own.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventsManagerScope
{
    private SourceDatabase $db;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * FROM and WHERE for the occurrences in scope, with its arguments. Aliases: e (the occurrence), pe (the
     * series parent, or null), p (the owning post).
     *
     * @return array{0: string, 1: array}
     */
    public function occurrenceFrom(MigrationOptions $options): array
    {
        $events     = $this->db->table(EventsManagerSchema::EVENTS);
        $posts      = $this->db->table('posts');
        $excluded   = EventsManagerSchema::EXCLUDED_POST_STATUSES;
        $not_listed = SourceDatabase::placeholders($excluded, '%s');
        $sql        = ' FROM ' . $events . ' e'
            . ' LEFT JOIN ' . $this->db->table(EventsManagerSchema::RECURRENCES) . ' r'
            . " ON e.event_type = 'recurrence' AND r.recurrence_set_id = e.recurrence_set_id"
            . ' LEFT JOIN ' . $events . ' pe ON pe.event_id = r.event_id'
            . ' JOIN ' . $posts . ' p ON p.ID = COALESCE(pe.post_id, e.post_id)'
            . ' LEFT JOIN ' . $posts . " op ON e.event_type = 'recurrence' AND op.ID = e.post_id"
            . " WHERE COALESCE(e.event_type, 'single') IN ('single', 'recurrence')"
            . ' AND p.post_status NOT IN (' . $not_listed . ')'
            . ' AND (op.ID IS NULL OR op.post_status NOT IN (' . $not_listed . '))'
            . ' AND (e.event_status IS NULL OR e.event_status <> -1)'
            . ' AND (pe.event_id IS NULL OR pe.event_status IS NULL OR pe.event_status <> -1)'
            . SourceAdapterBase::horizonSql($options, 'e.event_end');
        $args       = array_merge($excluded, $excluded);
        if (! $options->isAllEvents()) {
            $args[] = SourceAdapterBase::horizonBoundaryUtc($options);
        }
        return [$sql, $args];
    }


    /**
     * The migrated event id of an occurrence: the series parent's in single-event mode, its own otherwise.
     */
    public static function eventIdExpression(MigrationOptions $options): string
    {
        return $options->isEventPerOccurrence()
            ? 'e.event_id'
            : "CASE WHEN e.event_type = 'recurrence' AND pe.event_id IS NOT NULL THEN pe.event_id ELSE e.event_id END";
    }


    /**
     * The event ids to migrate, ascending.
     *
     * @return string[]
     * @throws MigrationRecordException When the database reports an error.
     */
    public function eventIds(MigrationOptions $options): array
    {
        [$from, $args] = $this->occurrenceFrom($options);
        return $this->db->column(
            'SELECT DISTINCT ' . self::eventIdExpression($options) . ' AS migrated_id' . $from . ' ORDER BY migrated_id',
            $args
        );
    }


    /**
     * The em_events ids of an event's dates: the event itself, or a series parent's children (all of them; the
     * horizon picks series, not dates within one).
     *
     * @return int[]
     * @throws MigrationRecordException When the database reports an error.
     */
    public function occurrenceIdsOf(int $event_id): array
    {
        $type = $this->db->scalar(
            'SELECT event_type FROM ' . $this->db->table(EventsManagerSchema::EVENTS) . ' WHERE event_id = %d',
            [$event_id]
        );
        if (! in_array($type, EventsManagerSchema::seriesTypes(), true)) {
            return [$event_id];
        }
        return array_map(
            'intval',
            $this->db->column(
                'SELECT e.event_id FROM ' . $this->db->table(EventsManagerSchema::EVENTS) . ' e'
                . ' JOIN ' . $this->db->table(EventsManagerSchema::RECURRENCES) . ' r ON r.recurrence_set_id = e.recurrence_set_id'
                . " WHERE r.event_id = %d AND e.event_type = 'recurrence'"
                . ' AND (e.event_status IS NULL OR e.event_status <> -1) ORDER BY e.event_start, e.event_id',
                [$event_id]
            )
        );
    }
}
// End of file EventsManagerScope.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_manager/EventsManagerScope.php
