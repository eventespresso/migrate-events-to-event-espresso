<?php

namespace EventEspresso\EventMigrator\application\services\migration\records;

/**
 * Class SourceSeatRecord
 *
 * One ticket line of a source booking: which source ticket, how many, and
 * the price actually paid per seat.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceSeatRecord
{
    private string $ticket_source_id;

    private float $price_paid;

    private int $quantity;

    private ?string $attendee_name;


    /**
     * @param string      $ticket_source_id Source identifier of the ticket this seat was bought on.
     * @param float       $price_paid       Price paid per seat in the booking currency.
     * @param int         $quantity         Number of seats on this line.
     * @param string|null $attendee_name    Per-seat attendee name when the source lists one.
     */
    public function __construct(
        string $ticket_source_id,
        float $price_paid,
        int $quantity = 1,
        ?string $attendee_name = null
    ) {
        $this->ticket_source_id = $ticket_source_id;
        $this->price_paid       = $price_paid;
        $this->quantity         = $quantity;
        $this->attendee_name    = $attendee_name;
    }


    public function ticketSourceId(): string
    {
        return $this->ticket_source_id;
    }


    public function pricePaid(): float
    {
        return $this->price_paid;
    }


    public function quantity(): int
    {
        return $this->quantity;
    }


    public function attendeeName(): ?string
    {
        return $this->attendee_name;
    }
}
// End of file SourceSeatRecord.php
// Location: EventEspresso\EventMigrator\application\services\migration\records/SourceSeatRecord.php
