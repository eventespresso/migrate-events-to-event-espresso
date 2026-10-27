<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_manager;

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
 * Class EventsManagerSourceAdapter
 *
 * Migrates from Events Manager (Pixelite, wp-events-plugin.com), version 7.4.8
 * as profiled in research/sources/events-manager.md: events and locations are
 * posts mirrored in em_events/em_locations, dates are stored in UTC and in the
 * event's own timezone, recurring and repeating series have one em_events row
 * per date, tickets live in em_tickets, bookings in em_bookings with one
 * em_tickets_bookings row per seat, and payments only exist with Events
 * Manager Pro (em_transactions).
 *
 * The adapter only selects which rows to read; the reader classes in this
 * directory read them, all through one SourceDatabase.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventsManagerSourceAdapter extends SourceAdapterBase
{
    public const SLUG = 'events-manager';

    private ?EventsManagerScope $scope = null;

    private ?EventsManagerTicketReader $tickets = null;


    public function slug(): string
    {
        return self::SLUG;
    }


    public function name(): string
    {
        return 'Events Manager';
    }


    /**
     * Installed when the plugin's files or its em_events table are present; selectable when the plugin is
     * active and all of its tables exist. EventPrime's em_price_options never counts: tables are checked by
     * exact name.
     *
     * @throws MigrationRecordException When a read fails.
     */
    protected function detectSource(): SourceDetection
    {
        $plugin  = $this->pluginState(EventsManagerSchema::PLUGIN_FILE);
        $tables  = $this->database()->tablesExist(EventsManagerSchema::requiredTables());
        $version = $plugin['version'] !== ''
            ? $plugin['version']
            : (string) ($this->tableExists('options') ? $this->database()->option(EventsManagerSchema::VERSION_OPTION) : '');
        $reason  = '';
        if ($plugin['installed'] && ! $plugin['active']) {
            $reason = $this->notActiveReason();
        } elseif ($plugin['active'] && ! $tables) {
            $reason = $this->missingTablesReason();
        } elseif (! $plugin['installed'] && $tables) {
            $reason = $this->notInstalledReason();
        }
        return new SourceDetection(
            $plugin['installed'] || $this->tableExists(EventsManagerSchema::EVENTS),
            $plugin['active'] && $tables,
            $version,
            $tables && in_array($version, $this->supportedVersions(), true),
            $reason
        );
    }


    /**
     * Payments are offered only when Events Manager Pro's em_transactions table exists.
     */
    public function capabilities(): SourceCapabilities
    {
        $domains = [
            SourceCapabilities::DOMAIN_EVENTS,
            SourceCapabilities::DOMAIN_BOOKINGS,
            SourceCapabilities::DOMAIN_CUSTOM_FIELDS,
        ];
        if ($this->tableExists(EventsManagerSchema::TRANSACTIONS)) {
            $domains[] = SourceCapabilities::DOMAIN_PAYMENTS;
        }
        return new SourceCapabilities($domains, true, TimezoneModel::PER_EVENT);
    }


    public function supportedVersions(): array
    {
        return ['7.4.8'];
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function inventory(MigrationOptions $options): SourceInventory
    {
        return (new EventsManagerInventory($this->database(), $this->scope()))->build($options, $this->detect()->version());
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
        return (new EventsManagerEventReader($this->database(), $this->scope(), $this->tickets()))->read($source_event_id);
    }


    /**
     * @throws MigrationRecordException When a read fails.
     */
    public function readBookings(string $source_event_id, int $offset, int $limit): array
    {
        return (new EventsManagerBookingReader($this->database(), $this->scope(), $this->tickets()))
            ->read($source_event_id, $offset, $limit);
    }


    public function statusTable(): StatusTable
    {
        return EventsManagerStatusTable::table();
    }


    /**
     * The free plugin's booking form has one free-text field of its own: the comment.
     */
    public function customFieldDefinitions(): array
    {
        return [
            new SourceCustomFieldRecord(
                EventsManagerBookingReader::COMMENT_FIELD,
                __('Booking comment', 'migrate-events-to-event-espresso'),
                'textarea'
            ),
        ];
    }


    private function scope(): EventsManagerScope
    {
        if (! $this->scope instanceof EventsManagerScope) {
            $this->scope = new EventsManagerScope($this->database());
        }
        return $this->scope;
    }


    private function tickets(): EventsManagerTicketReader
    {
        if (! $this->tickets instanceof EventsManagerTicketReader) {
            $this->tickets = new EventsManagerTicketReader($this->database());
        }
        return $this->tickets;
    }
}
// End of file EventsManagerSourceAdapter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_manager/EventsManagerSourceAdapter.php
