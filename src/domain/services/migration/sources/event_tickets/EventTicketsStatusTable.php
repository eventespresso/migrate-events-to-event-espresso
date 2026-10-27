<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\event_tickets;

use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;

/**
 * Class EventTicketsStatusTable
 *
 * Tickets Commerce order statuses (`tec-tc-<slug>`, research profile §5)
 * mapped to Event Espresso. Registration and transaction keys are the order's
 * post status. Payment keys are the states EventTicketsBookingReader derives
 * from the order, because a Tickets Commerce order carries its payment as
 * meta: `paid`, `refund`, `pending`, `declined`, `failed`, `voided`. Event
 * keys are the host post's status (events come from The Events Calendar's
 * post fields).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventTicketsStatusTable
{
    public static function table(): StatusTable
    {
        return new StatusTable(
            [
                'tec-tc-created'       => 'RIC',
                'tec-tc-pending'       => 'RPP',
                'tec-tc-action-req'    => 'RPP',
                'tec-tc-approved'      => 'RPP',
                'tec-tc-completed'     => 'RAP',
                'tec-tc-not-completed' => 'RCN',
                'tec-tc-denied'        => 'RDC',
                'tec-tc-refunded'      => 'RCN',
                'tec-tc-reversed'      => 'RCN',
                'tec-tc-voided'        => 'RCN',
                'tec-tc-undefined'     => 'RIC',
                'tec-tc-unknown'       => 'RIC',
            ],
            [
                'tec-tc-created'       => 'TIN',
                'tec-tc-pending'       => 'TIN',
                'tec-tc-action-req'    => 'TIN',
                'tec-tc-approved'      => 'TIN',
                'tec-tc-completed'     => 'TCM',
                'tec-tc-not-completed' => 'TAB',
                'tec-tc-denied'        => 'TFL',
                'tec-tc-refunded'      => 'TIN',
                'tec-tc-reversed'      => 'TIN',
                'tec-tc-voided'        => 'TAB',
                'tec-tc-undefined'     => 'TIN',
                'tec-tc-unknown'       => 'TIN',
            ],
            [
                'paid'     => 'PAP',
                'refund'   => 'PAP',
                'pending'  => 'PPN',
                'declined' => 'PDC',
                'failed'   => 'PFL',
                'voided'   => 'PCN',
            ],
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
// End of file EventTicketsStatusTable.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\event_tickets/EventTicketsStatusTable.php
