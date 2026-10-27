<?php

namespace EventEspresso\EventMigrator\application\services\migration\records;

/**
 * Class SourceBookingRecord
 *
 * A source booking/order: who booked, which seats, what was paid, the answers
 * given and the check-ins recorded. Becomes one EE transaction with one
 * registration per seat.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceBookingRecord
{
    private string $source_id;

    private string $status;

    private SourceAttendeeRecord $attendee;

    private array $seats;

    private string $reference;

    private string $transaction_status;

    private ?SourceDateTime $created_at;

    private ?float $total;

    private string $currency;

    private array $payments;

    private array $answers;

    private array $checkins;

    private array $unmapped;


    /**
     * @param string               $source_id Source identifier of the booking/order.
     * @param string               $status    Raw source booking status.
     * @param SourceAttendeeRecord $attendee  The person who booked.
     * @param SourceSeatRecord[]   $seats     The ticket lines of the booking.
     * @param array                $extra     Optional: reference, transaction_status, currency (strings),
     *                                        created_at (SourceDateTime), total (?float),
     *                                        payments (SourcePaymentRecord[]), answers (SourceAnswerRecord[]),
     *                                        checkins (SourceCheckinRecord[]), unmapped (UnmappedConcept[]).
     */
    public function __construct(
        string $source_id,
        string $status,
        SourceAttendeeRecord $attendee,
        array $seats,
        array $extra = []
    ) {
        $this->source_id          = $source_id;
        $this->status             = $status;
        $this->attendee           = $attendee;
        $this->seats              = $this->onlyInstancesOf(SourceSeatRecord::class, $seats);
        $this->reference          = (string) ($extra['reference'] ?? '');
        $this->transaction_status = (string) ($extra['transaction_status'] ?? $status);
        $this->created_at         = ($extra['created_at'] ?? null) instanceof SourceDateTime
            ? $extra['created_at']
            : null;
        $this->total              = isset($extra['total']) ? (float) $extra['total'] : null;
        $this->currency           = (string) ($extra['currency'] ?? '');
        $this->payments           = $this->onlyInstancesOf(SourcePaymentRecord::class, $extra['payments'] ?? []);
        $this->answers            = $this->onlyInstancesOf(SourceAnswerRecord::class, $extra['answers'] ?? []);
        $this->checkins           = $this->onlyInstancesOf(SourceCheckinRecord::class, $extra['checkins'] ?? []);
        $this->unmapped           = $this->onlyInstancesOf(UnmappedConcept::class, $extra['unmapped'] ?? []);
    }


    private function onlyInstancesOf(string $class, $items): array
    {
        $kept = [];
        foreach ((array) $items as $item) {
            if ($item instanceof $class) {
                $kept[] = $item;
            }
        }
        return $kept;
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    /**
     * The raw source booking status; mapped through the adapter's StatusTable in Phase 2.
     */
    public function status(): string
    {
        return $this->status;
    }


    public function attendee(): SourceAttendeeRecord
    {
        return $this->attendee;
    }


    /**
     * @return SourceSeatRecord[]
     */
    public function seats(): array
    {
        return $this->seats;
    }


    public function seatCount(): int
    {
        $count = 0;
        foreach ($this->seats as $seat) {
            $count += $seat->quantity();
        }
        return $count;
    }


    /**
     * The source's booking reference or code, kept as the EE registration code when present.
     */
    public function reference(): string
    {
        return $this->reference;
    }


    /**
     * The raw source order/transaction status; defaults to the booking status when the source has only one.
     */
    public function transactionStatus(): string
    {
        return $this->transaction_status;
    }


    public function createdAt(): ?SourceDateTime
    {
        return $this->created_at;
    }


    /**
     * The booking total in the booking currency, or null when the source does not store one.
     */
    public function total(): ?float
    {
        return $this->total;
    }


    public function currency(): string
    {
        return $this->currency;
    }


    /**
     * @return SourcePaymentRecord[]
     */
    public function payments(): array
    {
        return $this->payments;
    }


    /**
     * @return SourceAnswerRecord[]
     */
    public function answers(): array
    {
        return $this->answers;
    }


    /**
     * @return SourceCheckinRecord[]
     */
    public function checkins(): array
    {
        return $this->checkins;
    }


    /**
     * @return UnmappedConcept[]
     */
    public function unmapped(): array
    {
        return $this->unmapped;
    }
}
// End of file SourceBookingRecord.php
// Location: EventEspresso\EventMigrator\application\services\migration\records/SourceBookingRecord.php
