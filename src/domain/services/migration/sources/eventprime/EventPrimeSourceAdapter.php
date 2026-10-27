<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventprime;

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
 * Class EventPrimeSourceAdapter
 *
 * Migrates from EventPrime, version 4.3.5.1 as profiled in
 * research/sources/eventprime.md. Events (`em_event`) and bookings
 * (`em_booking`) are posts; venues, organizers and event types are terms with
 * term meta; tickets are `em_price_options` rows; dates are Unix timestamps;
 * a recurring event is a parent post with a child post per date.
 *
 * EventPrime's `em_price_options` table shares the `em_` prefix with Events
 * Manager (REQ-103). This adapter is detected by EventPrime's own plugin file
 * and by `em_price_options` together with `eventprime_ticket_categories` and
 * `eventprime_checkout_fields`; none of Events Manager's tables count.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventPrimeSourceAdapter extends SourceAdapterBase
{
    public const SLUG = 'eventprime';

    private ?EventPrimeScope $scope = null;

    private ?EventPrimeTicketReader $tickets = null;


    public function slug(): string
    {
        return self::SLUG;
    }


    public function name(): string
    {
        return 'EventPrime';
    }


    /**
     * Installed when the plugin's files are present or its ticket table exists; selectable when the plugin is
     * active and all three of its tables exist. The version is the plugin's, or the one EventPrime last migrated
     * its data to (`emagic_db_version`) when the plugin has been removed.
     *
     * @throws MigrationRecordException When a read fails.
     */
    protected function detectSource(): SourceDetection
    {
        $plugin  = $this->pluginState(EventPrimeSchema::PLUGIN_FILE);
        $tables  = $this->database()->tablesExist(EventPrimeSchema::requiredTables());
        $version = $plugin['version'] !== ''
            ? $plugin['version']
            : ($tables ? trim((string) $this->database()->option(EventPrimeSchema::VERSION_OPTION)) : '');
        $reason  = '';
        if ($plugin['installed'] && ! $plugin['active']) {
            $reason = $this->notActiveReason();
        } elseif ($plugin['active'] && ! $tables) {
            $reason = $this->missingTablesReason();
        } elseif (! $plugin['installed'] && $tables) {
            $reason = $this->notInstalledReason();
        }
        return new SourceDetection(
            $plugin['installed'] || $this->tableExists(EventPrimeSchema::TICKETS),
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
        return ['4.3.5.1'];
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function inventory(MigrationOptions $options): SourceInventory
    {
        return (new EventPrimeInventory($this->database(), $this->scope()))->build($options, $this->detect()->version());
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
        return (new EventPrimeEventReader($this->database(), $this->scope(), $this->tickets()))->read($source_event_id);
    }


    /**
     * @throws MigrationRecordException When a read fails.
     */
    public function readBookings(string $source_event_id, int $offset, int $limit): array
    {
        return (new EventPrimeBookingReader($this->database(), $this->scope()))->read($source_event_id, $offset, $limit);
    }


    public function statusTable(): StatusTable
    {
        return EventPrimeStatusTable::table();
    }


    /**
     * The checkout fields defined on the site.
     *
     * @throws MigrationRecordException When a read fails.
     */
    public function customFieldDefinitions(): array
    {
        return $this->tableExists(EventPrimeSchema::CHECKOUT_FIELDS)
            ? (new EventPrimeCustomFields($this->database()))->definitions()
            : [];
    }


    private function scope(): EventPrimeScope
    {
        if (! $this->scope instanceof EventPrimeScope) {
            $this->scope = new EventPrimeScope($this->database());
        }
        return $this->scope;
    }


    private function tickets(): EventPrimeTicketReader
    {
        if (! $this->tickets instanceof EventPrimeTicketReader) {
            $this->tickets = new EventPrimeTicketReader($this->database());
        }
        return $this->tickets;
    }
}
// End of file EventPrimeSourceAdapter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventprime/EventPrimeSourceAdapter.php
