<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_manager;

/**
 * Class EventsManagerSchema
 *
 * The names Events Manager (Pixelite) stores its data under, from
 * em-install.php and em-posts.php of version 7.4.8. Exact names only: the
 * `em_` prefix is shared with EventPrime (`em_price_options`), so nothing is
 * ever matched by prefix (REQ-103).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventsManagerSchema
{
    public const PLUGIN_FILE = 'events-manager/events-manager.php';

    public const VERSION_OPTION = 'dbem_version';

    public const CURRENCY_OPTION = 'dbem_bookings_currency';

    public const EVENTS = 'em_events';

    public const RECURRENCES = 'em_event_recurrences';

    public const TIMESLOTS = 'em_event_timeslots';

    public const LOCATIONS = 'em_locations';

    public const TICKETS = 'em_tickets';

    public const BOOKINGS = 'em_bookings';

    public const BOOKINGS_META = 'em_bookings_meta';

    public const TICKETS_BOOKINGS = 'em_tickets_bookings';

    /**
     * Events Manager Pro's payments table; absent from the free plugin.
     */
    public const TRANSACTIONS = 'em_transactions';

    public const POST_TYPE_EVENT = 'event';

    public const TAXONOMY_CATEGORY = 'event-categories';

    public const TAXONOMY_TAG = 'event-tags';

    /**
     * em_events.event_type values.
     */
    public const TYPE_SINGLE = 'single';

    public const TYPE_RECURRING = 'recurring';

    public const TYPE_REPEATING = 'repeating';

    public const TYPE_RECURRENCE = 'recurrence';

    /**
     * Post statuses that are never migrated.
     */
    public const EXCLUDED_POST_STATUSES = ['trash', 'auto-draft', 'inherit'];

    /**
     * The guest identity Events Manager keeps in em_bookings_meta (`_registration|<field>`).
     */
    public const GUEST_META_PREFIX = '_registration|';

    /**
     * The tables a usable Events Manager installation has.
     *
     * @return string[]
     */
    public static function requiredTables(): array
    {
        return [
            self::EVENTS,
            self::RECURRENCES,
            self::LOCATIONS,
            self::TICKETS,
            self::BOOKINGS,
            self::BOOKINGS_META,
            self::TICKETS_BOOKINGS,
        ];
    }


    /**
     * The event types whose rows are dates (one EE datetime each).
     *
     * @return string[]
     */
    public static function occurrenceTypes(): array
    {
        return [self::TYPE_SINGLE, self::TYPE_RECURRENCE];
    }


    /**
     * The event types of a series parent (a template whose children are the dates).
     *
     * @return string[]
     */
    public static function seriesTypes(): array
    {
        return [self::TYPE_RECURRING, self::TYPE_REPEATING];
    }
}
// End of file EventsManagerSchema.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_manager/EventsManagerSchema.php
