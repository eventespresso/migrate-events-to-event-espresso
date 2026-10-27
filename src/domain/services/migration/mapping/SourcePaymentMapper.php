<?php

namespace EventEspresso\EventMigrator\domain\services\migration\mapping;

use DateTimeImmutable;
use EEM_Payment;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\records\SourceBookingRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourcePaymentRecord;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\normalize\TimezoneNormalizer;

/**
 * Class SourcePaymentMapper
 *
 * Turns a booking's source payments into MappedPayments (REQ-018): refunds
 * become negative amounts, a payment recorded without an amount becomes the
 * booking total and a refund without one the amount paid so far, each with a
 * warning, and a currency other than the site's is reported (PRD OQ-7). Used
 * by SourceBookingMapper; pure apart from the warnings it adds.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class SourcePaymentMapper
{
    /**
     * @param SourceBookingRecord    $booking    The source booking.
     * @param string[]               $statuses   EE payment status per payment index.
     * @param float                  $total      The booking total, for inferred amounts.
     * @param DateTimeImmutable|null $created    The booking time, for payments without one.
     * @param TimezoneNormalizer     $normalizer The run's normalizer.
     * @param MigrationContext       $context    The run, for warnings and the site currency.
     * @return MappedPayment[]
     */
    public function map(
        SourceBookingRecord $booking,
        array $statuses,
        float $total,
        ?DateTimeImmutable $created,
        TimezoneNormalizer $normalizer,
        MigrationContext $context
    ): array {
        $payments = [];
        $paid     = 0.0;
        foreach ($booking->payments() as $index => $payment) {
            $currency = strtoupper(trim($payment->currency() !== '' ? $payment->currency() : $booking->currency()));
            $this->checkCurrency($payment, $currency, $context);
            $amount = $this->amount($payment, $total, $paid, $context);
            if (! $payment->isRefund() && $statuses[ $index ] === EEM_Payment::status_id_approved) {
                $paid += $amount;
            }
            $payments[] = new MappedPayment(
                $payment->sourceId(),
                $payment->isRefund() ? -$amount : $amount,
                $statuses[ $index ],
                $payment->paidAt() !== null ? $normalizer->toUtc($payment->paidAt()) : $created,
                [
                    'gateway'        => $payment->gateway(),
                    'gateway_txn_id' => $payment->gatewayTxnId(),
                    'details'        => $payment->details(),
                    'currency'       => $currency,
                    'inferred'       => ! $payment->hasAmount(),
                ]
            );
        }
        return $payments;
    }


    /**
     * The unsigned amount: as recorded, or inferred (a payment as the booking total, a refund as what was
     * paid so far, else the total) with a warning (REQ-018).
     */
    private function amount(SourcePaymentRecord $payment, float $total, float $paid, MigrationContext $context): float
    {
        if ($payment->hasAmount()) {
            return abs((float) $payment->amount());
        }
        $amount = $payment->isRefund() && $paid > 0 ? $paid : $total;
        $context->addWarning(
            new MigrationWarning(
                $payment->isRefund() ? WarningRule::REFUND_AMOUNT_INFERRED : WarningRule::AMOUNT_INFERRED,
                CrosswalkKeys::TYPE_PAYMENT,
                $payment->sourceId(),
                [self::money($amount)]
            )
        );
        return $amount;
    }


    /**
     * The site currency is assumed for every amount (PRD OQ-7); a different source currency is reported.
     */
    private function checkCurrency(SourcePaymentRecord $payment, string $currency, MigrationContext $context): void
    {
        $site = $context->siteCurrency();
        if ($currency === '' || $site === '' || $currency === $site) {
            return;
        }
        $context->addWarning(
            new MigrationWarning(
                WarningRule::CURRENCY_MISMATCH,
                CrosswalkKeys::TYPE_PAYMENT,
                $payment->sourceId(),
                [$currency, $site]
            )
        );
    }


    private static function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
// End of file SourcePaymentMapper.php
// Location: EventEspresso\EventMigrator\domain\services\migration\mapping/SourcePaymentMapper.php
