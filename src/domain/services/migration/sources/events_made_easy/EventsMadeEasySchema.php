<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy;

/**
 * Class EventsMadeEasySchema
 *
 * The names Events Made Easy (EME) stores its data under, from version 3.2.22
 * (research profile §2). EME has no post type, taxonomy or post meta: events,
 * locations, people, bookings, payments and answers are `eme_*` tables, and
 * the plugin's main file is `events-manager.php` (not Events Manager's own
 * plugin, which lives in another directory). Exact names only.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventsMadeEasySchema
{
    public const PLUGIN_FILE = 'events-made-easy/events-manager.php';

    public const EVENTS = 'eme_events';

    public const RECURRENCE = 'eme_recurrence';

    public const LOCATIONS = 'eme_locations';

    public const CATEGORIES = 'eme_categories';

    public const PEOPLE = 'eme_people';

    public const BOOKINGS = 'eme_bookings';

    public const PAYMENTS = 'eme_payments';

    public const ANSWERS = 'eme_answers';

    public const FORMFIELDS = 'eme_formfields';

    public const ATTENDANCES = 'eme_attendances';

    /**
     * eme_events.event_status values: the trash (never migrated).
     */
    public const EVENT_TRASH = 0;

    /**
     * Source event ids of a recurrence start with this prefix and carry the recurrence id.
     */
    public const SERIES_PREFIX = 'series:';

    /**
     * Source ticket ids of an event's price slots start with this prefix and carry the migrated event id.
     */
    public const TICKET_PREFIX = 'event:';

    public const COMMENT_FIELD = 'booking_comment';

    /**
     * The separator EME puts between the values of a multi-price or multi-seat event.
     */
    public const MULTI = '||';

    /**
     * Form field purposes that are questions asked of the person booking.
     */
    public const BOOKING_PURPOSES = ['rsvp', 'people', 'generic'];

    /**
     * The tables a usable EME installation has.
     *
     * @return string[]
     */
    public static function requiredTables(): array
    {
        return [
            self::EVENTS,
            self::RECURRENCE,
            self::LOCATIONS,
            self::CATEGORIES,
            self::PEOPLE,
            self::BOOKINGS,
            self::PAYMENTS,
            self::ANSWERS,
            self::FORMFIELDS,
            self::ATTENDANCES,
        ];
    }
}
// End of file EventsMadeEasySchema.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy/EventsMadeEasySchema.php
