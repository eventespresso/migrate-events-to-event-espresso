<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

use EE_Attendee;
use EE_Base_Class;
use EE_Registration;
use EE_Ticket;
use EEM_Answer;
use EEM_Attendee;
use EEM_Base;
use EEM_Checkin;
use EEM_Line_Item;
use EEM_Payment;
use EEM_Registration;
use EEM_Registration_Payment;
use EEM_Ticket;
use EEM_Transaction;
use EventEspresso\core\domain\services\registration\RegStatus;
use Throwable;
use EventEspresso\EventMigrator\domain\services\migration\run\ThrowableLogger;

/**
 * Class BookingGraphRemover
 *
 * Hard-deletes what one persistBooking() call created, from the ids in its
 * PersistedBooking, in reverse dependency order (check-ins, answers,
 * registration payments, registrations, payments, line items, transaction,
 * and the contact when this call created it and nothing else uses it), then
 * proves every row is gone with exists_by_ID(). Sold counts are reversed with
 * EE_Ticket::decreaseSold() per approved registration whose delete is proven, up to the increments the call
 * recorded.
 * Never calls set_status(), so no cancellation messages, webhooks or
 * wait-list listeners run.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class BookingGraphRemover
{
    /**
     * True only when everything the call created provably no longer exists; any Throwable is logged and
     * becomes false.
     */
    public function remove(PersistedBooking $persisted): bool
    {
        try {
            $this->deleteAll(EEM_Checkin::instance(), $persisted->checkinIds());
            $this->deleteAll(EEM_Answer::instance(), $persisted->answerIds());
            $this->deleteAll(EEM_Registration_Payment::instance(), $persisted->registrationPaymentIds());
            $this->removeRegistrations($persisted);
            $this->deleteAll(EEM_Payment::instance(), $persisted->paymentIds());
            TransactionLineItems::deleteAll($persisted->transactionId(), $persisted->totalLineItemId());
            $this->deleteAll(EEM_Transaction::instance(), $persisted->transactionId() !== 0 ? [$persisted->transactionId()] : []);
            $this->deleteOrphanedContact($persisted->createdAttendeeId());
            return $this->allGone($persisted);
        } catch (Throwable $cleanup_exception) {
            ThrowableLogger::log(
                $cleanup_exception,
                sprintf(
                    'BookingGraphRemover: failed to clean up partially-created booking %s (transaction %d).',
                    $persisted->sourceId(),
                    $persisted->transactionId()
                )
            );
            return false;
        }
    }


    /**
     * @param EEM_Base $model The model of the records.
     * @param int[]    $ids   The record ids to hard-delete.
     */
    private function deleteAll(EEM_Base $model, array $ids): void
    {
        foreach ($ids as $id) {
            $object = $model->get_one_by_ID($id);
            if ($object instanceof EE_Base_Class) {
                $object->delete_permanently();
            }
        }
    }


    /**
     * Deletes the call's registrations one at a time and gives back an approved registration's seat only once its
     * delete is proven, never more seats per ticket than this call added; so a compensation that fails part-way
     * and is followed by a rollback cannot decrement a sold count twice (as RunEntityRemover does).
     */
    private function removeRegistrations(PersistedBooking $persisted): void
    {
        $owed = $persisted->soldIncrements();
        foreach ($persisted->registrationIds() as $registration_id) {
            $registration = EEM_Registration::instance()->get_one_by_ID($registration_id);
            if (! $registration instanceof EE_Registration) {
                continue;
            }
            $ticket_id = (int) $registration->ticket_ID();
            $approved  = $registration->status_ID() === RegStatus::APPROVED;
            $registration->delete_permanently();
            if (
                ! $approved
                || ($owed[ $ticket_id ] ?? 0) < 1
                || EEM_Registration::instance()->exists_by_ID($registration_id)
            ) {
                continue;
            }
            $owed[ $ticket_id ]--;
            $ticket = EEM_Ticket::instance()->get_one_by_ID($ticket_id);
            if ($ticket instanceof EE_Ticket) {
                $ticket->decreaseSold();
            }
        }
    }


    /**
     * A contact this call created is removed only when no registration (from another booking) uses it.
     */
    private function deleteOrphanedContact(?int $attendee_id): void
    {
        if ($attendee_id === null || EEM_Registration::instance()->count([['ATT_ID' => $attendee_id]]) > 0) {
            return;
        }
        $attendee = EEM_Attendee::instance()->get_one_by_ID($attendee_id);
        if ($attendee instanceof EE_Attendee) {
            $attendee->delete_permanently();
        }
    }


    private function allGone(PersistedBooking $persisted): bool
    {
        $attendee_id = $persisted->createdAttendeeId();
        $orphaned    = $attendee_id !== null
            && EEM_Registration::instance()->count([['ATT_ID' => $attendee_id]]) === 0;
        $checks      = [
            [EEM_Checkin::instance(), $persisted->checkinIds()],
            [EEM_Answer::instance(), $persisted->answerIds()],
            [EEM_Registration_Payment::instance(), $persisted->registrationPaymentIds()],
            [EEM_Registration::instance(), $persisted->registrationIds()],
            [EEM_Payment::instance(), $persisted->paymentIds()],
            [EEM_Line_Item::instance(), array_merge($persisted->lineItemIds(), array_filter([$persisted->totalLineItemId()]))],
            [EEM_Transaction::instance(), array_filter([$persisted->transactionId()])],
            [EEM_Attendee::instance(), $orphaned ? [$attendee_id] : []],
        ];
        foreach ($checks as [$model, $ids]) {
            foreach ($ids as $id) {
                if ($model->exists_by_ID($id)) {
                    return false;
                }
            }
        }
        return true;
    }
}
// End of file BookingGraphRemover.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/BookingGraphRemover.php
