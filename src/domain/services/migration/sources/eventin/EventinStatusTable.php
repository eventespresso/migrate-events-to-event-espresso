<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventin;

use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;

/**
 * Class EventinStatusTable
 *
 * Eventin's status vocabularies (research profile §5) mapped to Event
 * Espresso statuses. Registration and transaction keys are the order's meta
 * `status` (one order = one transaction, its attendees share the status);
 * payment keys are the states EventinBookingReader derives from the order
 * (paid, pending, failed, voided, and "refund" for a refund record); event
 * keys are the post status.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventinStatusTable
{
    public const TXN_FREE = 'free';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_FAILED = 'failed';

    public const PAYMENT_VOIDED = 'voided';

    public const PAYMENT_REFUND = 'refund';


    public static function table(): StatusTable
    {
        return new StatusTable(
            [
                'completed'          => 'RAP',
                'pending'            => 'RPP',
                'failed'             => 'RIC', // the state of an unpaid checkout that was never completed
                'cancelled'          => 'RCN',
                'refunded'           => 'RCN',
                'partially_refunded' => 'RAP', // the refunded seats are listed as refunds
                'waiting'            => 'RWL',
            ],
            [
                self::TXN_FREE       => 'TCM',
                'completed'          => 'TCM',
                'pending'            => 'TIN',
                'failed'             => 'TAB',
                'cancelled'          => 'TAB',
                'refunded'           => 'TCM',
                'partially_refunded' => 'TCM',
                'waiting'            => 'TIN',
            ],
            [
                self::PAYMENT_PAID    => 'PAP',
                self::PAYMENT_PENDING => 'PPN',
                self::PAYMENT_FAILED  => 'PFL',
                self::PAYMENT_VOIDED  => 'PCN',
                self::PAYMENT_REFUND  => 'PAP', // recorded as a negative amount
            ],
            [
                'publish' => 'publish',
                'private' => 'private',
                'pending' => 'draft',
                'draft'   => 'draft',
                'future'  => 'draft',
            ]
        );
    }
}
// End of file EventinStatusTable.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventin/EventinStatusTable.php
