<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventprime;

use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;

/**
 * Class EventPrimeStatusTable
 *
 * EventPrime's status vocabularies (research profile §5) mapped to Event
 * Espresso statuses. Registration keys are the booking's status (`completed`,
 * `pending`, `cancelled`, `refunded`, `failed`); transaction keys are those
 * plus "free" (nothing to pay); payment keys are the gateway status of the
 * booking's payment log, with "refund" for the refund added to a refunded
 * payment and "voided" for an offline payment that was cancelled; event keys
 * are the event post's status (`cancelled`, `expired` and `emexpired` are
 * EventPrime's own).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventPrimeStatusTable
{
    public const TXN_FREE = 'free';

    public const PAYMENT_PAID = 'completed';

    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_FAILED = 'failed';

    public const PAYMENT_VOIDED = 'voided';

    public const PAYMENT_REFUNDED = 'refunded';

    public const PAYMENT_REFUND = 'refund';


    public static function table(): StatusTable
    {
        return new StatusTable(
            [
                'completed' => 'RAP',
                'pending'   => 'RPP',
                'cancelled' => 'RCN',
                'refunded'  => 'RCN',
                'failed'    => 'RIC', // the state of a checkout that was never paid
            ],
            [
                self::TXN_FREE => 'TCM',
                'completed'    => 'TCM',
                'pending'      => 'TIN',
                'cancelled'    => 'TAB',
                'refunded'     => 'TCM',
                'failed'       => 'TAB',
            ],
            [
                self::PAYMENT_PAID     => 'PAP',
                'approved'             => 'PAP',
                'received'             => 'PAP',
                self::PAYMENT_PENDING  => 'PPN',
                self::PAYMENT_FAILED   => 'PFL',
                self::PAYMENT_VOIDED   => 'PCN',
                self::PAYMENT_REFUNDED => 'PAP', // the refund is the record that follows
                self::PAYMENT_REFUND   => 'PAP', // recorded as a negative amount
            ],
            [
                'publish'   => 'publish',
                'private'   => 'private',
                'pending'   => 'draft',
                'draft'     => 'draft',
                'future'    => 'draft',
                'cancelled' => 'cancelled',
                'expired'   => 'publish', // an event that has finished
                'emexpired' => 'publish',
            ]
        );
    }
}
// End of file EventPrimeStatusTable.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventprime/EventPrimeStatusTable.php
