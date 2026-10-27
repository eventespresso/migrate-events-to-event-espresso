<?php

namespace EventEspresso\EventMigrator\application\services\migration;

/**
 * Class TimezoneModel
 *
 * The four ways the supported source plugins store date/times. Each
 * SourceDateTime names its model so the TimezoneNormalizer can convert it to
 * UTC without knowing which plugin it came from.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class TimezoneModel
{
    /**
     * The stored value is already UTC (Amelia, Events Manager UTC columns, TEC/Event Genius UTC metas).
     */
    public const UTC = 'utc';

    /**
     * The stored value is local time in the site timezone (EME, EventPrime, WP Event Manager, ThimPress).
     */
    public const SITE_LOCAL = 'site_local';

    /**
     * The stored value is local time in a timezone recorded on the event (TEC, Eventin, Event Genius).
     */
    public const PER_EVENT = 'per_event';

    /**
     * The stored value is a unix timestamp whose UTC wall-clock digits are really site-local time (EventON).
     */
    public const WALL_CLOCK_AS_UTC = 'wall_clock_as_utc';


    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::UTC,
            self::SITE_LOCAL,
            self::PER_EVENT,
            self::WALL_CLOCK_AS_UTC,
        ];
    }


    public static function isValid(string $model): bool
    {
        return in_array($model, self::all(), true);
    }
}
// End of file TimezoneModel.php
// Location: EventEspresso\EventMigrator\application\services\migration/TimezoneModel.php
