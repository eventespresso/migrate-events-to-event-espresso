<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\event_genius;

/**
 * Class EventGeniusSchema
 *
 * The names Event Genius (event-genius, free build) stores its data under,
 * from version 1.19 (research profile §2). Events, venues and organizers are
 * posts; registrations and payments are `evge_*` tables. The paid builds ship
 * under other plugin file names and add ticket tables this adapter does not
 * read. Exact names only.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventGeniusSchema
{
    public const PLUGIN_FILE = 'event-genius/event-genius.php';

    public const EVENT_TIMING = 'evge_events';

    public const REGISTRATIONS = 'evge_registrations';

    public const REGISTRATIONS_META = 'evge_registrations_meta';

    public const PAYMENTS = 'evge_payments';

    public const PAYMENTS_META = 'evge_payments_meta';

    public const FIELDS_OPTION = 'evge_all_fields';

    public const POST_TYPE_EVENT = 'evge_event';

    public const POST_TYPE_VENUE = 'evge_venue';

    public const POST_TYPE_ORGANIZER = 'evge_organizer';

    public const TAXONOMY_CATEGORY = 'evge_event_cat';

    public const TAXONOMY_TAG = 'evge_event_tag';

    /**
     * Source event ids of a recurring series start with this prefix and carry the template event's post id.
     */
    public const SERIES_PREFIX = 'series:';

    /**
     * Source ticket ids of an event's implicit ticket start with this prefix and carry the migrated event id.
     */
    public const TICKET_PREFIX = 'event:';

    /**
     * evge_recurrence_type values of a template event that repeats.
     */
    public const REPEATING = ['daily', 'weekly', 'monthly', 'weekday', 'custom'];

    /**
     * Post statuses that are never migrated.
     */
    public const EXCLUDED_POST_STATUSES = ['trash', 'auto-draft', 'inherit'];

    /**
     * Registration meta keys that are not form answers.
     */
    public const SYSTEM_META = ['first', 'last', 'email', '_event_info', 'canceled_date', 'canceled_by', 'canceled_notes'];

    /**
     * The tables a usable Event Genius installation has.
     *
     * @return string[]
     */
    public static function requiredTables(): array
    {
        return [
            self::EVENT_TIMING,
            self::REGISTRATIONS,
            self::REGISTRATIONS_META,
            self::PAYMENTS,
            self::PAYMENTS_META,
        ];
    }
}
// End of file EventGeniusSchema.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\event_genius/EventGeniusSchema.php
