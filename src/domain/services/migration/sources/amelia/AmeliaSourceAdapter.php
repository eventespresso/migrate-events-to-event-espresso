<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\amelia;

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
 * Class AmeliaSourceAdapter
 *
 * Migrates from Amelia (ameliabooking, TMS), version 2.4.11 as profiled in
 * research/sources/amelia.md. Amelia keeps events in its own tables: an event
 * (one row per occurrence, a series grouped by parentId) with its periods in
 * UTC, optional custom tickets, customer bookings linked to periods, and
 * payments; customers are Amelia users, with the WordPress user id when they
 * have an account. Appointments and services are not events and are ignored.
 *
 * The adapter only selects which rows to read; the reader classes in this
 * directory read them, all through one SourceDatabase.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class AmeliaSourceAdapter extends SourceAdapterBase
{
    public const SLUG = 'amelia';

    private ?AmeliaScope $scope = null;

    private ?AmeliaTicketReader $tickets = null;

    private ?AmeliaSettings $settings = null;


    public function slug(): string
    {
        return self::SLUG;
    }


    public function name(): string
    {
        return 'Amelia';
    }


    /**
     * Installed when the plugin's files or its events table are present; selectable when the plugin is active
     * and all of the tables events need exist. The version is the plugin's, or the one Amelia last recorded in
     * its settings when the plugin has been removed.
     *
     * @throws MigrationRecordException When a read fails.
     */
    protected function detectSource(): SourceDetection
    {
        $plugin  = $this->pluginState(AmeliaSchema::PLUGIN_FILE);
        $tables  = $this->database()->tablesExist(AmeliaSchema::requiredTables());
        $version = $plugin['version'] !== '' ? $plugin['version'] : ($tables ? $this->settings()->version() : '');
        $reason  = '';
        if ($plugin['installed'] && ! $plugin['active']) {
            $reason = $this->notActiveReason();
        } elseif ($plugin['active'] && ! $tables) {
            $reason = $this->missingTablesReason();
        } elseif (! $plugin['installed'] && $tables) {
            $reason = $this->notInstalledReason();
        }
        return new SourceDetection(
            $plugin['installed'] || $this->tableExists(AmeliaSchema::EVENTS),
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
            TimezoneModel::UTC
        );
    }


    public function supportedVersions(): array
    {
        return ['2.4.11'];
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function inventory(MigrationOptions $options): SourceInventory
    {
        return (new AmeliaInventory($this->database()))->build($options, $this->detect()->version(), $this->scope());
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
        return (new AmeliaEventReader($this->database(), $this->scope(), $this->tickets()))->read($source_event_id);
    }


    /**
     * @throws MigrationRecordException When a read fails.
     */
    public function readBookings(string $source_event_id, int $offset, int $limit): array
    {
        return (new AmeliaBookingReader($this->database(), $this->scope(), $this->tickets(), $this->settings()))
            ->read($source_event_id, $offset, $limit);
    }


    public function statusTable(): StatusTable
    {
        return AmeliaStatusTable::table();
    }


    /**
     * The custom fields (a paid Amelia feature) defined on the site; none when the table is absent or empty.
     *
     * @throws MigrationRecordException When a read fails.
     */
    public function customFieldDefinitions(): array
    {
        return (new AmeliaCustomFields($this->database()))->definitions();
    }


    private function scope(): AmeliaScope
    {
        if (! $this->scope instanceof AmeliaScope) {
            $this->scope = new AmeliaScope($this->database());
        }
        return $this->scope;
    }


    private function tickets(): AmeliaTicketReader
    {
        if (! $this->tickets instanceof AmeliaTicketReader) {
            $this->tickets = new AmeliaTicketReader($this->database());
        }
        return $this->tickets;
    }


    private function settings(): AmeliaSettings
    {
        if (! $this->settings instanceof AmeliaSettings) {
            $this->settings = new AmeliaSettings($this->database());
        }
        return $this->settings;
    }
}
// End of file AmeliaSourceAdapter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\amelia/AmeliaSourceAdapter.php
