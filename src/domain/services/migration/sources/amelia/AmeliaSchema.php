<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\amelia;

/**
 * Class AmeliaSchema
 *
 * The names Amelia (ameliabooking) stores its event data under, from its
 * install classes in version 2.4.11 (research profile §2). Amelia keeps
 * everything in its own `amelia_*` tables and uses no custom post type for
 * the data. Exact names only, because `customer_bookings` and `payments` are
 * shared with Amelia's appointments.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class AmeliaSchema
{
    public const PLUGIN_FILE = 'ameliabooking/ameliabooking.php';

    /**
     * The JSON option holding Amelia's settings (currency, and the version it last ran as).
     */
    public const SETTINGS_OPTION = 'amelia_settings';

    public const EVENTS = 'amelia_events';

    public const PERIODS = 'amelia_events_periods';

    public const TAGS = 'amelia_events_tags';

    public const TICKETS = 'amelia_events_to_tickets';

    public const BOOKINGS = 'amelia_customer_bookings';

    public const BOOKING_PERIODS = 'amelia_customer_bookings_to_events_periods';

    public const BOOKING_TICKETS = 'amelia_customer_bookings_to_events_tickets';

    public const PAYMENTS = 'amelia_payments';

    public const USERS = 'amelia_users';

    public const LOCATIONS = 'amelia_locations';

    public const CUSTOM_FIELDS = 'amelia_custom_fields';

    public const CUSTOM_FIELD_OPTIONS = 'amelia_custom_fields_options';

    /**
     * Source event ids of a recurring series start with this prefix and carry the series origin's id.
     */
    public const SERIES_PREFIX = 'series:';

    /**
     * Source ticket ids of the ticket an event gets from its price and capacity start with this prefix.
     */
    public const IMPLICIT_TICKET_PREFIX = 'event:';

    /**
     * The raw event status of a live event that is hidden from the front end (amelia_events.show = 0).
     */
    public const STATUS_HIDDEN = 'hidden';

    /**
     * Field types whose answers are never carried over (no text value).
     */
    public const SKIPPED_FIELD_TYPES = ['file', 'content'];

    /**
     * The tables a usable Amelia installation has for events and their bookings.
     *
     * @return string[]
     */
    public static function requiredTables(): array
    {
        return [
            self::EVENTS,
            self::PERIODS,
            self::TICKETS,
            self::BOOKINGS,
            self::BOOKING_PERIODS,
            self::BOOKING_TICKETS,
            self::PAYMENTS,
            self::USERS,
        ];
    }
}
// End of file AmeliaSchema.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\amelia/AmeliaSchema.php
