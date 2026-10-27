<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceAdapter;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;

/**
 * Class HorizonExclusion
 *
 * How many source events the chosen time horizon leaves out (REQ-004): the events in scope with "all events"
 * minus the events in scope with the chosen horizon, both from the adapter's own inventory, so every adapter
 * answers it without code of its own.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       0.0.1.000
 */
final class HorizonExclusion
{
    /**
     * @param SourceAdapter        $adapter   The source.
     * @param MigrationOptions     $options   The run options.
     * @param SourceInventory|null $inventory The inventory already taken with $options, to save one read.
     * @return int Events excluded by the horizon; 0 for "all events".
     * @throws MigrationRecordException When an inventory read fails.
     */
    public static function count(SourceAdapter $adapter, MigrationOptions $options, ?SourceInventory $inventory = null): int
    {
        if ($options->isAllEvents()) {
            return 0;
        }
        $all      = MigrationOptions::fromArray(
            array_merge($options->toArray(), ['horizon' => MigrationOptions::HORIZON_ALL])
        );
        $in_scope = ($inventory ?? $adapter->inventory($options))->count(SourceInventory::COUNT_EVENTS);
        return max(0, $adapter->inventory($all)->count(SourceInventory::COUNT_EVENTS) - $in_scope);
    }
}
// End of file HorizonExclusion.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources/HorizonExclusion.php
