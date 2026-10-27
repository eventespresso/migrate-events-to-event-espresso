<?php

namespace EventEspresso\EventMigrator\domain\services\migration\run;

use EEM_Payment;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\records\SourceBookingRecord;
use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;
use EventEspresso\EventMigrator\application\services\migration\SourceAdapter;
use EventEspresso\EventMigrator\application\services\migration\SourceCapabilities;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\exceptions\MigrationCleanupFailedException;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\exceptions\UnmappedStatusException;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\mapping\MappedBooking;
use EventEspresso\EventMigrator\domain\services\migration\mapping\SourceBookingMapper;
use EventEspresso\EventMigrator\domain\services\migration\persist\NotificationSuppressor;
use EventEspresso\EventMigrator\domain\services\migration\persist\PersistedBooking;
use EventEspresso\EventMigrator\domain\services\migration\persist\PersistedEvent;
use EventEspresso\EventMigrator\domain\services\migration\persist\Persister;
use Exception;

/**
 * Class MigrationRunner
 *
 * One unit of migration work per call (REQ-021, REQ-104): either one source
 * event's graph (EventStep), or one chunk of that event's bookings. Bookings
 * are mapped and persisted one at a time inside NotificationSuppressor, a
 * crosswalk hit is skipped as already-migrated (REQ-009), a record-level
 * failure is reported and the run continues, and a failed compensation
 * (MigrationCleanupFailedException) is left to stop the job. Warnings and
 * reuse notes are moved from the context into the run record, and to the log,
 * after every record.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationRunner
{
    /**
     * Bookings per batch request.
     */
    public const BOOKING_CHUNK = 25;

    private EventStep $event_step;

    private SourceBookingMapper $booking_mapper;

    private Crosswalk $crosswalk;

    private NotificationSuppressor $suppressor;

    private MigrationLog $log;


    public function __construct(
        EventStep $event_step,
        SourceBookingMapper $booking_mapper,
        Crosswalk $crosswalk,
        NotificationSuppressor $suppressor,
        MigrationLog $log
    ) {
        $this->event_step     = $event_step;
        $this->booking_mapper = $booking_mapper;
        $this->crosswalk      = $crosswalk;
        $this->suppressor     = $suppressor;
        $this->log            = $log;
    }


    /**
     * Does the next unit of work and moves the cursor on.
     *
     * @throws MigrationCleanupFailedException When a failed record's partial writes could not be removed.
     */
    public function step(
        SourceAdapter $adapter,
        Persister $persister,
        MigrationContext $context,
        MigrationCursor $cursor,
        MigrationRunRecord $record
    ): void {
        if ($cursor->isFinished()) {
            return;
        }
        if ($cursor->phase() === MigrationCursor::PHASE_EVENT) {
            $this->eventStep($adapter, $persister, $context, $cursor, $record);
        } else {
            $this->bookingStep($adapter, $persister, $context, $cursor, $record);
        }
        $this->drain($context, $record);
    }


    /**
     * @throws MigrationCleanupFailedException
     */
    private function eventStep(
        SourceAdapter $adapter,
        Persister $persister,
        MigrationContext $context,
        MigrationCursor $cursor,
        MigrationRunRecord $record
    ): void {
        $source_id = (string) $cursor->currentEventId();
        try {
            $current = $this->event_step->run($source_id, $adapter, $persister, $context, $record);
        } catch (MigrationCleanupFailedException $cleanup_failed) {
            throw $cleanup_failed;
        } catch (Exception $exception) {
            $this->recordFailure($exception, CrosswalkKeys::TYPE_EVENT, $source_id, 'event', $context, $record);
            $current = null;
        }
        $wants_bookings = $context->options()->includesBookings()
            && $adapter->capabilities()->provides(SourceCapabilities::DOMAIN_BOOKINGS);
        if ($current === null || ! $wants_bookings) {
            $cursor->nextEvent();
            return;
        }
        $cursor->startBookings($current);
    }


    /**
     * @throws MigrationCleanupFailedException
     */
    private function bookingStep(
        SourceAdapter $adapter,
        Persister $persister,
        MigrationContext $context,
        MigrationCursor $cursor,
        MigrationRunRecord $record
    ): void {
        $current  = (array) $cursor->current();
        $bookings = $adapter->readBookings((string) $current['source_id'], $cursor->bookingOffset(), self::BOOKING_CHUNK);
        $this->prefetch($bookings, $context);
        $event        = EventStep::persistedFrom($current);
        $prices       = array_map('floatval', (array) ($current['ticket_prices'] ?? []));
        $status_table = $adapter->statusTable();
        $this->suppressor->during(
            function () use ($bookings, $event, $prices, $status_table, $persister, $context, $record): void {
                foreach ($bookings as $booking) {
                    $this->migrateBooking($booking, $event, $prices, $status_table, $persister, $context, $record);
                    $this->drain($context, $record);
                }
            }
        );
        $cursor->advanceBookings(count($bookings));
        if (count($bookings) < self::BOOKING_CHUNK) {
            $cursor->nextEvent();
        }
    }


    /**
     * Migrates one booking, or skips it when an earlier run already did.
     *
     * @param SourceBookingRecord  $booking      The source booking.
     * @param PersistedEvent       $event        The ids of the booking's event.
     * @param array<string, float> $prices       Source ticket id => migrated ticket price.
     * @param StatusTable          $status_table The source's status table.
     * @param Persister            $persister    The run's persister.
     * @param MigrationContext     $context      The run context.
     * @param MigrationRunRecord   $record       The run record that receives the counts.
     * @throws MigrationCleanupFailedException
     */
    private function migrateBooking(
        SourceBookingRecord $booking,
        PersistedEvent $event,
        array $prices,
        StatusTable $status_table,
        Persister $persister,
        MigrationContext $context,
        MigrationRunRecord $record
    ): void {
        $source = $context->source();
        $id     = $booking->sourceId();
        if (
            $this->crosswalk->targetId($source, CrosswalkKeys::TYPE_BOOKING, $id, CrosswalkKeys::MODEL_TRANSACTION, $context)
            !== null
        ) {
            $context->addWarning(new MigrationWarning(WarningRule::ALREADY_MIGRATED, CrosswalkKeys::TYPE_BOOKING, $id));
            $record->increment('booking', MigrationRunRecord::COUNT_SKIPPED);
            return;
        }
        if ($this->crosswalk->isTargetDeleted($source, CrosswalkKeys::TYPE_BOOKING, $id, $context)) {
            // findTarget() added the target-deleted warning; a deleted transaction is never re-created.
            $record->increment('booking', MigrationRunRecord::COUNT_SKIPPED);
            return;
        }
        $mapped = null;
        try {
            $mapped    = $this->booking_mapper->map($booking, $prices, $status_table, $context);
            $persisted = $persister->persistBooking($mapped, $event, $context);
        } catch (MigrationCleanupFailedException $cleanup_failed) {
            throw $cleanup_failed;
        } catch (MigrationRecordException $deleted) {
            $this->tally($record, $booking, $event, $mapped, null, $context);
            if ($deleted->rule() !== WarningRule::TARGET_DELETED) {
                $this->recordFailure($deleted, CrosswalkKeys::TYPE_BOOKING, $id, 'booking', $context, $record);
                return;
            }
            // The booking's contact was migrated earlier and has since been deleted: reported, not re-created.
            $record->increment('booking', MigrationRunRecord::COUNT_SKIPPED);
            return;
        } catch (Exception $exception) {
            $this->tally($record, $booking, $event, $mapped, null, $context);
            $this->recordFailure($exception, CrosswalkKeys::TYPE_BOOKING, $id, 'booking', $context, $record);
            return;
        }
        $this->tally($record, $booking, $event, $mapped, $persisted, $context);
        $record->increment('booking', MigrationRunRecord::COUNT_CREATED);
        $record->increment('registration', MigrationRunRecord::COUNT_CREATED, count($persisted->registrationIds()));
        $record->increment('payment', MigrationRunRecord::COUNT_CREATED, count($persisted->paymentIds()));
        if ($persisted->createdAttendeeId() !== null) {
            $record->increment('attendee', MigrationRunRecord::COUNT_CREATED);
        }
        foreach ($persisted->soldIncrements() as $ticket_id => $quantity) {
            $record->addSoldAdjustment($ticket_id, $quantity);
        }
    }


    /**
     * Adds one booking to the run's reconciliation tally: its seats against the registrations created, and its
     * approved payments (refunds negative) per source currency against what was created. Nothing was created
     * when $persisted is null; nothing is known about payments when $mapped is null.
     */
    private function tally(
        MigrationRunRecord $record,
        SourceBookingRecord $booking,
        PersistedEvent $event,
        ?MappedBooking $mapped,
        ?PersistedBooking $persisted,
        MigrationContext $context
    ): void {
        $record->tally()->addBooking(
            $event->sourceId(),
            $event->eventId(),
            $booking->seatCount(),
            $persisted !== null ? count($persisted->registrationIds()) : 0
        );
        if ($mapped === null) {
            return;
        }
        $approved = [];
        foreach ($mapped->payments() as $payment) {
            if ($payment->status() === EEM_Payment::status_id_approved) {
                $approved[ $payment->currency() ] = ($approved[ $payment->currency() ] ?? 0.0) + $payment->amount();
            }
        }
        $record->tally()->addPayments(
            $approved,
            $context->siteCurrency(),
            $persisted !== null ? array_sum($approved) : 0.0
        );
    }


    /**
     * One query per model answers "already migrated?" for the whole chunk (bookings and their contacts).
     *
     * @param SourceBookingRecord[] $bookings
     */
    private function prefetch(array $bookings, MigrationContext $context): void
    {
        if ($bookings === []) {
            return;
        }
        $booking_ids  = [];
        $attendee_ids = [];
        foreach ($bookings as $booking) {
            $booking_ids[]  = $booking->sourceId();
            $attendee_ids[] = $booking->attendee()->sourceId();
        }
        $source = $context->source();
        $this->crosswalk->prefetch($source, CrosswalkKeys::TYPE_BOOKING, $booking_ids, CrosswalkKeys::MODEL_TRANSACTION, $context);
        $this->crosswalk->prefetch(
            $source,
            CrosswalkKeys::TYPE_ATTENDEE,
            array_values(array_unique($attendee_ids)),
            CrosswalkKeys::MODEL_ATTENDEE,
            $context
        );
    }


    /**
     * Reports a record that could not be migrated under its rule and counts it as failed.
     */
    private function recordFailure(
        Exception $exception,
        string $type,
        string $id,
        string $entity,
        MigrationContext $context,
        MigrationRunRecord $record
    ): void {
        $message = html_entity_decode($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        if ($exception instanceof UnmappedStatusException) {
            $warning = new MigrationWarning(
                WarningRule::STATUS_UNMAPPED,
                $exception->sourceType() !== '' ? $exception->sourceType() : $type,
                $exception->sourceId() !== '' ? $exception->sourceId() : $id,
                [$exception->domain(), $exception->rawStatus()]
            );
        } elseif ($exception instanceof MigrationRecordException) {
            $warning = new MigrationWarning(
                $exception->rule(),
                $exception->sourceType() !== '' ? $exception->sourceType() : $type,
                $exception->sourceId() !== '' ? $exception->sourceId() : $id,
                [$message]
            );
        } else {
            $warning = new MigrationWarning(WarningRule::RECORD_FAILED, $type, $id, [$message]);
        }
        $context->addWarning($warning);
        $record->increment($entity, MigrationRunRecord::COUNT_FAILED);
    }


    /**
     * Moves the context's warnings and reuse notes into the run record and the log.
     */
    private function drain(MigrationContext $context, MigrationRunRecord $record): void
    {
        foreach ($context->drainWarnings() as $warning) {
            $record->addWarning($warning);
            $this->log->append($record->logSecret(), $warning->rule(), $warning->sourceType(), $warning->sourceId());
        }
        foreach ($context->drainReuseNotes() as $note) {
            $record->addReuseNote($note);
            $record->increment(strtolower((string) $note['model']), MigrationRunRecord::COUNT_REUSED);
        }
    }
}
// End of file MigrationRunner.php
// Location: EventEspresso\EventMigrator\domain\services\migration\run/MigrationRunner.php
