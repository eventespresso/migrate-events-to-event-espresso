<?php

namespace EventEspresso\EventMigrator\domain\services\migration\ui;

use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;

/**
 * Class InventoryRows
 *
 * The rows of the options step's pre-flight table (REQ-003): what the source
 * holds under the chosen options, an entity the source cannot supply shown as
 * "not available", and how the source stores times. Plain text; the template
 * escapes it.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class InventoryRows
{
    /**
     * @return array<int, array{key: string, label: string, count: int|null}> count is null when the source has no such records
     */
    public static function build(SourceInventory $inventory): array
    {
        $labels = self::labels();
        $rows   = [];
        foreach (SourceInventory::countKeys() as $key) {
            $available = $inventory->isAvailable($key);
            if (! $available || array_key_exists($key, $inventory->counts())) {
                $rows[] = [
                    'key'   => $key,
                    'label' => $labels[ $key ] ?? $key,
                    'count' => $available ? $inventory->count($key) : null,
                ];
            }
        }
        return $rows;
    }


    public static function timezoneLabel(string $timezone_model): string
    {
        $labels = [
            TimezoneModel::UTC        => __('Times are stored in UTC.', 'migrate-events-to-event-espresso'),
            TimezoneModel::SITE_LOCAL => __('Times are stored in the site timezone.', 'migrate-events-to-event-espresso'),
            TimezoneModel::PER_EVENT  => __('Each event stores its own timezone.', 'migrate-events-to-event-espresso'),
            TimezoneModel::WALL_CLOCK_AS_UTC => __(
                'Wall-clock times stored as UTC, read in the zone the event or site declares.',
                'migrate-events-to-event-espresso'
            ),
        ];
        return $labels[ $timezone_model ] ?? '';
    }


    /**
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return [
            SourceInventory::COUNT_EVENTS      => __('Events', 'migrate-events-to-event-espresso'),
            SourceInventory::COUNT_OCCURRENCES => __('Occurrences (recurring dates)', 'migrate-events-to-event-espresso'),
            SourceInventory::COUNT_VENUES      => __('Venues', 'migrate-events-to-event-espresso'),
            SourceInventory::COUNT_CATEGORIES  => __('Categories', 'migrate-events-to-event-espresso'),
            SourceInventory::COUNT_TICKETS     => __('Tickets', 'migrate-events-to-event-espresso'),
            SourceInventory::COUNT_ATTENDEES   => __('Contacts', 'migrate-events-to-event-espresso'),
            SourceInventory::COUNT_BOOKINGS    => __('Bookings', 'migrate-events-to-event-espresso'),
            SourceInventory::COUNT_PAYMENTS    => __('Payments', 'migrate-events-to-event-espresso'),
        ];
    }
}
// End of file InventoryRows.php
// Location: EventEspresso\EventMigrator\domain\services\migration\ui/InventoryRows.php
