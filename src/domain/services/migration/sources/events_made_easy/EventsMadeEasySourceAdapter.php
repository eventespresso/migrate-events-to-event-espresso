<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\records\SourceCustomFieldRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceEventRecord;
use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;
use EventEspresso\EventMigrator\application\services\migration\SourceCapabilities;
use EventEspresso\EventMigrator\application\services\migration\SourceDetection;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterBase;

/**
 * Class EventsMadeEasySourceAdapter
 *
 * Migrates from Events Made Easy (EME), version 3.2.22 as profiled in
 * research/sources/events-made-easy.md. EME keeps everything in its own `eme_*`
 * tables: an event is a row (a recurrence is one row per date), its price and
 * seats are text on the event (single or `||` multi-price), bookings hold seats
 * per price slot, and a booking carries its own payment. Dates are naive
 * local times.
 *
 * The adapter only selects which rows to read; the reader classes in this
 * directory read them, all through one SourceDatabase.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventsMadeEasySourceAdapter extends SourceAdapterBase
{
    public const SLUG = 'events-made-easy';

    private ?EventsMadeEasyScope $scope = null;


    public function slug(): string
    {
        return self::SLUG;
    }


    public function name(): string
    {
        return 'Events Made Easy';
    }


    /**
     * Installed when the plugin's files or its events table are present; selectable when the plugin is active
     * and all of its tables exist. The plugin keeps no version of its own in the database (`eme_version` is the
     * schema version), so the version is the plugin's header version only.
     *
     * @throws MigrationRecordException When a read fails.
     */
    protected function detectSource(): SourceDetection
    {
        $plugin  = $this->pluginState(EventsMadeEasySchema::PLUGIN_FILE);
        $tables  = $this->database()->tablesExist(EventsMadeEasySchema::requiredTables());
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
            $plugin['installed'] || $this->tableExists(EventsMadeEasySchema::EVENTS),
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
                SourceCapabilities::DOMAIN_CHECKINS,
                SourceCapabilities::DOMAIN_CUSTOM_FIELDS,
            ],
            true,
            TimezoneModel::SITE_LOCAL
        );
    }


    public function supportedVersions(): array
    {
        return ['3.2.22'];
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function inventory(MigrationOptions $options): SourceInventory
    {
        return (new EventsMadeEasyInventory($this->database(), $this->scope()))->build($options, $this->detect()->version());
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
        return (new EventsMadeEasyEventReader($this->database(), $this->scope()))->read($source_event_id);
    }


    /**
     * @throws MigrationRecordException When a read fails.
     */
    public function readBookings(string $source_event_id, int $offset, int $limit): array
    {
        return (new EventsMadeEasyBookingReader($this->database(), $this->scope()))->read($source_event_id, $offset, $limit);
    }


    public function statusTable(): StatusTable
    {
        return EventsMadeEasyStatusTable::table();
    }


    /**
     * The booking comment plus the booking-form fields defined on the site.
     *
     * @throws MigrationRecordException When a read fails.
     */
    public function customFieldDefinitions(): array
    {
        $fields = [
            new SourceCustomFieldRecord(
                EventsMadeEasySchema::COMMENT_FIELD,
                __('Booking comment', 'migrate-events-to-event-espresso'),
                'textarea'
            ),
        ];
        return array_merge($fields, (new EventsMadeEasyCustomFields($this->database()))->definitions());
    }


    private function scope(): EventsMadeEasyScope
    {
        if (! $this->scope instanceof EventsMadeEasyScope) {
            $this->scope = new EventsMadeEasyScope($this->database());
        }
        return $this->scope;
    }
}
// End of file EventsMadeEasySourceAdapter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy/EventsMadeEasySourceAdapter.php
