<?php

namespace EventEspresso\EventMigrator\domain\services\migration\ui;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;

/**
 * Class OptionsSummary
 *
 * The chosen options as label/value pairs for the confirm step, in plain text
 * (the template escapes them).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class OptionsSummary
{
    /**
     * @return array<int, array{label: string, value: string}>
     */
    public static function lines(MigrationOptions $options, string $source_name): array
    {
        $horizon_date = $options->horizonDate();
        if ($horizon_date !== null) {
            /* translators: %s: a date, YYYY-MM-DD */
            $horizon = sprintf(__('Events ending on or after %s', 'migrate-events-to-event-espresso'), $horizon_date);
        } else {
            $horizon = $options->isUpcomingOnly() ? __('Upcoming events only', 'migrate-events-to-event-espresso') : __('All events', 'migrate-events-to-event-espresso');
        }
        return [
            ['label' => __('Source', 'migrate-events-to-event-espresso'), 'value' => $source_name],
            ['label' => __('Which events', 'migrate-events-to-event-espresso'), 'value' => $horizon],
            [
                'label' => __('What to migrate', 'migrate-events-to-event-espresso'),
                'value' => $options->includesBookings()
                    ? __('Events and their bookings', 'migrate-events-to-event-espresso')
                    : __('Events only', 'migrate-events-to-event-espresso'),
            ],
            [
                'label' => __('Recurring events', 'migrate-events-to-event-espresso'),
                'value' => $options->isEventPerOccurrence()
                    ? __('One event for every occurrence', 'migrate-events-to-event-espresso')
                    : __('One event per series', 'migrate-events-to-event-espresso'),
            ],
            [
                'label' => __('Publish state', 'migrate-events-to-event-espresso'),
                'value' => $options->preserveStatus()
                    ? __('Kept from the source', 'migrate-events-to-event-espresso')
                    : __('Every event is created as a draft', 'migrate-events-to-event-espresso'),
            ],
        ];
    }
}
// End of file OptionsSummary.php
// Location: EventEspresso\EventMigrator\domain\services\migration\ui/OptionsSummary.php
