<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy;

use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;

/**
 * Class EventsMadeEasyStatusTable
 *
 * EME's status vocabularies (research profile §5) mapped to Event Espresso
 * statuses. Registration keys are eme_bookings.status values, or "waiting"
 * for a booking on the wait list; transaction keys are those plus "free"
 * (nothing to pay) and "paid" (received covers the total), which
 * EventsMadeEasyBookingReader computes; the payment key is "paid" (EME keeps
 * a payment only on a paid booking and no refunds); event keys are
 * eme_events.event_status.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventsMadeEasyStatusTable
{
    public const TXN_FREE = 'free';

    public const TXN_PAID = 'paid';

    public const BOOKING_WAITING = 'waiting';

    public const PAYMENT_PAID = 'paid';


    public static function table(): StatusTable
    {
        return new StatusTable(
            [
                '0'                   => 'RCN', // trashed: cancelled or removed
                '1'                   => 'RAP', // approved
                '2'                   => 'RPP', // pending approval or payment
                '3'                   => 'RPP', // awaiting the customer's email confirmation
                self::BOOKING_WAITING => 'RWL',
            ],
            [
                self::TXN_FREE => 'TCM',
                self::TXN_PAID => 'TCM',
                '0'            => 'TAB',
                '1'            => 'TIN',
                '2'            => 'TIN',
                '3'            => 'TIN',
                self::BOOKING_WAITING => 'TIN',
            ],
            [
                self::PAYMENT_PAID => 'PAP',
            ],
            [
                '1' => 'publish', // public
                '2' => 'private', // logged-in users only
                '3' => 'publish', // unlisted
                '5' => 'draft',
                '6' => 'draft',   // front-end submission awaiting payment
            ]
        );
    }
}
// End of file EventsMadeEasyStatusTable.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy/EventsMadeEasyStatusTable.php
