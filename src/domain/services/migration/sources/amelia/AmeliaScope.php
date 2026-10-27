<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\amelia;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterBase;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class AmeliaScope
 *
 * Which Amelia rows a run covers. An "occurrence" is an amelia_events row
 * with at least one period; a recurring series is the occurrences sharing a
 * parentId (the origin row's parentId is its own id). Amelia has no trash and
 * no draft state, so every event is in scope; an event that ended before the
 * horizon (its last period, in UTC) is left out (REQ-004).
 *
 * In "single event" mode a series is one migrated event whose id is
 * "series:<origin id>"; every other event, and every occurrence in "event per
 * occurrence" mode, is its own migrated event with the plain row id.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class AmeliaScope
{
    private SourceDatabase $db;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * FROM and WHERE for the occurrences in scope, with its arguments. Alias: e (the occurrence).
     *
     * @return array{0: string, 1: array}
     */
    public function occurrenceFrom(MigrationOptions $options): array
    {
        $sql  = ' FROM ' . $this->db->table(AmeliaSchema::EVENTS) . ' e'
            . ' WHERE EXISTS (SELECT 1 FROM ' . $this->db->table(AmeliaSchema::PERIODS) . ' ep'
            . ' WHERE ep.eventId = e.id' . SourceAdapterBase::horizonSql($options, 'ep.periodEnd') . ')';
        $args = $options->isAllEvents() ? [] : [SourceAdapterBase::horizonBoundaryUtc($options)];
        return [$sql, $args];
    }


    /**
     * The migrated event id of an occurrence: its series' in single-event mode, its own otherwise.
     */
    public static function eventIdExpression(MigrationOptions $options): string
    {
        return $options->isEventPerOccurrence()
            ? 'CAST(e.id AS CHAR)'
            : "CASE WHEN e.parentId IS NULL THEN CAST(e.id AS CHAR) ELSE CONCAT('" . AmeliaSchema::SERIES_PREFIX . "', e.parentId) END";
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
            'SELECT ' . self::eventIdExpression($options) . ' AS migrated_id, MIN(e.id) AS first_id'
            . $from . ' GROUP BY migrated_id ORDER BY first_id',
            $args
        );
    }


    /**
     * The amelia_events ids behind a migrated event id: a series' occurrences, or the row itself.
     *
     * @return int[]
     * @throws MigrationRecordException When the database reports an error.
     */
    public function occurrenceIdsOf(string $event_id): array
    {
        if (strpos($event_id, AmeliaSchema::SERIES_PREFIX) !== 0) {
            return [(int) $event_id];
        }
        return array_map(
            'intval',
            $this->db->column(
                'SELECT id FROM ' . $this->db->table(AmeliaSchema::EVENTS) . ' WHERE parentId = %d ORDER BY id',
                [(int) substr($event_id, strlen(AmeliaSchema::SERIES_PREFIX))]
            )
        );
    }
}
// End of file AmeliaScope.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\amelia/AmeliaScope.php
