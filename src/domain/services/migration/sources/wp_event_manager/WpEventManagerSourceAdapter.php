<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\wp_event_manager;

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
 * Class WpEventManagerSourceAdapter
 *
 * Migrates events from WP Event Manager (wp-eventmanager.com), version 3.4.1 as
 * profiled in research/sources/wp-event-manager.md. Events, venues and
 * organizers are posts with `_`-prefixed meta, an event has one local date
 * range, and the ticket is only a free/paid option and a free-text price. The
 * free plugin has no bookings (registration and ticketing are paid add-ons,
 * out of scope): the adapter provides events only.
 *
 * The adapter only selects which rows to read; the reader classes in this
 * directory read them, all through one SourceDatabase.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class WpEventManagerSourceAdapter extends SourceAdapterBase
{
    public const SLUG = 'wp-event-manager';

    private ?WpEventManagerScope $scope = null;


    public function slug(): string
    {
        return self::SLUG;
    }


    public function name(): string
    {
        return 'WP Event Manager';
    }


    /**
     * Installed when the plugin's files are present or `event_listing` posts exist; selectable when the plugin is
     * active (there is no table to check). The version is the plugin's, or the one it last recorded
     * (`wp_event_manager_version`) when the plugin has been removed.
     *
     * @throws MigrationRecordException When a read fails.
     */
    protected function detectSource(): SourceDetection
    {
        $plugin   = $this->pluginState(WpEventManagerSchema::PLUGIN_FILE);
        $has_data = $this->countRows(
            'SELECT COUNT(*) FROM ' . $this->table('posts') . ' WHERE post_type = %s',
            [WpEventManagerSchema::POST_TYPE_EVENT]
        ) > 0;
        $version  = $plugin['version'] !== ''
            ? $plugin['version']
            : ($has_data ? trim((string) $this->database()->option(WpEventManagerSchema::VERSION_OPTION)) : '');
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
        return new SourceCapabilities([SourceCapabilities::DOMAIN_EVENTS], false, TimezoneModel::SITE_LOCAL);
    }


    public function supportedVersions(): array
    {
        return ['3.4.1'];
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function inventory(MigrationOptions $options): SourceInventory
    {
        return (new WpEventManagerInventory($this->database(), $this->scope()))->build($options, $this->detect()->version());
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
        return (new WpEventManagerEventReader($this->database()))->read($source_event_id);
    }


    /**
     * The free plugin has no bookings.
     */
    public function readBookings(string $source_event_id, int $offset, int $limit): array
    {
        return [];
    }


    public function statusTable(): StatusTable
    {
        return WpEventManagerStatusTable::table();
    }


    public function customFieldDefinitions(): array
    {
        return [];
    }


    private function scope(): WpEventManagerScope
    {
        if (! $this->scope instanceof WpEventManagerScope) {
            $this->scope = new WpEventManagerScope($this->database());
        }
        return $this->scope;
    }
}
// End of file WpEventManagerSourceAdapter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\wp_event_manager/WpEventManagerSourceAdapter.php
