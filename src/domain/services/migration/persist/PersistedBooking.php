<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

/**
 * Class PersistedBooking
 *
 * The ids of what one persistBooking() call created, recorded the moment each
 * save succeeds; mirrors PersistedEvent. BookingGraphRemover deletes exactly
 * these on compensation, and reverses only the sold-count increments listed
 * here. Ids from a dry run are synthetic negatives.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class PersistedBooking
{
    private string $source_id;

    private ?int $attendee_id = null;

    private ?int $created_attendee_id = null;

    private int $transaction_id = 0;

    private int $total_line_item_id = 0;

    /**
     * @var int[] ticket line items created under the total line item
     */
    private array $line_item_ids = [];

    /**
     * @var array<int, int> REG_count => EE registration id
     */
    private array $registration_ids = [];

    /**
     * @var array<int, int> EE ticket id => sold count this call added
     */
    private array $sold_increments = [];

    /**
     * @var array<string, int> source payment id => EE payment id
     */
    private array $payment_ids = [];

    /**
     * @var int[]
     */
    private array $registration_payment_ids = [];

    /**
     * @var int[]
     */
    private array $answer_ids = [];

    /**
     * @var int[]
     */
    private array $checkin_ids = [];


    public function __construct(string $source_id)
    {
        $this->source_id = $source_id;
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    /**
     * The contact the registrations belong to, whether created by this call or linked.
     */
    public function setAttendeeId(int $attendee_id, bool $created): void
    {
        $this->attendee_id = $attendee_id;
        if ($created) {
            $this->created_attendee_id = $attendee_id;
        }
    }


    public function attendeeId(): ?int
    {
        return $this->attendee_id;
    }


    public function createdAttendeeId(): ?int
    {
        return $this->created_attendee_id;
    }


    public function setTransaction(int $transaction_id, int $total_line_item_id): void
    {
        $this->transaction_id     = $transaction_id;
        $this->total_line_item_id = $total_line_item_id;
    }


    /**
     * The EE transaction id, or 0 when none was created.
     */
    public function transactionId(): int
    {
        return $this->transaction_id;
    }


    public function totalLineItemId(): int
    {
        return $this->total_line_item_id;
    }


    public function addLineItem(int $line_item_id): void
    {
        $this->line_item_ids[] = $line_item_id;
    }


    /**
     * @return int[]
     */
    public function lineItemIds(): array
    {
        return $this->line_item_ids;
    }


    public function addRegistration(int $count, int $registration_id): void
    {
        $this->registration_ids[ $count ] = $registration_id;
    }


    /**
     * @return array<int, int> REG_count => EE registration id
     */
    public function registrationIds(): array
    {
        return $this->registration_ids;
    }


    /**
     * The registration that carries booking-level answers and check-ins: REG_count 1.
     */
    public function primaryRegistrationId(): ?int
    {
        return $this->registration_ids[1] ?? (reset($this->registration_ids) ?: null);
    }


    public function addSoldIncrement(int $ticket_id): void
    {
        $this->sold_increments[ $ticket_id ] = ($this->sold_increments[ $ticket_id ] ?? 0) + 1;
    }


    /**
     * @return array<int, int> EE ticket id => sold count added by this call
     */
    public function soldIncrements(): array
    {
        return $this->sold_increments;
    }


    public function addPayment(string $source_id, int $payment_id): void
    {
        $this->payment_ids[ $source_id ] = $payment_id;
    }


    /**
     * @return array<string, int> source payment id => EE payment id
     */
    public function paymentIds(): array
    {
        return $this->payment_ids;
    }


    /**
     * @param int[] $registration_payment_ids
     */
    public function addRegistrationPayments(array $registration_payment_ids): void
    {
        foreach ($registration_payment_ids as $id) {
            $this->registration_payment_ids[] = (int) $id;
        }
    }


    /**
     * @return int[]
     */
    public function registrationPaymentIds(): array
    {
        return $this->registration_payment_ids;
    }


    public function addAnswer(int $answer_id): void
    {
        $this->answer_ids[] = $answer_id;
    }


    /**
     * @return int[]
     */
    public function answerIds(): array
    {
        return $this->answer_ids;
    }


    public function addCheckin(int $checkin_id): void
    {
        $this->checkin_ids[] = $checkin_id;
    }


    /**
     * @return int[]
     */
    public function checkinIds(): array
    {
        return $this->checkin_ids;
    }


    /**
     * Whether anything was created, i.e. whether compensation has work to do.
     */
    public function hasCreated(): bool
    {
        return $this->created_attendee_id !== null
            || $this->transaction_id !== 0
            || $this->registration_ids !== []
            || $this->payment_ids !== []
            || $this->answer_ids !== []
            || $this->checkin_ids !== [];
    }
}
// End of file PersistedBooking.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/PersistedBooking.php
