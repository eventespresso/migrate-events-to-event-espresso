<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\event_genius;

use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;

/**
 * Class EventGeniusStatusTable
 *
 * Event Genius' status vocabularies (research profile §5) mapped to Event
 * Espresso statuses. Registration keys are evge_registrations.status;
 * transaction keys are those plus "free" (nothing to pay) and "paid" (the
 * payments cover the total), which EventGeniusBookingReader computes; payment
 * keys are evge_payments.payment_status ("offline" is the free build's record
 * of a payment still to be made), with "refund" for the refund added to a
 * refunded payment; event keys are the post status.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventGeniusStatusTable
{
    public const TXN_FREE = 'free';

    public const TXN_PAID = 'paid';

    public const PAYMENT_PAID = 'completed';

    public const PAYMENT_REFUNDED = 'refunded';

    public const PAYMENT_REFUND = 'refund';


    public static function table(): StatusTable
    {
        return new StatusTable(
            [
                'confirmed' => 'RAP',
                'pending'   => 'RPP',
                'canceled'  => 'RCN',
                'waiting'   => 'RWL',
            ],
            [
                self::TXN_FREE => 'TCM',
                self::TXN_PAID => 'TCM',
                'confirmed'    => 'TIN',
                'pending'      => 'TIN',
                'canceled'     => 'TAB',
                'waiting'      => 'TIN',
            ],
            [
                self::PAYMENT_PAID     => 'PAP',
                'paid'                 => 'PAP',
                self::PAYMENT_REFUNDED => 'PAP', // the refund is the record that follows
                self::PAYMENT_REFUND   => 'PAP', // recorded as a negative amount
                'offline'              => 'PPN',
                'pending'              => 'PPN',
                'failed'               => 'PFL',
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
// End of file EventGeniusStatusTable.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\event_genius/EventGeniusStatusTable.php
