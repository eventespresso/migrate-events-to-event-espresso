<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventprime;

/**
 * Class EventPrimeSchema
 *
 * The names EventPrime stores its data under, from version 4.3.5.1 (research
 * profile §2). Events and bookings are posts, venues, organizers and event
 * types are terms with term meta, tickets are rows of `em_price_options`.
 * That table's `em_` prefix is shared with Events Manager's `em_*` tables
 * (REQ-103): EventPrime is recognised by its own post types and by
 * `em_price_options` together with `eventprime_ticket_categories`, and the
 * Events Manager adapter looks for names EventPrime never creates.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventPrimeSchema
{
    public const PLUGIN_FILE = 'eventprime-event-calendar-management/event-prime.php';

    /**
     * The option holding the version EventPrime last migrated its data to.
     */
    public const VERSION_OPTION = 'emagic_db_version';

    public const SETTINGS_OPTION = 'em_global_settings';

    public const TICKETS = 'em_price_options';

    public const TICKET_CATEGORIES = 'eventprime_ticket_categories';

    public const CHECKOUT_FIELDS = 'eventprime_checkout_fields';

    public const POST_TYPE_EVENT = 'em_event';

    public const POST_TYPE_BOOKING = 'em_booking';

    public const POST_TYPE_PERFORMER = 'em_performer';

    public const TAXONOMY_TYPE = 'em_event_type';

    public const TAXONOMY_VENUE = 'em_venue';

    public const TAXONOMY_ORGANIZER = 'em_event_organizer';

    /**
     * Source event ids of a recurring series start with this prefix and carry the parent event's post id.
     */
    public const SERIES_PREFIX = 'series:';

    /**
     * Post statuses that are never migrated.
     */
    public const EXCLUDED_POST_STATUSES = ['trash', 'auto-draft', 'inherit'];

    /**
     * Event post meta the readers use.
     *
     * @return string[]
     */
    public static function eventMetaKeys(): array
    {
        return [
            '_thumbnail_id',
            'em_start_date_time',
            'em_end_date_time',
            'em_all_day',
            'em_event_type',
            'em_venue',
            'em_organizer',
            'em_performer',
            'em_event_add_more_dates',
            'em_custom_link',
            'em_fixed_event_price',
            'em_enable_recurrence',
        ];
    }


    /**
     * The tables a usable EventPrime installation has.
     *
     * @return string[]
     */
    public static function requiredTables(): array
    {
        return [self::TICKETS, self::TICKET_CATEGORIES, self::CHECKOUT_FIELDS];
    }
}
// End of file EventPrimeSchema.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventprime/EventPrimeSchema.php
