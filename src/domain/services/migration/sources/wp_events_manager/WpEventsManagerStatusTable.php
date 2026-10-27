<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\wp_events_manager;

use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;

/**
 * Class WpEventsManagerStatusTable
 *
 * WP Events Manager's status vocabularies (research profile §5). Registration
 * and transaction keys are the booking post's status (`ea-pending`,
 * `ea-processing`, `ea-completed`, `ea-cancelled`), the transaction keys also
 * "free" (nothing to pay); the payment keys are "paid" and "pending", which
 * WpEventsManagerBookingReader derives from the status; event keys are the
 * post status (events before version 2.1 kept their lifecycle in the post
 * status, as `tp-event-*`).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class WpEventsManagerStatusTable
{
    public const TXN_FREE = 'free';


    public static function table(): StatusTable
    {
        return new StatusTable(
            [
                'ea-pending'    => 'RPP',
                'ea-processing' => 'RPP', // PayPal reported the payment pending
                'ea-completed'  => 'RAP',
                'ea-cancelled'  => 'RCN',
            ],
            [
                self::TXN_FREE  => 'TCM',
                'ea-pending'    => 'TIN',
                'ea-processing' => 'TIN',
                'ea-completed'  => 'TCM',
                'ea-cancelled'  => 'TAB',
            ],
            [
                WpEventsManagerSchema::PAID    => 'PAP',
                WpEventsManagerSchema::PENDING => 'PPN',
            ],
            [
                'publish'             => 'publish',
                'private'             => 'private',
                'pending'             => 'draft',
                'draft'               => 'draft',
                'future'              => 'draft',
                'tp-event-upcoming'   => 'publish',
                'tp-event-happenning' => 'publish', // sic: the plugin's own spelling
                'tp-event-expired'    => 'publish',
            ]
        );
    }
}
// End of file WpEventsManagerStatusTable.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\wp_events_manager/WpEventsManagerStatusTable.php
