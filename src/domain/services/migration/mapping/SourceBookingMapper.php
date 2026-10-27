<?php

namespace EventEspresso\EventMigrator\domain\services\migration\mapping;

use DateTimeImmutable;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\records\SourceBookingRecord;
use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\normalize\AttendeeResolver;
use EventEspresso\EventMigrator\domain\services\migration\normalize\StatusMapper;
use EventEspresso\EventMigrator\domain\services\migration\normalize\TimezoneNormalizer;
use EventEspresso\EventMigrator\domain\services\migration\normalize\UnmappedConceptWriter;

/**
 * Class SourceBookingMapper
 *
 * Turns one source booking into the MappedBooking a persister writes
 * (REQ-017, REQ-018): statuses through the adapter's StatusTable (unmapped
 * stops the record), one registration per seat with the price actually paid,
 * payments with inferred amounts and currency checks, answers and check-ins.
 * Writes nothing. Everything that can fail is checked before the contact is
 * resolved, so a failing booking leaves no reuse note behind.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class SourceBookingMapper
{
    private StatusMapper $status_mapper;

    private AttendeeResolver $attendee_resolver;

    private SourcePaymentMapper $payment_mapper;

    private UnmappedConceptWriter $concept_writer;

    private ?TimezoneNormalizer $normalizer = null;


    public function __construct(
        StatusMapper $status_mapper,
        AttendeeResolver $attendee_resolver,
        SourcePaymentMapper $payment_mapper,
        UnmappedConceptWriter $concept_writer
    ) {
        $this->status_mapper     = $status_mapper;
        $this->attendee_resolver = $attendee_resolver;
        $this->payment_mapper    = $payment_mapper;
        $this->concept_writer    = $concept_writer;
    }


    /**
     * @param SourceBookingRecord  $booking       The source booking.
     * @param array<string, float> $ticket_prices Source ticket id => migrated ticket price, for the event.
     * @param StatusTable          $status_table  The adapter's status table.
     * @param MigrationContext     $context       The run.
     * @throws MigrationRecordException When the booking has no seats or a seat's ticket was not migrated.
     */
    public function map(
        SourceBookingRecord $booking,
        array $ticket_prices,
        StatusTable $status_table,
        MigrationContext $context
    ): MappedBooking {
        $id         = $booking->sourceId();
        $normalizer = $this->normalizer($context);
        $reg_status = $this->status_mapper->registration($status_table, $booking->status(), CrosswalkKeys::TYPE_BOOKING, $id);
        $txn_status = $this->status_mapper->transaction(
            $status_table,
            $booking->transactionStatus(),
            CrosswalkKeys::TYPE_BOOKING,
            $id
        );
        $pay_status = [];
        foreach ($booking->payments() as $index => $payment) {
            $pay_status[ $index ] = $this->status_mapper->payment(
                $status_table,
                $payment->status(),
                CrosswalkKeys::TYPE_PAYMENT,
                $payment->sourceId()
            );
        }
        $this->assertSeats($booking, $ticket_prices);
        $created = $booking->createdAt() !== null ? $normalizer->toUtc($booking->createdAt()) : null;
        $total   = $booking->total() ?? $this->seatTotal($booking);

        $registrations = $this->registrations($booking, $ticket_prices, $reg_status, $created, $context);
        $payments      = $this->payment_mapper->map($booking, $pay_status, $total, $created, $normalizer, $context);
        $attendee      = $this->attendee_resolver->resolve($booking->attendee(), $context);
        foreach ($this->concept_writer->warnings($booking->unmapped(), CrosswalkKeys::TYPE_BOOKING, $id) as $warning) {
            $context->addWarning($warning);
        }
        return new MappedBooking(
            $id,
            $booking->attendee()->sourceId(),
            $attendee,
            $txn_status,
            $total,
            $registrations,
            [
                'timestamp' => $created,
                'currency'  => strtoupper(trim($booking->currency())),
                'payments'  => $payments,
                'answers'   => $this->answers($booking),
                'checkins'  => $this->checkins($booking, $normalizer),
            ]
        );
    }


    private function normalizer(MigrationContext $context): TimezoneNormalizer
    {
        if (
            ! $this->normalizer instanceof TimezoneNormalizer
            || $this->normalizer->siteTimezone() !== $context->siteTimezone()
        ) {
            $this->normalizer = new TimezoneNormalizer($context->siteTimezone());
        }
        return $this->normalizer;
    }


    /**
     * @param SourceBookingRecord  $booking       The source booking.
     * @param array<string, float> $ticket_prices Source ticket id => migrated ticket price.
     * @throws MigrationRecordException When there are no seats or a seat's ticket was not migrated.
     */
    private function assertSeats(SourceBookingRecord $booking, array $ticket_prices): void
    {
        $message = '';
        if ($booking->seatCount() < 1) {
            $message = esc_html__('The source booking has no seats.', 'migrate-events-to-event-espresso');
        }
        foreach ($booking->seats() as $seat) {
            if ($seat->quantity() > 0 && ! array_key_exists($seat->ticketSourceId(), $ticket_prices)) {
                $message = esc_html__('The source booking is for a ticket that was not migrated.', 'migrate-events-to-event-espresso');
            }
        }
        if ($message !== '') {
            // The message is a translated constant; the type and id are data carried for the report.
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new MigrationRecordException(
                $message,
                WarningRule::RECORD_FAILED,
                CrosswalkKeys::TYPE_BOOKING,
                $booking->sourceId()
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }


    private function seatTotal(SourceBookingRecord $booking): float
    {
        $total = 0.0;
        foreach ($booking->seats() as $seat) {
            $total += $seat->pricePaid() * max(0, $seat->quantity());
        }
        return $total;
    }


    /**
     * One registration per seat. The source reference becomes the registration code, suffixed with the seat
     * number when the booking has several seats; a line whose price paid differs from the ticket price is
     * reported once as price-overridden.
     *
     * @param SourceBookingRecord    $booking       The source booking.
     * @param array<string, float>   $ticket_prices Source ticket id => migrated ticket price.
     * @param string                 $status        The EE registration status.
     * @param DateTimeImmutable|null $created       REG_date.
     * @param MigrationContext       $context       The run, for warnings.
     * @return MappedRegistration[]
     */
    private function registrations(
        SourceBookingRecord $booking,
        array $ticket_prices,
        string $status,
        ?DateTimeImmutable $created,
        MigrationContext $context
    ): array {
        $group_size    = $booking->seatCount();
        $reference     = trim($booking->reference());
        $registrations = [];
        $count         = 0;
        foreach ($booking->seats() as $seat) {
            $ticket_price = (float) $ticket_prices[ $seat->ticketSourceId() ];
            for ($seat_number = 0; $seat_number < $seat->quantity(); $seat_number++) {
                $count++;
                $registrations[] = new MappedRegistration(
                    $seat->ticketSourceId(),
                    $ticket_price,
                    $seat->pricePaid(),
                    $count,
                    $group_size,
                    $reference === '' ? null : ($group_size > 1 ? $reference . '-' . $count : $reference),
                    $created,
                    $status,
                    (string) $seat->attendeeName()
                );
            }
            if ($seat->quantity() > 0 && end($registrations)->isPriceOverridden()) {
                $context->addWarning(
                    new MigrationWarning(
                        WarningRule::PRICE_OVERRIDDEN,
                        CrosswalkKeys::TYPE_BOOKING,
                        $booking->sourceId(),
                        [self::money($seat->pricePaid()), self::money($ticket_price)]
                    )
                );
            }
        }
        return $registrations;
    }


    /**
     * @return MappedAnswer[]
     */
    private function answers(SourceBookingRecord $booking): array
    {
        $answers = [];
        foreach ($booking->answers() as $answer) {
            $values = array_values(array_filter($answer->values(), static fn(string $value): bool => trim($value) !== ''));
            if ($values !== []) {
                $answers[] = new MappedAnswer($answer->fieldSourceId(), $values);
            }
        }
        return $answers;
    }


    /**
     * @return MappedCheckin[]
     */
    private function checkins(SourceBookingRecord $booking, TimezoneNormalizer $normalizer): array
    {
        $checkins = [];
        foreach ($booking->checkins() as $checkin) {
            $checkins[] = new MappedCheckin(
                $checkin->datetimeSourceId(),
                $normalizer->toUtc($checkin->checkedInAt()),
                $checkin->isCheckedIn()
            );
        }
        return $checkins;
    }


    private static function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
// End of file SourceBookingMapper.php
// Location: EventEspresso\EventMigrator\domain\services\migration\mapping/SourceBookingMapper.php
