<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\records\SourceEventRecord;
use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;
use EventEspresso\EventMigrator\application\services\migration\SourceCapabilities;
use EventEspresso\EventMigrator\application\services\migration\SourceDetection;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterBase;

/**
 * Class TheEventsCalendarSourceAdapter
 *
 * Migrates events, venues and categories from The Events Calendar
 * (StellarWP), version 6.18.1 as profiled in research/sources/the-events-
 * calendar.md. Events are `tribe_events` posts with local start/end meta in
 * the event's own timezone; free TEC has no recurrence, tickets or bookings
 * (those are Event Tickets, see the event_tickets adapter), so the cost text
 * becomes the event's one implicit ticket.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class TheEventsCalendarSourceAdapter extends SourceAdapterBase
{
    public const SLUG = 'the-events-calendar';

    private ?TheEventsCalendarScope $scope = null;


    public function slug(): string
    {
        return self::SLUG;
    }


    public function name(): string
    {
        return 'The Events Calendar';
    }


    /**
     * Installed when the plugin's files are present or events it created are still in the posts table;
     * selectable when the plugin is active. The plugin's own post types are the data check: it has no
     * tables of its own that the migration needs.
     *
     * @throws MigrationRecordException When a read fails.
     */
    protected function detectSource(): SourceDetection
    {
        $plugin = $this->pluginState(TheEventsCalendarSchema::PLUGIN_FILE);
        $has_data = $this->countRows(
            'SELECT COUNT(*) FROM ' . $this->table('posts') . ' WHERE post_type = %s',
            [TheEventsCalendarSchema::POST_TYPE_EVENT]
        ) > 0;
        $reason = '';
        if ($plugin['installed'] && ! $plugin['active']) {
            $reason = $this->notActiveReason();
        } elseif (! $plugin['installed'] && $has_data) {
            $reason = $this->notInstalledReason();
        }
        return new SourceDetection(
            $plugin['installed'] || $has_data,
            $plugin['active'],
            $plugin['version'],
            in_array($plugin['version'], $this->supportedVersions(), true),
            $reason
        );
    }


    public function capabilities(): SourceCapabilities
    {
        return new SourceCapabilities([SourceCapabilities::DOMAIN_EVENTS], false, TimezoneModel::PER_EVENT);
    }


    public function supportedVersions(): array
    {
        return ['6.18.1'];
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function inventory(MigrationOptions $options): SourceInventory
    {
        return (new TheEventsCalendarInventory($this->database(), $this->scope()))
            ->build($options, $this->pluginState(TheEventsCalendarSchema::PLUGIN_FILE)['version']);
    }


    /**
     * @throws MigrationRecordException When the read fails.
     */
    public function eventIds(MigrationOptions $options): iterable
    {
        return $this->scope()->eventIds($options);
    }


    /**
     * @throws MigrationRecordException When the event does not exist or a read fails.
     */
    public function readEvent(string $source_event_id, MigrationOptions $options): SourceEventRecord
    {
        return (new TheEventsCalendarEventReader($this->database()))->read($source_event_id);
    }


    /**
     * The Events Calendar has no bookings; Event Tickets' are read by the event_tickets adapter.
     */
    public function readBookings(string $source_event_id, int $offset, int $limit): array
    {
        return [];
    }


    public function statusTable(): StatusTable
    {
        return TheEventsCalendarStatusTable::table();
    }


    public function customFieldDefinitions(): array
    {
        return [];
    }


    private function scope(): TheEventsCalendarScope
    {
        if (! $this->scope instanceof TheEventsCalendarScope) {
            $this->scope = new TheEventsCalendarScope($this->database());
        }
        return $this->scope;
    }
}
// End of file TheEventsCalendarSourceAdapter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar/TheEventsCalendarSourceAdapter.php
