<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterBase;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventsMadeEasyScope
 *
 * Which EME rows a run covers. An "occurrence" is an eme_events row (EME
 * writes one real row per date of a recurrence, all sharing a recurrence_id).
 * Trashed events (event_status 0) and occurrences that ended before the
 * horizon (the local end, or the start when there is no end) are left out
 * (REQ-004, REQ-025).
 *
 * In "single event" mode a recurrence is one migrated event whose id is
 * "series:<recurrence id>"; every other event, and every occurrence in
 * "event per occurrence" mode, is its own migrated event with the plain
 * event id.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventsMadeEasyScope
{
    private SourceDatabase $db;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * FROM and WHERE for the occurrences in scope, with its arguments. Alias: e.
     *
     * @return array{0: string, 1: array}
     */
    public function occurrenceFrom(MigrationOptions $options): array
    {
        $end  = "COALESCE(NULLIF(e.event_end, '0000-00-00 00:00:00'), e.event_start)";
        $sql  = ' FROM ' . $this->db->table(EventsMadeEasySchema::EVENTS) . ' e WHERE e.event_status <> %d'
            . SourceAdapterBase::horizonSql($options, $end);
        $args = [EventsMadeEasySchema::EVENT_TRASH];
        if (! $options->isAllEvents()) {
            $args[] = SourceAdapterBase::horizonBoundary($options);
        }
        return [$sql, $args];
    }


    /**
     * The migrated event id of an occurrence: its recurrence's in single-event mode, its own otherwise.
     */
    public static function eventIdExpression(MigrationOptions $options): string
    {
        return $options->isEventPerOccurrence()
            ? 'CAST(e.event_id AS CHAR)'
            : "CASE WHEN e.recurrence_id > 0 THEN CONCAT('" . EventsMadeEasySchema::SERIES_PREFIX . "', e.recurrence_id)"
                . ' ELSE CAST(e.event_id AS CHAR) END';
    }


    /**
     * The event ids to migrate, in the order of their first occurrence.
     *
     * @return string[]
     * @throws MigrationRecordException When the database reports an error.
     */
    public function eventIds(MigrationOptions $options): array
    {
        [$from, $args] = $this->occurrenceFrom($options);
        return $this->db->column(
            'SELECT ' . self::eventIdExpression($options) . ' AS migrated_id, MIN(e.event_id) AS first_id'
            . $from . ' GROUP BY migrated_id ORDER BY first_id',
            $args
        );
    }


    /**
     * The eme_events ids behind a migrated event id: a recurrence's dates (earliest first), or the event itself.
     *
     * @return int[]
     * @throws MigrationRecordException When the database reports an error.
     */
    public function occurrenceIdsOf(string $event_id): array
    {
        if (! self::isSeries($event_id)) {
            return [(int) $event_id];
        }
        return array_map(
            'intval',
            $this->db->column(
                'SELECT event_id FROM ' . $this->db->table(EventsMadeEasySchema::EVENTS)
                . ' WHERE recurrence_id = %d AND event_status <> %d ORDER BY event_start, event_id',
                [(int) substr($event_id, strlen(EventsMadeEasySchema::SERIES_PREFIX)), EventsMadeEasySchema::EVENT_TRASH]
            )
        );
    }


    public static function isSeries(string $event_id): bool
    {
        return strpos($event_id, EventsMadeEasySchema::SERIES_PREFIX) === 0;
    }
}
// End of file EventsMadeEasyScope.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy/EventsMadeEasyScope.php
