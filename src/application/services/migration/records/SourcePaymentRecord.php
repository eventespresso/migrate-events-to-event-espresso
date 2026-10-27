<?php

namespace EventEspresso\EventMigrator\application\services\migration\records;

/**
 * Class SourcePaymentRecord
 *
 * A payment or refund the source recorded against a booking. A null amount
 * means the source only recorded that payment happened; the mapper infers it.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourcePaymentRecord
{
    private string $source_id;

    private ?float $amount;

    private string $status;

    private string $currency;

    private string $gateway;

    private string $gateway_txn_id;

    private ?SourceDateTime $paid_at;

    private bool $refund;

    private array $details;


    /**
     * @param string     $source_id Source identifier of the payment row, or "<booking id>:paid" when inferred.
     * @param float|null $amount    Amount in the booking currency; null when the source did not record it.
     * @param string     $status    Raw source payment status.
     * @param array      $extra     Optional: currency, gateway, gateway_txn_id (strings), paid_at (SourceDateTime),
     *                              refund (bool), details (array of extra scalars).
     */
    public function __construct(string $source_id, ?float $amount, string $status, array $extra = [])
    {
        $this->source_id      = $source_id;
        $this->amount         = $amount;
        $this->status         = $status;
        $this->currency       = (string) ($extra['currency'] ?? '');
        $this->gateway        = (string) ($extra['gateway'] ?? '');
        $this->gateway_txn_id = (string) ($extra['gateway_txn_id'] ?? '');
        $this->paid_at        = ($extra['paid_at'] ?? null) instanceof SourceDateTime ? $extra['paid_at'] : null;
        $this->refund         = (bool) ($extra['refund'] ?? false);
        $this->details        = (array) ($extra['details'] ?? []);
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    public function amount(): ?float
    {
        return $this->amount;
    }


    public function hasAmount(): bool
    {
        return $this->amount !== null;
    }


    public function status(): string
    {
        return $this->status;
    }


    public function currency(): string
    {
        return $this->currency;
    }


    public function gateway(): string
    {
        return $this->gateway;
    }


    public function gatewayTxnId(): string
    {
        return $this->gateway_txn_id;
    }


    public function paidAt(): ?SourceDateTime
    {
        return $this->paid_at;
    }


    public function isRefund(): bool
    {
        return $this->refund;
    }


    public function details(): array
    {
        return $this->details;
    }
}
// End of file SourcePaymentRecord.php
// Location: EventEspresso\EventMigrator\application\services\migration\records/SourcePaymentRecord.php
