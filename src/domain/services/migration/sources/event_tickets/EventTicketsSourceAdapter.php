<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\event_tickets;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDatetimeRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceEventRecord;
use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;
use EventEspresso\EventMigrator\application\services\migration\SourceCapabilities;
use EventEspresso\EventMigrator\application\services\migration\SourceDetection;
use EventEspresso\EventMigrator\application\services\migration\SourceExclusions;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterBase;
use EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar\TheEventsCalendarEventReader;

/**
 * Class EventTicketsSourceAdapter
 *
 * Migrates tickets, orders, attendees and payments from Event Tickets
 * (StellarWP) 5.30.1 (Tickets Commerce, and RSVPs of the current "v2"
 * version), together with the Events Calendar events they are for: an event is
 * read the same way the Events Calendar adapter reads it
 * (TheEventsCalendarEventReader) and gets its real tickets instead of the cost
 * text. Only tickets on `tribe_events` posts are migrated; tickets on other
 * post types are reported as excluded (SourceExclusions). Running this
 * adapter and the Events Calendar's on the same site creates the events twice.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventTicketsSourceAdapter extends SourceAdapterBase implements SourceExclusions
{
    public const SLUG = 'event-tickets';

    private ?EventTicketsScope $scope = null;

    private ?EventTicketsTicketReader $tickets = null;


    public function slug(): string
    {
        return self::SLUG;
    }


    public function name(): string
    {
        return 'Event Tickets';
    }


    /**
     * Installed when the plugin's files or Tickets Commerce tickets are present; selectable when the plugin is
     * active. The plugin's own post types are the data check.
     *
     * @throws MigrationRecordException When a read fails.
     */
    protected function detectSource(): SourceDetection
    {
        $plugin   = $this->pluginState(EventTicketsSchema::PLUGIN_FILE);
        $has_data = $this->countRows(
            'SELECT COUNT(*) FROM ' . $this->table('posts') . ' WHERE post_type = %s',
            [EventTicketsSchema::POST_TYPE_TICKET]
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
            [
                SourceCapabilities::DOMAIN_EVENTS,
                SourceCapabilities::DOMAIN_BOOKINGS,
                SourceCapabilities::DOMAIN_PAYMENTS,
                SourceCapabilities::DOMAIN_CHECKINS,
            ],
            false,
            TimezoneModel::PER_EVENT
        );
    }


    public function supportedVersions(): array
    {
        return ['5.30.1'];
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function inventory(MigrationOptions $options): SourceInventory
    {
        return (new EventTicketsInventory($this->database(), $this->scope()))
            ->build($options, $this->pluginState(EventTicketsSchema::PLUGIN_FILE)['version']);
    }


    /**
     * @throws MigrationRecordException When the read fails.
     */
    public function eventIds(MigrationOptions $options): iterable
    {
        return $this->scope()->eventIds($options);
    }


    /**
     * The event with its Tickets Commerce tickets in place of the cost text; a shared capacity becomes its
     * date's capacity, sale prices are kept as text.
     *
     * @throws MigrationRecordException When the event does not exist or a read fails.
     */
    public function readEvent(string $source_event_id, MigrationOptions $options): SourceEventRecord
    {
        $event   = (new TheEventsCalendarEventReader($this->database()))->read($source_event_id);
        $tickets = $this->tickets()->read((int) $source_event_id);
        $copy    = $event;
        if ($tickets['records'] !== []) {
            $copy = $copy->withTickets($tickets['records']);
        }
        if ($tickets['capacity'] !== null) {
            $copy = $copy->withDatetimes(
                array_map(
                    static fn(SourceDatetimeRecord $datetime): SourceDatetimeRecord => new SourceDatetimeRecord(
                        $datetime->sourceId(),
                        $datetime->start(),
                        $datetime->end(),
                        ['name' => $datetime->name(), 'description' => $datetime->description(), 'capacity' => $tickets['capacity']]
                    ),
                    $copy->datetimes()
                )
            );
        }
        return $tickets['unmapped'] === [] ? $copy : $copy->withUnmapped(array_merge($copy->unmapped(), $tickets['unmapped']));
    }


    /**
     * @throws MigrationRecordException When a read fails.
     */
    public function readBookings(string $source_event_id, int $offset, int $limit): array
    {
        return (new EventTicketsBookingReader($this->database()))->read($source_event_id, $offset, $limit);
    }


    public function statusTable(): StatusTable
    {
        return EventTicketsStatusTable::table();
    }


    public function customFieldDefinitions(): array
    {
        return [];
    }


    /**
     * One domain-excluded warning for each post that is not an event but has tickets.
     *
     * @return MigrationWarning[]
     * @throws MigrationRecordException When the read fails.
     */
    public function exclusions(MigrationOptions $options): array
    {
        return array_map(
            fn(string $host_id): MigrationWarning => $this->warn(WarningRule::DOMAIN_EXCLUDED, 'post', $host_id),
            $this->scope()->excludedHostIds()
        );
    }


    private function scope(): EventTicketsScope
    {
        if (! $this->scope instanceof EventTicketsScope) {
            $this->scope = new EventTicketsScope($this->database());
        }
        return $this->scope;
    }


    private function tickets(): EventTicketsTicketReader
    {
        if (! $this->tickets instanceof EventTicketsTicketReader) {
            $this->tickets = new EventTicketsTicketReader($this->database(), $this->scope());
        }
        return $this->tickets;
    }
}
// End of file EventTicketsSourceAdapter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\event_tickets/EventTicketsSourceAdapter.php
