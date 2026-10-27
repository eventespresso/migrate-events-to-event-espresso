<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\wp_event_manager;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterBase;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class WpEventManagerScope
 *
 * Which WP Event Manager rows a run covers: `event_listing` posts that are
 * not trashed, auto-drafts or `preview` (an unsubmitted front-end draft), and
 * that ended on or after the horizon. Each event has one date range, so there
 * are no occurrences or series. The end is `_event_end_date` (a local
 * `Y-m-d H:i:s`, or only a date in versions before 3.1.10), else the start;
 * the horizon is compared by date.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class WpEventManagerScope
{
    private SourceDatabase $db;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * FROM and WHERE for the events in scope, with its arguments. Alias: p.
     *
     * @return array{0: string, 1: array}
     */
    public function eventFrom(MigrationOptions $options): array
    {
        $meta     = $this->db->table('postmeta');
        $excluded = WpEventManagerSchema::EXCLUDED_POST_STATUSES;
        $end      = "LEFT(COALESCE(NULLIF(pe.meta_value, ''), ps.meta_value), 10)";
        $sql      = ' FROM ' . $this->db->table('posts') . ' p'
            . ' LEFT JOIN ' . $meta . " ps ON ps.post_id = p.ID AND ps.meta_key = '_event_start_date'"
            . ' LEFT JOIN ' . $meta . " pe ON pe.post_id = p.ID AND pe.meta_key = '_event_end_date'"
            . ' WHERE p.post_type = %s AND p.post_status NOT IN (' . SourceDatabase::placeholders($excluded, '%s') . ')'
            . SourceAdapterBase::horizonSql($options, $end);
        $args     = array_merge([WpEventManagerSchema::POST_TYPE_EVENT], $excluded);
        if (! $options->isAllEvents()) {
            $args[] = substr(SourceAdapterBase::horizonBoundary($options), 0, 10);
        }
        return [$sql, $args];
    }


    /**
     * The event ids to migrate, ascending.
     *
     * @return string[]
     * @throws MigrationRecordException When the database reports an error.
     */
    public function eventIds(MigrationOptions $options): array
    {
        [$from, $args] = $this->eventFrom($options);
        return $this->db->column('SELECT p.ID' . $from . ' ORDER BY p.ID', $args);
    }
}
// End of file WpEventManagerScope.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\wp_event_manager/WpEventManagerScope.php
