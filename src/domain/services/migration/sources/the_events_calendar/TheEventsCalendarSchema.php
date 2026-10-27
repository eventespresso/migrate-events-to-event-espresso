<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar;

/**
 * Class TheEventsCalendarSchema
 *
 * The names The Events Calendar (StellarWP) stores its data under, from
 * src/Tribe/Main.php and Venue.php/Organizer.php of version 6.18.1. Events,
 * venues and organizers are custom post types with `_Event*`, `_Venue*` and
 * `_Organizer*` meta; the Custom Tables v1 cache (`tec_events`,
 * `tec_occurrences`) is derived from that meta and is not read.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class TheEventsCalendarSchema
{
    public const PLUGIN_FILE = 'the-events-calendar/the-events-calendar.php';

    public const POST_TYPE_EVENT = 'tribe_events';

    public const POST_TYPE_VENUE = 'tribe_venue';

    public const POST_TYPE_ORGANIZER = 'tribe_organizer';

    public const TAXONOMY_CATEGORY = 'tribe_events_cat';

    public const TAXONOMY_TAG = 'post_tag';

    /**
     * Post statuses never migrated: trash, auto drafts, and events the Event Aggregator marks as ignored.
     */
    public const EXCLUDED_POST_STATUSES = ['trash', 'auto-draft', 'inherit', 'tribe-ignored'];

    public const META_START = '_EventStartDate';

    public const META_END = '_EventEndDate';

    public const META_START_UTC = '_EventStartDateUTC';

    public const META_END_UTC = '_EventEndDateUTC';

    public const META_ALL_DAY = '_EventAllDay';

    public const META_TIMEZONE = '_EventTimezone';

    public const META_VENUE = '_EventVenueID';

    public const META_ORGANIZER = '_EventOrganizerID';

    public const META_COST = '_EventCost';

    public const META_URL = '_EventURL';

    public const META_PHONE = '_EventPhone';

    public const META_STATUS = '_tribe_events_status';

    public const META_STATUS_REASON = '_tribe_events_status_reason';

    public const META_THUMBNAIL = '_thumbnail_id';

    /**
     * Event meta read for one event in a single query.
     *
     * @return string[]
     */
    public static function eventMetaKeys(): array
    {
        return [
            self::META_START,
            self::META_END,
            self::META_START_UTC,
            self::META_END_UTC,
            self::META_ALL_DAY,
            self::META_TIMEZONE,
            self::META_VENUE,
            self::META_COST,
            self::META_URL,
            self::META_PHONE,
            self::META_STATUS,
            self::META_STATUS_REASON,
            self::META_THUMBNAIL,
        ];
    }


    /**
     * @return string[]
     */
    public static function venueMetaKeys(): array
    {
        return [
            '_VenueAddress',
            '_VenueCity',
            '_VenueStateProvince',
            '_VenueState',
            '_VenueProvince',
            '_VenueZip',
            '_VenueCountry',
            '_VenuePhone',
            '_VenueURL',
            '_VenueLat',
            '_VenueLng',
        ];
    }


    /**
     * @return string[]
     */
    public static function organizerMetaKeys(): array
    {
        return ['_OrganizerEmail', '_OrganizerPhone', '_OrganizerWebsite'];
    }
}
// End of file TheEventsCalendarSchema.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar/TheEventsCalendarSchema.php
