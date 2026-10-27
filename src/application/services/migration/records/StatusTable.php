<?php

namespace EventEspresso\EventMigrator\application\services\migration\records;

/**
 * Class StatusTable
 *
 * A source plugin's raw status vocabularies mapped onto Event Espresso status
 * constants, one map per domain. Keys are the raw source values lower-cased
 * and trimmed; values are the EE constants. There are deliberately no
 * defaults: a raw value missing from its map must stop that record.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class StatusTable
{
    private array $registration;

    private array $transaction;

    private array $payment;

    private array $event;


    /**
     * @param array $registration Raw booking status => EEM_Registration status id.
     * @param array $transaction  Raw order status => EEM_Transaction status code.
     * @param array $payment      Raw payment status => EEM_Payment status id.
     * @param array $event        Raw event status => EE post status or EEM_Event status.
     */
    public function __construct(array $registration, array $transaction, array $payment, array $event)
    {
        $this->registration = $registration;
        $this->transaction  = $transaction;
        $this->payment      = $payment;
        $this->event        = $event;
    }


    public function registration(): array
    {
        return $this->registration;
    }


    public function transaction(): array
    {
        return $this->transaction;
    }


    public function payment(): array
    {
        return $this->payment;
    }


    public function event(): array
    {
        return $this->event;
    }
}
// End of file StatusTable.php
// Location: EventEspresso\EventMigrator\application\services\migration\records/StatusTable.php
