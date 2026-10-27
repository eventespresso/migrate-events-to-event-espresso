<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventin;

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
 * Class EventinSourceAdapter
 *
 * Migrates from Eventin (wp-event-solution, Themewinter), version 4.1.26 as
 * profiled in research/sources/eventin.md. Events are `etn` posts with local
 * dates and a timezone of their own in meta, the venue is an address embedded
 * in the event, tickets are serialized variations identified by slug, orders
 * and attendees are posts (the order type is unregistered), and a recurring
 * series is a parent post with a child post per date.
 *
 * The adapter only selects which rows to read; the reader classes in this
 * directory read them, all through one SourceDatabase.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventinSourceAdapter extends SourceAdapterBase
{
    public const SLUG = 'eventin';

    private ?EventinScope $scope = null;

    private ?EventinSettings $settings = null;


    public function slug(): string
    {
        return self::SLUG;
    }


    public function name(): string
    {
        return 'Eventin';
    }


    /**
     * Installed when the plugin's files are present or `etn` posts exist; selectable when the plugin is active.
     * Eventin has no table that every install has, so the version is the plugin's, or the one it last migrated
     * its data to (`etn_db_migration`) when the plugin has been removed.
     *
     * @throws MigrationRecordException When a read fails.
     */
    protected function detectSource(): SourceDetection
    {
        $plugin   = $this->pluginState(EventinSchema::PLUGIN_FILE);
        $has_data = $this->hasEvents();
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
        return ['4.1.26'];
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function inventory(MigrationOptions $options): SourceInventory
    {
        return (new EventinInventory($this->database(), $this->scope()))->build($options, $this->detect()->version());
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
        return (new EventinEventReader($this->database(), $this->scope(), new EventinTicketReader()))->read($source_event_id);
    }


    /**
     * @throws MigrationRecordException When a read fails.
     */
    public function readBookings(string $source_event_id, int $offset, int $limit): array
    {
        return (new EventinBookingReader($this->database(), $this->scope(), $this->settings()))
            ->read($source_event_id, $offset, $limit);
    }


    public function statusTable(): StatusTable
    {
        return EventinStatusTable::table();
    }


    /**
     * The extra attendee fields defined globally and on events.
     *
     * @throws MigrationRecordException When a read fails.
     */
    public function customFieldDefinitions(): array
    {
        return (new EventinCustomFields($this->database(), $this->settings()))->definitions();
    }


    /**
     * @throws MigrationRecordException
     */
    private function hasEvents(): bool
    {
        return $this->countRows(
            'SELECT COUNT(*) FROM ' . $this->table('posts') . ' WHERE post_type = %s',
            [EventinSchema::POST_TYPE_EVENT]
        ) > 0;
    }


    private function scope(): EventinScope
    {
        if (! $this->scope instanceof EventinScope) {
            $this->scope = new EventinScope($this->database());
        }
        return $this->scope;
    }


    private function settings(): EventinSettings
    {
        if (! $this->settings instanceof EventinSettings) {
            $this->settings = new EventinSettings($this->database());
        }
        return $this->settings;
    }
}
// End of file EventinSourceAdapter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventin/EventinSourceAdapter.php
