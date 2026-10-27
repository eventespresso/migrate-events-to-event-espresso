<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

use EventEspresso\core\domain\services\registration\RegStatus;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\exceptions\MigrationCleanupFailedException;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\mapping\MappedBooking;
use EventEspresso\EventMigrator\domain\services\migration\normalize\AttendeeResolver;
use Throwable;

/**
 * Class BookingPersister
 *
 * Writes one MappedBooking with notifications suppressed (REQ-017, REQ-018,
 * REQ-024): contact → transaction → per seat a ticket line item and a
 * registration with the source status (sold count +1 for each approved one,
 * never set_status()) → TXN_total from the line items → payments, each
 * applied through core → answers and check-ins → the source transaction
 * status re-applied (txn-status-reconciled when core changed it) → the
 * transaction crosswalked last, so its fingerprint holds the final status.
 * Every id is tracked in a PersistedBooking the moment it exists; a failure
 * is compensated by BookingGraphRemover, and an unproven compensation stops
 * the run with MigrationCleanupFailedException.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class BookingPersister
{
    private BookingWriter $writer;

    private PaymentMethodResolver $payment_methods;

    private BookingAnswersPersister $answers;

    private BookingGraphRemover $remover;

    private NotificationSuppressor $suppressor;


    public function __construct(
        BookingWriter $writer,
        PaymentMethodResolver $payment_methods,
        BookingAnswersPersister $answers,
        BookingGraphRemover $remover,
        NotificationSuppressor $suppressor
    ) {
        $this->writer          = $writer;
        $this->payment_methods = $payment_methods;
        $this->answers         = $answers;
        $this->remover         = $remover;
        $this->suppressor      = $suppressor;
    }


    /**
     * A failure whose compensation cannot be proven surfaces as MigrationCleanupFailedException (see
     * compensate()); otherwise the original failure is rethrown after compensation.
     *
     * @throws Throwable The original failure, after compensation succeeded.
     */
    public function persistBooking(MappedBooking $booking, PersistedEvent $event, MigrationContext $context): PersistedBooking
    {
        $ticket_ids = self::ticketIds($booking, $event);
        $persisted  = new PersistedBooking($booking->sourceId());
        $this->writer->reset();
        $this->suppressor->suppress();
        try {
            $this->persistContact($booking, $persisted, $context);
            $created        = $this->writer->createTransaction(
                $booking->timestamp(),
                $booking->total(),
                $booking->transactionStatus()
            );
            $transaction_id = $created['transaction_id'];
            $persisted->setTransaction($transaction_id, $created['total_line_item_id']);
            $this->persistRegistrations($booking, $ticket_ids, $persisted, $context);
            $this->writer->syncTransactionTotal($transaction_id);
            $this->persistPayments($booking, $persisted, $context);
            $this->answers->persist($booking, $event, $persisted, $context);
            $this->reapplyTransactionStatus($booking, $transaction_id, $context);
            $this->writer->recordCrosswalk(
                CrosswalkKeys::MODEL_TRANSACTION,
                $transaction_id,
                CrosswalkKeys::TYPE_BOOKING,
                $booking->sourceId(),
                $context
            );
            return $persisted;
        } catch (Throwable $failure) {
            $this->compensate($booking, $persisted, $context, $failure);
            throw $failure;
        } finally {
            $this->suppressor->restore();
            $this->writer->reset();
        }
    }


    private function persistContact(MappedBooking $booking, PersistedBooking $persisted, MigrationContext $context): void
    {
        $attendee = $booking->attendee();
        if ($attendee->existingId() !== null) {
            $persisted->setAttendeeId($attendee->existingId(), false);
            return;
        }
        $fields      = (array) $attendee->fieldsToCreate();
        $attendee_id = $this->writer->createContact($fields);
        $persisted->setAttendeeId($attendee_id, true);
        $this->writer->recordCrosswalk(
            CrosswalkKeys::MODEL_ATTENDEE,
            $attendee_id,
            CrosswalkKeys::TYPE_ATTENDEE,
            $booking->attendeeSourceId(),
            $context,
            $attendee->wpUserId()
        );
        $email = (string) ($fields['ATT_email'] ?? '');
        if ($email !== '') {
            $context->remember(AttendeeResolver::emailKey($email), CrosswalkKeys::MODEL_ATTENDEE, $attendee_id);
        }
    }


    /**
     * The EE ticket id of every registration, REG_count => ticket id, checked before anything is written.
     *
     * @return array<int, int>
     * @throws MigrationRecordException When a seat's ticket was not persisted for the event.
     */
    public static function ticketIds(MappedBooking $booking, PersistedEvent $event): array
    {
        $ticket_ids = [];
        foreach ($booking->registrations() as $registration) {
            $ticket_id = $event->ticketIdFor($registration->ticketSourceId());
            if ($ticket_id === null) {
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new MigrationRecordException(
                    esc_html__('The source booking is for a ticket that was not migrated.', 'migrate-events-to-event-espresso'),
                    WarningRule::RECORD_FAILED,
                    CrosswalkKeys::TYPE_BOOKING,
                    $booking->sourceId()
                );
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $ticket_ids[ $registration->count() ] = $ticket_id;
        }
        return $ticket_ids;
    }


    /**
     * The txn-status-reconciled warning for a booking whose transaction status core changed.
     */
    public static function reconciledWarning(MappedBooking $booking, string $core_status): MigrationWarning
    {
        return new MigrationWarning(
            WarningRule::TXN_STATUS_RECONCILED,
            CrosswalkKeys::TYPE_BOOKING,
            $booking->sourceId(),
            [$core_status, $booking->transactionStatus()]
        );
    }


    /**
     * @param MappedBooking    $booking    The booking.
     * @param array<int, int>  $ticket_ids REG_count => EE ticket id.
     * @param PersistedBooking $persisted  The tracker.
     * @param MigrationContext $context    The run.
     */
    private function persistRegistrations(
        MappedBooking $booking,
        array $ticket_ids,
        PersistedBooking $persisted,
        MigrationContext $context
    ): void {
        // Every seat's line item exists before the first registration: core caches each registration's final price
        // per transaction, so a line added after that cache is built would have no price to read.
        $line_item_ids = [];
        foreach ($booking->registrations() as $registration) {
            $line_item_id = $this->writer->createTicketLineItem(
                $persisted->transactionId(),
                $ticket_ids[ $registration->count() ],
                $registration->isPriceOverridden() ? $registration->pricePaid() : null
            );
            $persisted->addLineItem($line_item_id);
            $line_item_ids[ $registration->count() ] = $line_item_id;
        }
        foreach ($booking->registrations() as $registration) {
            $ticket_id       = $ticket_ids[ $registration->count() ];
            $line_item_id    = $line_item_ids[ $registration->count() ];
            $registration_id = $this->writer->createRegistration(
                $persisted->transactionId(),
                $line_item_id,
                $ticket_id,
                $registration,
                (int) $persisted->attendeeId()
            );
            $persisted->addRegistration($registration->count(), $registration_id);
            if ($registration->status() === RegStatus::APPROVED) {
                $this->writer->increaseSold($ticket_id);
                $persisted->addSoldIncrement($ticket_id);
            }
            $this->writer->recordCrosswalk(
                CrosswalkKeys::MODEL_REGISTRATION,
                $registration_id,
                CrosswalkKeys::TYPE_REGISTRATION,
                self::registrationSourceId($booking->sourceId(), $registration->count()),
                $context
            );
        }
    }


    private function persistPayments(MappedBooking $booking, PersistedBooking $persisted, MigrationContext $context): void
    {
        foreach ($booking->payments() as $payment) {
            $payment_id = $this->writer->createPayment(
                $persisted->transactionId(),
                $payment->fields(),
                $this->payment_methods->resolve($payment->gateway())
            );
            $persisted->addPayment($payment->sourceId(), $payment_id);
            $this->writer->recordCrosswalk(
                CrosswalkKeys::MODEL_PAYMENT,
                $payment_id,
                CrosswalkKeys::TYPE_PAYMENT,
                $payment->sourceId(),
                $context
            );
            $persisted->addRegistrationPayments($this->writer->processPayment($persisted->transactionId(), $payment_id));
        }
    }


    /**
     * The source status table is authoritative: core's post-payment status is replaced and reported.
     */
    private function reapplyTransactionStatus(MappedBooking $booking, int $transaction_id, MigrationContext $context): void
    {
        $core_status = $this->writer->transactionStatus($transaction_id);
        if ($core_status === $booking->transactionStatus()) {
            return;
        }
        $this->writer->setTransactionStatus($transaction_id, $booking->transactionStatus());
        $context->addWarning(self::reconciledWarning($booking, $core_status));
    }


    /**
     * @throws MigrationCleanupFailedException When the removal cannot be proven.
     */
    private function compensate(
        MappedBooking $booking,
        PersistedBooking $persisted,
        MigrationContext $context,
        Throwable $failure
    ): void {
        if (! $persisted->hasCreated()) {
            return;
        }
        if (! $this->remover->remove($persisted)) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new MigrationCleanupFailedException(
                sprintf(
                    /* translators: %1$s: source booking id, %2$d: EE transaction id, %3$s: the original error message */
                    esc_html__(
                        'Source booking %1$s failed to migrate ("%3$s") and what it created (transaction %2$d) could not be fully removed. The migration has been stopped; remove the partial transaction before retrying.',
                        'migrate-events-to-event-espresso'
                    ),
                    esc_html($booking->sourceId()),
                    $persisted->transactionId(),
                    esc_html($failure->getMessage())
                ),
                0,
                $failure
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $created_attendee = $persisted->createdAttendeeId();
        $context->forgetIds(CrosswalkKeys::MODEL_ATTENDEE, $created_attendee !== null ? [$created_attendee] : []);
        $context->forgetIds(CrosswalkKeys::MODEL_REGISTRATION, array_values($persisted->registrationIds()));
        $context->forgetIds(CrosswalkKeys::MODEL_PAYMENT, array_values($persisted->paymentIds()));
        $context->forgetIds(CrosswalkKeys::MODEL_ANSWER, $persisted->answerIds());
        $context->forgetIds(CrosswalkKeys::MODEL_CHECKIN, $persisted->checkinIds());
    }


    /**
     * "<booking id>-<REG_count>", the source key a registration is crosswalked under.
     */
    public static function registrationSourceId(string $booking_source_id, int $count): string
    {
        return $booking_source_id . '-' . $count;
    }
}
// End of file BookingPersister.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/BookingPersister.php
