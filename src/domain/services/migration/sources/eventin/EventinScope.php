<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventin;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterBase;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventinScope
 *
 * Which Eventin rows a run covers. An "occurrence" is an `etn` post: a single
 * event, or a recurring parent or child (children have the parent as
 * post_parent and a date of their own). Trashed posts, auto-drafts, the
 * demo placeholder and occurrences that ended before the horizon (the local
 * end date, which has no time zone) are left out (REQ-004, REQ-025).
 *
 * In "single event" mode a series is one migrated event whose id is
 * "series:<parent post id>"; every other event, and every occurrence in
 * "event per occurrence" mode, is its own migrated event with the plain post
 * id.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventinScope
{
    private SourceDatabase $db;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * FROM and WHERE for the occurrences in scope, with its arguments. Aliases: p (the post), pr (its
     * recurring_enabled meta).
     *
     * @return array{0: string, 1: array}
     */
    public function occurrenceFrom(MigrationOptions $options): array
    {
        $meta       = $this->db->table('postmeta');
        $excluded   = EventinSchema::EXCLUDED_POST_STATUSES;
        $end        = "COALESCE(NULLIF(pe.meta_value, ''), ps.meta_value)";
        $sql        = ' FROM ' . $this->db->table('posts') . ' p'
            . ' LEFT JOIN ' . $meta . ' ph ON ph.post_id = p.ID AND ph.meta_key = %s'
            . ' LEFT JOIN ' . $meta . " ps ON ps.post_id = p.ID AND ps.meta_key = 'etn_start_date'"
            . ' LEFT JOIN ' . $meta . " pe ON pe.post_id = p.ID AND pe.meta_key = 'etn_end_date'"
            . ' LEFT JOIN ' . $meta . " pr ON pr.post_id = p.ID AND pr.meta_key = 'recurring_enabled'"
            . ' WHERE p.post_type = %s AND p.post_status NOT IN (' . SourceDatabase::placeholders($excluded, '%s') . ')'
            . " AND (ph.meta_value IS NULL OR ph.meta_value NOT IN ('1', 'true', 'yes'))"
            . SourceAdapterBase::horizonSql($options, $end);
        $args       = array_merge([EventinSchema::PLACEHOLDER_META, EventinSchema::POST_TYPE_EVENT], $excluded);
        if (! $options->isAllEvents()) {
            $args[] = substr(SourceAdapterBase::horizonBoundary($options), 0, 10);
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
            : "CASE WHEN p.post_parent > 0 THEN CONCAT('" . EventinSchema::SERIES_PREFIX . "', p.post_parent)"
                . " WHEN pr.meta_value = 'on' THEN CONCAT('" . EventinSchema::SERIES_PREFIX . "', p.ID)"
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
     * The `etn` post ids behind a migrated event id: a series' parent and children, or the post itself.
     *
     * @return int[]
     * @throws MigrationRecordException When the database reports an error.
     */
    public function occurrenceIdsOf(string $event_id): array
    {
        if (! self::isSeries($event_id)) {
            return [(int) $event_id];
        }
        $excluded = EventinSchema::EXCLUDED_POST_STATUSES;
        $parent   = self::parentId($event_id);
        return array_map(
            'intval',
            $this->db->column(
                'SELECT ID FROM ' . $this->db->table('posts') . ' WHERE post_type = %s AND (ID = %d OR post_parent = %d)'
                . ' AND post_status NOT IN (' . SourceDatabase::placeholders($excluded, '%s') . ') ORDER BY ID',
                array_merge([EventinSchema::POST_TYPE_EVENT, $parent, $parent], $excluded)
            )
        );
    }


    public static function isSeries(string $event_id): bool
    {
        return strpos($event_id, EventinSchema::SERIES_PREFIX) === 0;
    }


    /**
     * The post id that prefixes the event's ticket ids: the series parent's, or the event's own.
     */
    public static function parentId(string $event_id): int
    {
        return (int) (self::isSeries($event_id) ? substr($event_id, strlen(EventinSchema::SERIES_PREFIX)) : $event_id);
    }
}
// End of file EventinScope.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventin/EventinScope.php
