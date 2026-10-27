<?php

namespace EventEspresso\EventMigrator\domain\services\migration\mapping;

use DateTimeImmutable;

/**
 * Class MappedPayment
 *
 * One EE_Payment a persister writes for a booking. Refunds carry a negative
 * amount. PMD_ID (resolved from the gateway slug) and TXN_ID are added by the
 * persister, because resolving the payment method reads the database.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class MappedPayment
{
    /**
     * PAY_source of every migrated payment, so they can be told apart from gateway and admin payments.
     */
    public const SOURCE = 'MIGRATED';

    private string $source_id;

    private float $amount;

    private string $status;

    private ?DateTimeImmutable $timestamp;

    private string $gateway;

    private string $gateway_txn_id;

    private array $details;

    private string $currency;

    private bool $inferred;


    /**
     * @param string                 $source_id The source payment id.
     * @param float                  $amount    PAY_amount; negative for a refund.
     * @param string                 $status    The EE payment status id.
     * @param DateTimeImmutable|null $timestamp PAY_timestamp in UTC, or null for "now".
     * @param array                  $extra     Optional: gateway, gateway_txn_id, currency (strings), details (array),
     *                                          inferred (bool, the amount was not in the source).
     */
    public function __construct(
        string $source_id,
        float $amount,
        string $status,
        ?DateTimeImmutable $timestamp,
        array $extra = []
    ) {
        $this->source_id      = $source_id;
        $this->amount         = $amount;
        $this->status         = $status;
        $this->timestamp      = $timestamp;
        $this->gateway        = (string) ($extra['gateway'] ?? '');
        $this->gateway_txn_id = (string) ($extra['gateway_txn_id'] ?? '');
        $this->details        = (array) ($extra['details'] ?? []);
        $this->currency       = (string) ($extra['currency'] ?? '');
        $this->inferred       = (bool) ($extra['inferred'] ?? false);
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    public function amount(): float
    {
        return $this->amount;
    }


    public function isRefund(): bool
    {
        return $this->amount < 0;
    }


    public function status(): string
    {
        return $this->status;
    }


    public function timestamp(): ?DateTimeImmutable
    {
        return $this->timestamp;
    }


    /**
     * The source gateway slug; the persister resolves PMD_ID from it.
     */
    public function gateway(): string
    {
        return $this->gateway;
    }


    public function gatewayTxnId(): string
    {
        return $this->gateway_txn_id;
    }


    public function currency(): string
    {
        return $this->currency;
    }


    public function isInferred(): bool
    {
        return $this->inferred;
    }


    /**
     * EE_Payment fields without PMD_ID and TXN_ID; the timestamp is DateTimeImmutable or absent.
     */
    public function fields(): array
    {
        $fields = [
            'PAY_amount'           => $this->amount,
            'STS_ID'               => $this->status,
            'PAY_source'           => self::SOURCE,
            'PAY_gateway_response' => $this->gateway,
            'PAY_txn_id_chq_nmbr'  => $this->gateway_txn_id,
            'PAY_details'          => $this->details,
        ];
        if ($this->timestamp !== null) {
            $fields['PAY_timestamp'] = $this->timestamp;
        }
        return $fields;
    }
}
// End of file MappedPayment.php
// Location: EventEspresso\EventMigrator\domain\services\migration\mapping/MappedPayment.php
