<?php

namespace EventEspresso\EventMigrator\domain\services\migration\mapping;

use DateTimeImmutable;
use EventEspresso\EventMigrator\domain\services\migration\normalize\ResolvedAttendee;

/**
 * Class MappedBooking
 *
 * Everything the booking persister writes for one source booking: the
 * contact decision, the transaction, one registration per seat, payments,
 * answers and check-ins. Pure data; built by SourceBookingMapper.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class MappedBooking
{
    private string $source_id;

    private string $attendee_source_id;

    private ResolvedAttendee $attendee;

    private string $transaction_status;

    private ?DateTimeImmutable $timestamp;

    private float $total;

    private string $currency;

    private array $registrations;

    private array $payments;

    private array $answers;

    private array $checkins;


    /**
     * @param string               $source_id          The source booking id.
     * @param string               $attendee_source_id The source attendee id, crosswalked on a created contact.
     * @param ResolvedAttendee     $attendee           The contact decision.
     * @param string               $transaction_status The EE transaction status code from the source.
     * @param float                $total              TXN_total, the booking total in the source currency.
     * @param MappedRegistration[] $registrations      At least one.
     * @param array                $extra              Optional: timestamp (?DateTimeImmutable, TXN_timestamp in UTC),
     *                                                 currency (string), payments (MappedPayment[]),
     *                                                 answers (MappedAnswer[]), checkins (MappedCheckin[]).
     */
    public function __construct(
        string $source_id,
        string $attendee_source_id,
        ResolvedAttendee $attendee,
        string $transaction_status,
        float $total,
        array $registrations,
        array $extra = []
    ) {
        $this->source_id          = $source_id;
        $this->attendee_source_id = $attendee_source_id;
        $this->attendee           = $attendee;
        $this->transaction_status = $transaction_status;
        $this->total              = $total;
        $this->registrations      = self::only(MappedRegistration::class, $registrations);
        $this->timestamp          = ($extra['timestamp'] ?? null) instanceof DateTimeImmutable ? $extra['timestamp'] : null;
        $this->currency           = (string) ($extra['currency'] ?? '');
        $this->payments           = self::only(MappedPayment::class, (array) ($extra['payments'] ?? []));
        $this->answers            = self::only(MappedAnswer::class, (array) ($extra['answers'] ?? []));
        $this->checkins           = self::only(MappedCheckin::class, (array) ($extra['checkins'] ?? []));
    }


    private static function only(string $class, array $items): array
    {
        return array_values(array_filter($items, static fn($item): bool => $item instanceof $class));
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    public function attendeeSourceId(): string
    {
        return $this->attendee_source_id;
    }


    public function attendee(): ResolvedAttendee
    {
        return $this->attendee;
    }


    public function transactionStatus(): string
    {
        return $this->transaction_status;
    }


    public function timestamp(): ?DateTimeImmutable
    {
        return $this->timestamp;
    }


    public function total(): float
    {
        return $this->total;
    }


    public function currency(): string
    {
        return $this->currency;
    }


    /**
     * @return MappedRegistration[]
     */
    public function registrations(): array
    {
        return $this->registrations;
    }


    /**
     * @return MappedPayment[]
     */
    public function payments(): array
    {
        return $this->payments;
    }


    /**
     * @return MappedAnswer[]
     */
    public function answers(): array
    {
        return $this->answers;
    }


    /**
     * @return MappedCheckin[]
     */
    public function checkins(): array
    {
        return $this->checkins;
    }
}
// End of file MappedBooking.php
// Location: EventEspresso\EventMigrator\domain\services\migration\mapping/MappedBooking.php
