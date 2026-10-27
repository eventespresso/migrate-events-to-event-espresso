<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventon_lite;

use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;

/**
 * Class EventonStatusTable
 *
 * EventON Lite's event statuses (research profile §5). It has no bookings, so
 * only the event map is filled: the post status, or "cancelled" and
 * "postponed" for an event whose `_status` meta (or legacy `_cancel` flag) says so.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventonStatusTable
{
    public static function table(): StatusTable
    {
        return new StatusTable(
            [],
            [],
            [],
            [
                'publish'                       => 'publish',
                'private'                       => 'private',
                'pending'                       => 'draft',
                'draft'                         => 'draft',
                'future'                        => 'draft',
                EventonSchema::STATUS_CANCELLED => 'cancelled',
                EventonSchema::STATUS_POSTPONED => 'postponed',
            ]
        );
    }
}
// End of file EventonStatusTable.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventon_lite/EventonStatusTable.php
