<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\amelia;

use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;

/**
 * Class AmeliaStatusTable
 *
 * Amelia's status vocabularies (research profile §5) mapped to Event Espresso
 * statuses. Registration keys are customer_bookings.status values;
 * transaction keys are those values plus "free" (nothing to pay) and "paid"
 * (the payments cover the total), which AmeliaBookingReader computes; payment
 * keys are the lower-cased payments.status, with "refund" for the refund
 * AmeliaBookingReader adds to a refunded payment; event keys are events.status,
 * with "hidden" for an approved event whose show flag is off.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class AmeliaStatusTable
{
    public const TXN_FREE = 'free';

    public const TXN_PAID = 'paid';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_REFUND = 'refund';


    public static function table(): StatusTable
    {
        return new StatusTable(
            [
                'approved' => 'RAP',
                'pending'  => 'RPP',
                'canceled' => 'RCN', // cancelled by the customer
                'rejected' => 'RDC', // declined by an admin, or by the event being cancelled
                'no-show'  => 'RAP', // approved, did not attend: still holds its seat
                'waiting'  => 'RWL', // wait list (Amelia Pro)
            ],
            [
                self::TXN_FREE => 'TCM',
                self::TXN_PAID => 'TCM',
                'approved'     => 'TIN',
                'pending'      => 'TIN',
                'canceled'     => 'TAB',
                'rejected'     => 'TAB',
                'no-show'      => 'TIN',
                'waiting'      => 'TIN',
            ],
            [
                self::PAYMENT_PAID   => 'PAP',
                'partiallypaid'      => 'PAP', // a deposit (payments.status partiallyPaid); the balance is a later row
                'pending'            => 'PPN',
                'refunded'           => 'PAP',
                self::PAYMENT_REFUND => 'PAP', // recorded as a negative amount
            ],
            [
                'approved'                  => 'publish',
                AmeliaSchema::STATUS_HIDDEN => 'private',
                'rejected'                  => 'cancelled',
                'canceled'                  => 'cancelled',
                'pending'                   => 'draft',
            ]
        );
    }
}
// End of file AmeliaStatusTable.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\amelia/AmeliaStatusTable.php
