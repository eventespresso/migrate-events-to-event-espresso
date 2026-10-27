<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar;

use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;

/**
 * Class TheEventsCalendarStatusTable
 *
 * The Events Calendar has no bookings, so only the event vocabulary exists:
 * the owning post's status, or "cancelled"/"postponed" when the event's own
 * status meta (`_tribe_events_status`: scheduled, canceled, postponed) says so.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class TheEventsCalendarStatusTable
{
    public static function table(): StatusTable
    {
        return new StatusTable(
            [],
            [],
            [],
            [
                'publish'   => 'publish',
                'private'   => 'private',
                'pending'   => 'draft',
                'draft'     => 'draft',
                'future'    => 'draft',
                'cancelled' => 'cancelled',
                'postponed' => 'postponed',
            ]
        );
    }
}
// End of file TheEventsCalendarStatusTable.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\the_events_calendar/TheEventsCalendarStatusTable.php
