<?php

namespace EventEspresso\EventMigrator\application\services\migration;

/**
 * Class SourceInventory
 *
 * The pre-flight counts shown before anything is written (REQ-003), plus the
 * domains the source cannot provide and the concepts that will not migrate.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceInventory
{
    public const COUNT_EVENTS      = 'events';

    public const COUNT_OCCURRENCES = 'occurrences';

    public const COUNT_VENUES      = 'venues';

    public const COUNT_CATEGORIES  = 'categories';

    public const COUNT_TICKETS     = 'tickets';

    public const COUNT_ATTENDEES   = 'attendees';

    public const COUNT_BOOKINGS    = 'bookings';

    public const COUNT_PAYMENTS    = 'payments';

    private array $counts;

    private array $not_available;

    private array $not_migrated;

    private string $version;

    private string $timezone_model;


    /**
     * @param array    $counts         COUNT_* key => int; keys the source cannot count are omitted.
     * @param string[] $not_available  COUNT_* keys the source has no data for ("not available in this source").
     * @param string[] $not_migrated   Translated names of source concepts that will not be migrated.
     * @param string   $version        The source plugin version.
     * @param string   $timezone_model A TimezoneModel constant.
     */
    public function __construct(
        array $counts,
        array $not_available,
        array $not_migrated,
        string $version,
        string $timezone_model
    ) {
        $this->counts = [];
        foreach (self::countKeys() as $key) {
            if (array_key_exists($key, $counts)) {
                $this->counts[ $key ] = (int) $counts[ $key ];
            }
        }
        $this->not_available  = array_values(array_intersect(self::countKeys(), array_map('strval', $not_available)));
        $this->not_migrated   = array_map('strval', array_values($not_migrated));
        $this->version        = $version;
        $this->timezone_model = $timezone_model;
    }


    /**
     * @return string[]
     */
    public static function countKeys(): array
    {
        return [
            self::COUNT_EVENTS,
            self::COUNT_OCCURRENCES,
            self::COUNT_VENUES,
            self::COUNT_CATEGORIES,
            self::COUNT_TICKETS,
            self::COUNT_ATTENDEES,
            self::COUNT_BOOKINGS,
            self::COUNT_PAYMENTS,
        ];
    }


    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }


    public function count(string $key): int
    {
        return $this->counts[ $key ] ?? 0;
    }


    /**
     * @return string[]
     */
    public function notAvailable(): array
    {
        return $this->not_available;
    }


    public function isAvailable(string $key): bool
    {
        return ! in_array($key, $this->not_available, true);
    }


    /**
     * @return string[] Translated names of the source concepts that will not be migrated.
     */
    public function notMigrated(): array
    {
        return $this->not_migrated;
    }


    public function version(): string
    {
        return $this->version;
    }


    public function timezoneModel(): string
    {
        return $this->timezone_model;
    }


    public function isEmpty(): bool
    {
        return array_sum($this->counts) === 0;
    }
}
// End of file SourceInventory.php
// Location: EventEspresso\EventMigrator\application\services\migration/SourceInventory.php
