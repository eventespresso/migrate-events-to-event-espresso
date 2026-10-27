<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventin;

/**
 * Class EventinSchema
 *
 * The names Eventin (wp-event-solution) stores its data under, from version
 * 4.1.26 (research profile §2, §3). Events, attendees and orders are posts;
 * the order post type is never registered by the plugin, so it is read from
 * the posts table by name. Speakers and organizers are WordPress users.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventinSchema
{
    public const PLUGIN_FILE = 'wp-event-solution/eventin.php';

    /**
     * Eventin's version marker and settings array.
     */
    public const VERSION_OPTION = 'etn_db_migration';

    public const SETTINGS_OPTION = 'etn_event_options';

    public const POST_TYPE_EVENT = 'etn';

    public const POST_TYPE_ORDER = 'etn-order';

    public const POST_TYPE_ATTENDEE = 'etn-attendee';

    public const POST_TYPE_SCHEDULE = 'etn-schedule';

    public const TAXONOMY_CATEGORY = 'etn_category';

    public const TAXONOMY_TAG = 'etn_tags';

    public const COUPONS = 'etn_coupons';

    /**
     * Source event ids of a recurring series start with this prefix and carry the series parent's post id.
     */
    public const SERIES_PREFIX = 'series:';

    public const PLACEHOLDER_META = '_etn_preview_placeholder';

    public const ANSWER_META_PREFIX = 'etn_attendee_extra_field_';

    /**
     * Post statuses that are never migrated.
     */
    public const EXCLUDED_POST_STATUSES = ['trash', 'auto-draft', 'inherit'];

    /**
     * The order statuses (order meta `status`) that have a payment attempt behind them.
     */
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    public const STATUS_WAITING = 'waiting';

    /**
     * Event post meta the event and ticket readers use.
     *
     * @return string[]
     */
    public static function eventMetaKeys(): array
    {
        return [
            '_thumbnail_id',
            'etn_start_date',
            'etn_end_date',
            'etn_start_time',
            'etn_end_time',
            'event_timezone',
            'etn_registration_deadline',
            'etn_ticket_variations',
            'etn_enable_global_stock',
            'etn_global_stock',
            'etn_event_location',
            'location',
            'event_type',
            'meeting_link',
            'etn_event_organizer',
            'etn_event_speaker',
            'etn_event_schedule',
            'recurring_enabled',
            'attendee_extra_fields',
            'external_link',
        ];
    }
}
// End of file EventinSchema.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventin/EventinSchema.php
