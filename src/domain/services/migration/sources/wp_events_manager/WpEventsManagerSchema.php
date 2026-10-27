<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\wp_events_manager;

/**
 * Class WpEventsManagerSchema
 *
 * The names WP Events Manager (ThimPress) stores its data under, from version
 * 2.2.5 (research profile §2). Events (`tp_event`) and bookings
 * (`event_auth_book`) are posts; there are no custom tables. Its version
 * option (`thimpress-event-version`) is the schema version, not the plugin's.
 * Not to be confused with Events Manager (Pixelite) or WP Event Manager
 * (wp-eventmanager.com), whose names are different.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class WpEventsManagerSchema
{
    public const PLUGIN_FILE = 'wp-events-manager/wp-events-manager.php';

    public const POST_TYPE_EVENT = 'tp_event';

    public const POST_TYPE_BOOKING = 'event_auth_book';

    public const TAXONOMY_CATEGORY = 'tp_event_category';

    public const TAXONOMY_TAG = 'tp_event_tag';

    public const TAXONOMY_TYPE = 'tp_event_type';

    /**
     * Post statuses that are never migrated.
     */
    public const EXCLUDED_POST_STATUSES = ['trash', 'auto-draft', 'inherit'];

    public const PAID = 'paid';

    public const PENDING = 'pending';

    /**
     * Event post meta the reader uses.
     *
     * @return string[]
     */
    public static function eventMetaKeys(): array
    {
        return [
            '_thumbnail_id',
            'tp_event_date_start',
            'tp_event_time_start',
            'tp_event_date_end',
            'tp_event_time_end',
            'tp_event_registration_end_date',
            'tp_event_registration_end_time',
            'tp_event_qty',
            'tp_event_price',
            'tp_event_location',
            'tp_event_iframe',
        ];
    }


    /**
     * Booking post meta the reader uses.
     *
     * @return string[]
     */
    public static function bookingMetaKeys(): array
    {
        return [
            'ea_booking_event_id',
            'ea_booking_user_id',
            'ea_booking_qty',
            'ea_booking_price',
            'ea_booking_currency',
            'ea_booking_payment_id',
            'ea_booking_note',
        ];
    }
}
// End of file WpEventsManagerSchema.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\wp_events_manager/WpEventsManagerSchema.php
