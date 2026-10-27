<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\event_genius;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterBase;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventGeniusScope
 *
 * Which Event Genius rows a run covers. An "occurrence" is an `evge_event`
 * post: a single event, or a recurring series' template (its first date) or
 * generated occurrence (`evge_is_recurrence` holds the template's id). An
 * occurrence detached from its template is a single event. Trashed posts,
 * auto-drafts and occurrences that ended before the horizon (the UTC end
 * date, or the start when there is no end) are left out (REQ-004, REQ-025).
 *
 * In "single event" mode a series is one migrated event whose id is
 * "series:<template post id>"; every other event, and every occurrence in
 * "event per occurrence" mode, is its own migrated event with the plain post
 * id. The date meta is read rather than the timing table, which the plugin
 * only refreshes when an event is saved in the admin.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventGeniusScope
{
    private SourceDatabase $db;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * FROM and WHERE for the occurrences in scope, with its arguments. Aliases: p (the post), ir (its
     * evge_is_recurrence meta), rt (evge_recurrence_type), dt (evge_detached_from_template_id).
     *
     * @return array{0: string, 1: array}
     */
    public function occurrenceFrom(MigrationOptions $options): array
    {
        $meta     = $this->db->table('postmeta');
        $excluded = EventGeniusSchema::EXCLUDED_POST_STATUSES;
        $end      = "COALESCE(NULLIF(pe.meta_value, ''), ps.meta_value)";
        $sql      = ' FROM ' . $this->db->table('posts') . ' p'
            . ' LEFT JOIN ' . $meta . " pe ON pe.post_id = p.ID AND pe.meta_key = 'evge_end_date_utc'"
            . ' LEFT JOIN ' . $meta . " ps ON ps.post_id = p.ID AND ps.meta_key = 'evge_start_date_utc'"
            . ' LEFT JOIN ' . $meta . " ir ON ir.post_id = p.ID AND ir.meta_key = 'evge_is_recurrence'"
            . ' LEFT JOIN ' . $meta . " rt ON rt.post_id = p.ID AND rt.meta_key = 'evge_recurrence_type'"
            . ' LEFT JOIN ' . $meta . " dt ON dt.post_id = p.ID AND dt.meta_key = 'evge_detached_from_template_id'"
            . ' WHERE p.post_type = %s AND p.post_status NOT IN (' . SourceDatabase::placeholders($excluded, '%s') . ')'
            . SourceAdapterBase::horizonSql($options, $end);
        $args     = array_merge([EventGeniusSchema::POST_TYPE_EVENT], $excluded);
        if (! $options->isAllEvents()) {
            $args[] = SourceAdapterBase::horizonBoundaryUtc($options);
        }
        return [$sql, $args];
    }


    /**
     * The migrated event id of an occurrence: its series' in single-event mode, its own otherwise.
     */
    public static function eventIdExpression(MigrationOptions $options): string
    {
        if ($options->isEventPerOccurrence()) {
            return 'CAST(p.ID AS CHAR)';
        }
        $prefix    = EventGeniusSchema::SERIES_PREFIX;
        $repeating = "'" . implode("','", EventGeniusSchema::REPEATING) . "'";
        return "CASE WHEN COALESCE(NULLIF(dt.meta_value, ''), '0') <> '0' THEN CAST(p.ID AS CHAR)"
            . " WHEN COALESCE(NULLIF(ir.meta_value, ''), '0') <> '0' THEN CONCAT('" . $prefix . "', ir.meta_value)"
            . ' WHEN rt.meta_value IN (' . $repeating . ") THEN CONCAT('" . $prefix . "', p.ID)"
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
     * The `evge_event` post ids behind a migrated event id: a series' template and its occurrences (earliest
     * first), or the post itself.
     *
     * @return int[]
     * @throws MigrationRecordException When the database reports an error.
     */
    public function occurrenceIdsOf(string $event_id): array
    {
        if (! self::isSeries($event_id)) {
            return [(int) $event_id];
        }
        $excluded = EventGeniusSchema::EXCLUDED_POST_STATUSES;
        $meta     = $this->db->table('postmeta');
        return array_map(
            'intval',
            $this->db->column(
                'SELECT p.ID FROM ' . $this->db->table('posts') . ' p LEFT JOIN ' . $meta
                . " ir ON ir.post_id = p.ID AND ir.meta_key = 'evge_is_recurrence' LEFT JOIN " . $meta
                . " ps ON ps.post_id = p.ID AND ps.meta_key = 'evge_start_date_utc' LEFT JOIN " . $meta
                . " dt ON dt.post_id = p.ID AND dt.meta_key = 'evge_detached_from_template_id'"
                . ' WHERE p.post_type = %s AND p.post_status NOT IN (' . SourceDatabase::placeholders($excluded, '%s') . ')'
                . " AND (p.ID = %d OR (ir.meta_value = %s AND COALESCE(NULLIF(dt.meta_value, ''), '0') = '0'))"
                . ' ORDER BY ps.meta_value, p.ID',
                array_merge(
                    [EventGeniusSchema::POST_TYPE_EVENT],
                    $excluded,
                    [self::templateId($event_id), (string) self::templateId($event_id)]
                )
            )
        );
    }


    public static function isSeries(string $event_id): bool
    {
        return strpos($event_id, EventGeniusSchema::SERIES_PREFIX) === 0;
    }


    /**
     * The post id that prefixes the event's ticket ids: the series template's, or the event's own.
     */
    public static function templateId(string $event_id): int
    {
        return (int) (self::isSeries($event_id) ? substr($event_id, strlen(EventGeniusSchema::SERIES_PREFIX)) : $event_id);
    }
}
// End of file EventGeniusScope.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\event_genius/EventGeniusScope.php
