<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\wp_event_manager;

use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;

/**
 * Class WpEventManagerStatusTable
 *
 * WP Event Manager's event statuses (research profile §5). The free plugin has
 * no bookings, so only the event map is filled: the post status, `expired` for
 * an event that has finished (it was published), and "cancelled" for an event
 * with `_cancelled` = 1.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class WpEventManagerStatusTable
{
    public const CANCELLED = 'cancelled';


    public static function table(): StatusTable
    {
        return new StatusTable(
            [],
            [],
            [],
            [
                'publish'       => 'publish',
                'private'       => 'private',
                'expired'       => 'publish', // finished; the expiry cron set the status
                'pending'       => 'draft',   // awaiting approval
                'draft'         => 'draft',
                'future'        => 'draft',
                self::CANCELLED => 'cancelled',
            ]
        );
    }
}
// End of file WpEventManagerStatusTable.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\wp_event_manager/WpEventManagerStatusTable.php
