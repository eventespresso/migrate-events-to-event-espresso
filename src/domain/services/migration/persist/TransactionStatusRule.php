<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

use EEM_Payment;
use EEM_Transaction;
use EventEspresso\EventMigrator\domain\services\migration\mapping\MappedPayment;

/**
 * Class TransactionStatusRule
 *
 * The transaction status core ends with after processing a booking's payments
 * (EE_Transaction::update_status_based_on_total_paid(), reached through
 * PaymentProcessor for every payment that is not failed): overpaid, complete
 * or incomplete by comparing the approved total with TXN_total. The booking
 * persister observes what core did; the dry-run persister predicts it with
 * this rule, so both report the same txn-status-reconciled warnings. Pure.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class TransactionStatusRule
{
    /**
     * Amounts closer than this are equal, as EEH_Money::compare_floats() treats cents.
     */
    private const EPSILON = 0.005;


    /**
     * @param float           $total    TXN_total.
     * @param MappedPayment[] $payments The booking's payments in processing order.
     * @param string          $current  The status the transaction was created with.
     * @return string The status after payment processing; $current when no payment triggers an update.
     */
    public static function afterPayments(float $total, array $payments, string $current): string
    {
        $processed = false;
        $paid      = 0.0;
        foreach ($payments as $payment) {
            if (! $payment instanceof MappedPayment || $payment->status() === EEM_Payment::status_id_failed) {
                continue;
            }
            $processed = true;
            if ($payment->status() === EEM_Payment::status_id_approved) {
                $paid += $payment->amount();
            }
        }
        if (! $processed) {
            return $current;
        }
        if ($paid - $total >= self::EPSILON) {
            return EEM_Transaction::overpaid_status_code;
        }
        if (abs($paid - $total) < self::EPSILON) {
            return EEM_Transaction::complete_status_code;
        }
        return EEM_Transaction::incomplete_status_code;
    }
}
// End of file TransactionStatusRule.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/TransactionStatusRule.php
