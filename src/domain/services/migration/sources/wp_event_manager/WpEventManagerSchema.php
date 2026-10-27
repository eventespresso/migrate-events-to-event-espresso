<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\wp_event_manager;

/**
 * Class WpEventManagerSchema
 *
 * The names WP Event Manager (wp-eventmanager.com) stores its data under, from
 * version 3.4.1 (research profile §2). Events, venues and organizers are
 * posts with `_`-prefixed meta, there are no custom tables, and the free
 * plugin has no bookings: registration and ticketing belong to paid add-ons.
 * Not to be confused with Events Manager (Pixelite) or WP Events Manager
 * (ThimPress), whose names are different.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class WpEventManagerSchema
{
    public const PLUGIN_FILE = 'wp-event-manager/wp-event-manager.php';

    public const VERSION_OPTION = 'wp_event_manager_version';

    public const TIMEZONE_OPTION = 'event_manager_timezone_setting';

    public const TIMEZONE_EACH_EVENT = 'each_event';

    public const POST_TYPE_EVENT = 'event_listing';

    public const POST_TYPE_VENUE = 'event_venue';

    public const POST_TYPE_ORGANIZER = 'event_organizer';

    public const TAXONOMY_CATEGORY = 'event_listing_category';

    public const TAXONOMY_TYPE = 'event_listing_type';

    /**
     * Post statuses that are never migrated: `preview` is a front-end draft that was never submitted.
     */
    public const EXCLUDED_POST_STATUSES = ['trash', 'auto-draft', 'inherit', 'preview'];

    /**
     * Event post meta the reader uses.
     *
     * @return string[]
     */
    public static function eventMetaKeys(): array
    {
        return [
            '_thumbnail_id',
            '_event_banner',
            '_event_online',
            '_event_location',
            '_event_pincode',
            '_event_country',
            '_event_start_date',
            '_event_end_date',
            '_event_start_time',
            '_event_end_time',
            '_event_timezone',
            '_event_registration_deadline',
            '_registration',
            '_event_video_url',
            '_event_ticket_options',
            '_event_ticket_price',
            '_event_organizer_ids',
            '_event_venue_ids',
            '_event_tags',
            '_cancelled',
            '_organizer_name',
            '_organizer_email',
        ];
    }
}
// End of file WpEventManagerSchema.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\wp_event_manager/WpEventManagerSchema.php
