<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\wp_events_manager;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterBase;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class WpEventsManagerScope
 *
 * Which WP Events Manager rows a run covers: `tp_event` posts that are not
 * trashed or auto-drafts and that ended on or after the horizon. An event has
 * one date range (a `Y-m-d` date and an `H:i` time each, local), so there are
 * no occurrences; the end is the end date, else the start date, compared by
 * date (REQ-004, REQ-025).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class WpEventsManagerScope
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
        $excluded = WpEventsManagerSchema::EXCLUDED_POST_STATUSES;
        $end      = "LEFT(COALESCE(NULLIF(pe.meta_value, ''), ps.meta_value), 10)";
        $sql      = ' FROM ' . $this->db->table('posts') . ' p'
            . ' LEFT JOIN ' . $meta . " ps ON ps.post_id = p.ID AND ps.meta_key = 'tp_event_date_start'"
            . ' LEFT JOIN ' . $meta . " pe ON pe.post_id = p.ID AND pe.meta_key = 'tp_event_date_end'"
            . ' WHERE p.post_type = %s AND p.post_status NOT IN (' . SourceDatabase::placeholders($excluded, '%s') . ')'
            . SourceAdapterBase::horizonSql($options, $end);
        $args     = array_merge([WpEventsManagerSchema::POST_TYPE_EVENT], $excluded);
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
// End of file WpEventsManagerScope.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\wp_events_manager/WpEventsManagerScope.php
