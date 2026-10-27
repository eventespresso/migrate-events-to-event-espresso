<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\event_genius;

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
 * Class EventGeniusSourceAdapter
 *
 * Migrates from Event Genius (free build), version 1.19 as profiled in
 * research/sources/event-genius.md. Events, venues and organizers are posts
 * with dates stored both local and UTC; a recurring series is a template
 * event plus a post per generated date; the price and capacity are on the
 * event; registrations (the main one and a row per extra guest) and their
 * offline payments are `evge_*` tables.
 *
 * The adapter only selects which rows to read; the reader classes in this
 * directory read them, all through one SourceDatabase.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventGeniusSourceAdapter extends SourceAdapterBase
{
    public const SLUG = 'event-genius';

    private ?EventGeniusScope $scope = null;


    public function slug(): string
    {
        return self::SLUG;
    }


    public function name(): string
    {
        return 'Event Genius';
    }


    /**
     * Installed when the plugin's files or its registrations table are present; selectable when the plugin is
     * active and all of its tables exist. The paid builds ship under other plugin file names and are not
     * detected here. The plugin keeps no plugin version in the database (`evge_db_version` is the schema version).
     *
     * @throws MigrationRecordException When a read fails.
     */
    protected function detectSource(): SourceDetection
    {
        $plugin  = $this->pluginState(EventGeniusSchema::PLUGIN_FILE);
        $tables  = $this->database()->tablesExist(EventGeniusSchema::requiredTables());
        $version = $plugin['version'];
        $reason  = '';
        if ($plugin['installed'] && ! $plugin['active']) {
            $reason = $this->notActiveReason();
        } elseif ($plugin['active'] && ! $tables) {
            $reason = $this->missingTablesReason();
        } elseif (! $plugin['installed'] && $tables) {
            $reason = $this->notInstalledReason();
        }
        return new SourceDetection(
            $plugin['installed'] || $this->tableExists(EventGeniusSchema::REGISTRATIONS),
            $plugin['active'] && $tables,
            $version,
            $tables && in_array($version, $this->supportedVersions(), true),
            $reason
        );
    }


    public function capabilities(): SourceCapabilities
    {
        return new SourceCapabilities(
            [
                SourceCapabilities::DOMAIN_EVENTS,
                SourceCapabilities::DOMAIN_BOOKINGS,
                SourceCapabilities::DOMAIN_PAYMENTS,
                SourceCapabilities::DOMAIN_CUSTOM_FIELDS,
            ],
            true,
            TimezoneModel::PER_EVENT
        );
    }


    public function supportedVersions(): array
    {
        return ['1.19'];
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function inventory(MigrationOptions $options): SourceInventory
    {
        return (new EventGeniusInventory($this->database(), $this->scope()))->build($options, $this->detect()->version());
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
        return (new EventGeniusEventReader($this->database(), $this->scope()))->read($source_event_id);
    }


    /**
     * @throws MigrationRecordException When a read fails.
     */
    public function readBookings(string $source_event_id, int $offset, int $limit): array
    {
        return (new EventGeniusBookingReader($this->database(), $this->scope()))->read($source_event_id, $offset, $limit);
    }


    public function statusTable(): StatusTable
    {
        return EventGeniusStatusTable::table();
    }


    /**
     * The registration form fields saved by the form builder; none when the form was never saved.
     *
     * @throws MigrationRecordException When a read fails.
     */
    public function customFieldDefinitions(): array
    {
        return (new EventGeniusCustomFields($this->database()))->definitions();
    }


    private function scope(): EventGeniusScope
    {
        if (! $this->scope instanceof EventGeniusScope) {
            $this->scope = new EventGeniusScope($this->database());
        }
        return $this->scope;
    }
}
// End of file EventGeniusSourceAdapter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\event_genius/EventGeniusSourceAdapter.php
