<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventon_lite;

/**
 * Class EventonSchema
 *
 * The names EventON Lite stores its data under, from version 2.5.10
 * (research profile §2). Events are `ajde_events` posts; locations, organizers
 * and event types are terms, but the location and organizer details are not
 * term meta: they are one serialized option, `evo_tax_meta`. EventON Lite has
 * no tables, no tickets and no bookings.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventonSchema
{
    public const PLUGIN_FILE = 'eventon-lite/eventon.php';

    public const VERSION_OPTION = 'eventon_plugin_version';

    public const SETTINGS_OPTION = 'evcal_options_evcal_1';

    public const TAX_META_OPTION = 'evo_tax_meta';

    public const POST_TYPE_EVENT = 'ajde_events';

    public const TAXONOMY_LOCATION = 'event_location';

    public const TAXONOMY_ORGANIZER = 'event_organizer';

    public const TAXONOMY_TAG = 'post_tag';

    /**
     * Post statuses that are never migrated.
     */
    public const EXCLUDED_POST_STATUSES = ['trash', 'auto-draft', 'inherit'];

    /**
     * The event-type taxonomies (the first two are always on, the others are optional).
     */
    public const TYPE_TAXONOMIES = ['event_type', 'event_type_2', 'event_type_3', 'event_type_4', 'event_type_5'];

    /**
     * Event `_status` values that change the migrated event's status; the others are reported as unmapped.
     */
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_POSTPONED = 'postponed';

    /**
     * Event post meta the readers use (the custom data fields `evcal_ec_f<N>a1` are added by the reader).
     *
     * @return string[]
     */
    public static function eventMetaKeys(): array
    {
        $custom = [];
        for ($index = 1; $index <= 10; $index++) {
            $custom[] = 'evcal_ec_f' . $index . 'a1';
        }
        return array_merge(
            [
                '_thumbnail_id',
                'evcal_srow',
                'evcal_erow',
                '_evo_tz',
                'evcal_allday',
                '_time_ext_type',
                'evcal_repeat',
                'repeat_intervals',
                '_status',
                '_cancel',
                '_attendance_mode',
                '_virtual',
                '_vir_url',
                'evcal_subtitle',
                'evcal_lmlink',
                'evcal_exlink',
            ],
            $custom
        );
    }
}
// End of file EventonSchema.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventon_lite/EventonSchema.php
