<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\wp_events_manager;

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
 * Class WpEventsManagerSourceAdapter
 *
 * Migrates from WP Events Manager (ThimPress), version 2.2.5 as profiled in
 * research/sources/wp-events-manager.md. Events (`tp_event`) and bookings
 * (`event_auth_book`) are posts with no custom tables; an event has one
 * local date range, one price and one seat count; a booking is one row for
 * several seats made by a WordPress user, with its status as the post status
 * and its payment folded into the booking.
 *
 * The adapter only selects which rows to read; the reader classes in this
 * directory read them, all through one SourceDatabase.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class WpEventsManagerSourceAdapter extends SourceAdapterBase
{
    public const SLUG = 'wp-events-manager';

    private ?WpEventsManagerScope $scope = null;


    public function slug(): string
    {
        return self::SLUG;
    }


    public function name(): string
    {
        return 'WP Events Manager';
    }


    /**
     * Installed when the plugin's files are present or `tp_event` posts exist; selectable when the plugin is
     * active (there is no table to check). The version is the plugin's header version only: the plugin's
     * `thimpress-event-version` option is a schema version and its `WPEMS_VER` constant lags the header.
     *
     * @throws MigrationRecordException When a read fails.
     */
    protected function detectSource(): SourceDetection
    {
        $plugin   = $this->pluginState(WpEventsManagerSchema::PLUGIN_FILE);
        $has_data = $this->countRows(
            'SELECT COUNT(*) FROM ' . $this->table('posts') . ' WHERE post_type = %s',
            [WpEventsManagerSchema::POST_TYPE_EVENT]
        ) > 0;
        $reason   = '';
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
        return new SourceCapabilities(
            [SourceCapabilities::DOMAIN_EVENTS, SourceCapabilities::DOMAIN_BOOKINGS, SourceCapabilities::DOMAIN_PAYMENTS],
            false,
            TimezoneModel::SITE_LOCAL
        );
    }


    public function supportedVersions(): array
    {
        return ['2.2.5'];
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function inventory(MigrationOptions $options): SourceInventory
    {
        return (new WpEventsManagerInventory($this->database(), $this->scope()))->build($options, $this->detect()->version());
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
        return (new WpEventsManagerEventReader($this->database()))->read($source_event_id);
    }


    /**
     * @throws MigrationRecordException When a read fails.
     */
    public function readBookings(string $source_event_id, int $offset, int $limit): array
    {
        return (new WpEventsManagerBookingReader($this->database()))->read($source_event_id, $offset, $limit);
    }


    public function statusTable(): StatusTable
    {
        return WpEventsManagerStatusTable::table();
    }


    public function customFieldDefinitions(): array
    {
        return [];
    }


    private function scope(): WpEventsManagerScope
    {
        if (! $this->scope instanceof WpEventsManagerScope) {
            $this->scope = new WpEventsManagerScope($this->database());
        }
        return $this->scope;
    }
}
// End of file WpEventsManagerSourceAdapter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\wp_events_manager/WpEventsManagerSourceAdapter.php
