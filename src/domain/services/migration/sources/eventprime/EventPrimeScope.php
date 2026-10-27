<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventprime;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterBase;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventPrimeScope
 *
 * Which EventPrime rows a run covers. An "occurrence" is an `em_event` post:
 * a single event, or a recurring parent or child (children have the parent as
 * post_parent and dates of their own). Trashed posts and auto-drafts, and
 * occurrences that ended before the horizon (`em_end_date_time` is a Unix
 * timestamp), are left out (REQ-004, REQ-025).
 *
 * In "single event" mode a recurring series is one migrated event whose id
 * is "series:<parent post id>"; every other event, and every occurrence in
 * "event per occurrence" mode, is its own migrated event with the plain post
 * id.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventPrimeScope
{
    private SourceDatabase $db;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * FROM and WHERE for the occurrences in scope, with its arguments. Aliases: p (the post), pr (its
     * em_enable_recurrence meta).
     *
     * @return array{0: string, 1: array}
     */
    public function occurrenceFrom(MigrationOptions $options): array
    {
        $meta     = $this->db->table('postmeta');
        $excluded = EventPrimeSchema::EXCLUDED_POST_STATUSES;
        $end      = "CAST(COALESCE(NULLIF(pe.meta_value, ''), ps.meta_value) AS UNSIGNED)";
        $sql      = ' FROM ' . $this->db->table('posts') . ' p'
            . ' LEFT JOIN ' . $meta . " ps ON ps.post_id = p.ID AND ps.meta_key = 'em_start_date_time'"
            . ' LEFT JOIN ' . $meta . " pe ON pe.post_id = p.ID AND pe.meta_key = 'em_end_date_time'"
            . ' LEFT JOIN ' . $meta . " pr ON pr.post_id = p.ID AND pr.meta_key = 'em_enable_recurrence'"
            . ' WHERE p.post_type = %s AND p.post_status NOT IN (' . SourceDatabase::placeholders($excluded, '%s') . ')'
            . SourceAdapterBase::horizonSql($options, $end);
        $args     = array_merge([EventPrimeSchema::POST_TYPE_EVENT], $excluded);
        if (! $options->isAllEvents()) {
            $args[] = (string) strtotime(SourceAdapterBase::horizonBoundaryUtc($options) . ' UTC');
        }
        return [$sql, $args];
    }


    /**
     * The migrated event id of an occurrence: its series' in single-event mode, its own otherwise.
     */
    public static function eventIdExpression(MigrationOptions $options): string
    {
        return $options->isEventPerOccurrence()
            ? 'CAST(p.ID AS CHAR)'
            : "CASE WHEN p.post_parent > 0 THEN CONCAT('" . EventPrimeSchema::SERIES_PREFIX . "', p.post_parent)"
                . " WHEN pr.meta_value = '1' THEN CONCAT('" . EventPrimeSchema::SERIES_PREFIX . "', p.ID)"
                . ' ELSE CAST(p.ID AS CHAR) END';
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
            'SELECT ' . self::eventIdExpression($options) . ' AS migrated_id, MIN(p.ID) AS first_id'
            . $from . ' GROUP BY migrated_id ORDER BY first_id',
            $args
        );
    }


    /**
     * The `em_event` post ids behind a migrated event id: a series' parent and children, or the post itself.
     *
     * @return int[]
     * @throws MigrationRecordException When the database reports an error.
     */
    public function occurrenceIdsOf(string $event_id): array
    {
        if (! self::isSeries($event_id)) {
            return [(int) $event_id];
        }
        $excluded = EventPrimeSchema::EXCLUDED_POST_STATUSES;
        $parent   = self::parentId($event_id);
        return array_map(
            'intval',
            $this->db->column(
                'SELECT ID FROM ' . $this->db->table('posts') . ' WHERE post_type = %s AND (ID = %d OR post_parent = %d)'
                . ' AND post_status NOT IN (' . SourceDatabase::placeholders($excluded, '%s') . ') ORDER BY ID',
                array_merge([EventPrimeSchema::POST_TYPE_EVENT, $parent, $parent], $excluded)
            )
        );
    }


    public static function isSeries(string $event_id): bool
    {
        return strpos($event_id, EventPrimeSchema::SERIES_PREFIX) === 0;
    }


    /**
     * The parent post id of a series, or the event's own post id.
     */
    public static function parentId(string $event_id): int
    {
        return (int) (self::isSeries($event_id) ? substr($event_id, strlen(EventPrimeSchema::SERIES_PREFIX)) : $event_id);
    }
}
// End of file EventPrimeScope.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventprime/EventPrimeScope.php
