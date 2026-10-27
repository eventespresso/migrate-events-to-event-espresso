<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_manager;

use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;

/**
 * Class EventsManagerStatusTable
 *
 * Events Manager's status vocabularies (research profile §5) mapped to Event
 * Espresso statuses. Registration keys are em_bookings.booking_status values;
 * transaction keys are those values plus "free" (nothing to pay) and "paid"
 * (approved payments cover the total), which EventsManagerBookingReader
 * computes; payment keys are Events Manager Pro's lower-cased
 * em_transactions.transaction_status; event keys are the post status, or
 * "cancelled" for event_active_status 0.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventsManagerStatusTable
{
    public const TXN_FREE = 'free';

    public const TXN_PAID = 'paid';

    public const EVENT_CANCELLED = 'cancelled';


    public static function table(): StatusTable
    {
        return new StatusTable(
            [
                '0' => 'RPP', // pending (awaiting approval or payment)
                '1' => 'RAP', // approved
                '2' => 'RDC', // rejected
                '3' => 'RCN', // cancelled
                '4' => 'RPP', // awaiting online payment (Pro)
                '5' => 'RPP', // awaiting payment (Pro offline)
                '6' => 'RWL', // waitlist (Pro)
                '7' => 'RWL', // waitlist approved, not yet booked (Pro)
                '8' => 'RIC', // waitlist expired (Pro)
            ],
            [
                self::TXN_FREE => 'TCM',
                self::TXN_PAID => 'TCM',
                '0'            => 'TIN',
                '1'            => 'TIN',
                '2'            => 'TIN',
                '3'            => 'TIN',
                '4'            => 'TIN',
                '5'            => 'TIN',
                '6'            => 'TIN',
                '7'            => 'TIN',
                '8'            => 'TIN',
            ],
            [
                'completed'  => 'PAP',
                'refunded'   => 'PAP',
                'pending'    => 'PPN',
                'processing' => 'PPN',
                'failed'     => 'PFL',
                'declined'   => 'PDC',
                'denied'     => 'PDC',
                'cancelled'  => 'PCN',
                'voided'     => 'PCN',
                'expired'    => 'PCN',
            ],
            [
                'publish'             => 'publish',
                'private'             => 'private',
                'pending'             => 'draft',
                'draft'               => 'draft',
                'future'              => 'draft',
                self::EVENT_CANCELLED => 'cancelled',
            ]
        );
    }
}
// End of file EventsManagerStatusTable.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_manager/EventsManagerStatusTable.php
