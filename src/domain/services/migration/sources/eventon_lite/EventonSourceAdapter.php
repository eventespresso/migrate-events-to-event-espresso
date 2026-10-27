<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventon_lite;

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
 * Class EventonSourceAdapter
 *
 * Migrates events from EventON Lite, version 2.5.10 as profiled in
 * research/sources/eventon-lite.md. Events are `ajde_events` posts whose
 * dates are wall-clock times stored as if they were UTC (read in the event's
 * own zone); a repeating event is one post with its dates in a meta list;
 * locations and organizers are terms whose details live in the `evo_tax_meta`
 * option. EventON Lite has no tables, tickets or bookings: the adapter provides
 * events only, and the engine gives each event a free ticket.
 *
 * The adapter only selects which rows to read; the reader classes in this
 * directory read them, all through one SourceDatabase.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventonSourceAdapter extends SourceAdapterBase
{
    public const SLUG = 'eventon-lite';

    private ?EventonSettings $settings = null;

    private ?EventonScope $scope = null;


    public function slug(): string
    {
        return self::SLUG;
    }


    public function name(): string
    {
        return 'EventON Lite';
    }


    /**
     * Installed when the plugin's files are present or `ajde_events` posts exist; selectable when the plugin is
     * active (there is no table to check). The version is the plugin's, or the one EventON last recorded
     * (`eventon_plugin_version`) when the plugin has been removed.
     *
     * @throws MigrationRecordException When a read fails.
     */
    protected function detectSource(): SourceDetection
    {
        $plugin   = $this->pluginState(EventonSchema::PLUGIN_FILE);
        $has_data = $this->countRows(
            'SELECT COUNT(*) FROM ' . $this->table('posts') . ' WHERE post_type = %s',
            [EventonSchema::POST_TYPE_EVENT]
        ) > 0;
        $version  = $plugin['version'] !== '' ? $plugin['version'] : ($has_data ? $this->settings()->version() : '');
        $reason   = '';
        if ($plugin['installed'] && ! $plugin['active']) {
            $reason = $this->notActiveReason();
        } elseif (! $plugin['installed'] && $has_data) {
            $reason = $this->notInstalledReason();
        }
        return new SourceDetection(
            $plugin['installed'] || $has_data,
            $plugin['active'],
            $version,
            in_array($version, $this->supportedVersions(), true),
            $reason
        );
    }


    public function capabilities(): SourceCapabilities
    {
        return new SourceCapabilities([SourceCapabilities::DOMAIN_EVENTS], true, TimezoneModel::WALL_CLOCK_AS_UTC);
    }


    public function supportedVersions(): array
    {
        return ['2.5.10'];
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function inventory(MigrationOptions $options): SourceInventory
    {
        return (new EventonInventory($this->database(), $this->scope()))->build($options, $this->detect()->version());
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
        return (new EventonEventReader($this->database(), $this->settings(), $this->scope()))->read($source_event_id);
    }


    /**
     * EventON Lite has no bookings.
     */
    public function readBookings(string $source_event_id, int $offset, int $limit): array
    {
        return [];
    }


    public function statusTable(): StatusTable
    {
        return EventonStatusTable::table();
    }


    public function customFieldDefinitions(): array
    {
        return [];
    }


    private function settings(): EventonSettings
    {
        if (! $this->settings instanceof EventonSettings) {
            $this->settings = new EventonSettings($this->database());
        }
        return $this->settings;
    }


    private function scope(): EventonScope
    {
        if (! $this->scope instanceof EventonScope) {
            $this->scope = new EventonScope($this->database(), $this->settings());
        }
        return $this->scope;
    }
}
// End of file EventonSourceAdapter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventon_lite/EventonSourceAdapter.php
