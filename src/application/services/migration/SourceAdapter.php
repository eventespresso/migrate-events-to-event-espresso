<?php

namespace EventEspresso\EventMigrator\application\services\migration;

use EventEspresso\EventMigrator\application\services\migration\records\SourceBookingRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceCustomFieldRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceEventRecord;
use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;

/**
 * Interface SourceAdapter
 *
 * The contract every source plugin adapter implements (REQ-023). An adapter
 * reads its plugin's tables and post meta directly (REQ-102) into the common
 * record shape; it never writes, never calls the source plugin's PHP API, and
 * never applies Event Espresso mapping rules.
 *
 * Source identifiers are always strings: sources use ints, post ids and slugs
 * interchangeably and the crosswalk stores them as text.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
interface SourceAdapter
{
    /**
     * Stable lower-case slug used in URLs, options and crosswalk keys, e.g. "events-manager".
     */
    public function slug(): string;


    /**
     * The source plugin's display name, untranslated.
     */
    public function name(): string;


    /**
     * Whether the source plugin is installed/active and which version, by exact
     * table or post-type name (REQ-103), never by prefix.
     */
    public function detect(): SourceDetection;


    /**
     * What the source can supply: its data domains, whether it has recurrence, and its timezone model.
     *
     * @since 0.0.1.000
     */
    public function capabilities(): SourceCapabilities;


    /**
     * The versions this adapter was profiled against; others migrate with a
     * schema-unverified warning.
     *
     * @return string[]
     */
    public function supportedVersions(): array;


    /**
     * Pre-flight counts for the chosen options (REQ-003), honouring the horizon.
     */
    public function inventory(MigrationOptions $options): SourceInventory;


    /**
     * The source event ids to migrate, in a stable order, honouring the horizon
     * and excluding trashed records (REQ-004, REQ-025).
     *
     * @return iterable<string>
     */
    public function eventIds(MigrationOptions $options): iterable;


    /**
     * One source event with its dates, venue, categories, tickets, unmapped
     * concepts and series id.
     */
    public function readEvent(string $source_event_id, MigrationOptions $options): SourceEventRecord;


    /**
     * A page of the event's bookings, each with seats, attendee, payments,
     * answers and check-ins, in a stable order.
     *
     * @return SourceBookingRecord[]
     */
    public function readBookings(string $source_event_id, int $offset, int $limit): array;


    /**
     * The raw-status => EE-status maps for this source.
     */
    public function statusTable(): StatusTable;


    /**
     * The custom registration fields the source defines (REQ-019).
     *
     * @return SourceCustomFieldRecord[]
     */
    public function customFieldDefinitions(): array;
}
// End of file SourceAdapter.php
// Location: EventEspresso\EventMigrator\application\services\migration/SourceAdapter.php
