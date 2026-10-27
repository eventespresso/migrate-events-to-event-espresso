<?php

namespace EventEspresso\EventMigrator\domain\services\migration\mapping;

use DateTimeImmutable;

/**
 * Class MappedRegistration
 *
 * One registration (one seat) of a mapped booking: which source ticket, the
 * ticket's EE price and the price actually paid (the line item uses the price
 * paid), its position in the group, code, date and EE status. The persister
 * resolves the ticket id through PersistedEvent::ticketIdFor().
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class MappedRegistration
{
    /**
     * Prices closer than this are equal (floating-point noise, not a real override).
     */
    private const PRICE_EPSILON = 0.005;

    private string $ticket_source_id;

    private float $ticket_price;

    private float $price_paid;

    private int $count;

    private int $group_size;

    private ?string $code;

    private ?DateTimeImmutable $date;

    private string $status;

    private string $attendee_name;


    /**
     * @param string                 $ticket_source_id The source ticket the seat was bought on.
     * @param float                  $ticket_price     The migrated ticket's price.
     * @param float                  $price_paid       The price paid for this seat; REG_final_price and the line
     *                                                 item's unit price.
     * @param int                    $count            REG_count, 1-based position in the group.
     * @param int                    $group_size       REG_group_size, the booking's seat total.
     * @param string|null            $code             REG_code from the source reference, or null to let core
     *                                                 generate one.
     * @param DateTimeImmutable|null $date             REG_date in UTC, or null for "now".
     * @param string                 $status           The EE registration status id.
     * @param string                 $attendee_name    The per-seat name the source gave, if any.
     */
    public function __construct(
        string $ticket_source_id,
        float $ticket_price,
        float $price_paid,
        int $count,
        int $group_size,
        ?string $code,
        ?DateTimeImmutable $date,
        string $status,
        string $attendee_name = ''
    ) {
        $this->ticket_source_id = $ticket_source_id;
        $this->ticket_price     = $ticket_price;
        $this->price_paid       = $price_paid;
        $this->count            = $count;
        $this->group_size       = $group_size;
        $this->code             = $code;
        $this->date             = $date;
        $this->status           = $status;
        $this->attendee_name    = $attendee_name;
    }


    public function ticketSourceId(): string
    {
        return $this->ticket_source_id;
    }


    public function ticketPrice(): float
    {
        return $this->ticket_price;
    }


    public function pricePaid(): float
    {
        return $this->price_paid;
    }


    /**
     * Whether the line item must override the ticket price with the price paid (price-overridden).
     */
    public function isPriceOverridden(): bool
    {
        return abs($this->price_paid - $this->ticket_price) >= self::PRICE_EPSILON;
    }


    public function count(): int
    {
        return $this->count;
    }


    public function groupSize(): int
    {
        return $this->group_size;
    }


    public function code(): ?string
    {
        return $this->code;
    }


    public function date(): ?DateTimeImmutable
    {
        return $this->date;
    }


    public function status(): string
    {
        return $this->status;
    }


    public function attendeeName(): string
    {
        return $this->attendee_name;
    }
}
// End of file MappedRegistration.php
// Location: EventEspresso\EventMigrator\domain\services\migration\mapping/MappedRegistration.php
