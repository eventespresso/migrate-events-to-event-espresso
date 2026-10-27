<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

use EEM_CPT_Base;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\mapping\MappedBooking;
use EventEspresso\EventMigrator\domain\services\migration\mapping\MappedEventGraph;
use EventEspresso\EventMigrator\domain\services\migration\normalize\AttendeeResolver;
use WP_Post;

/**
 * Class DryRunPersister
 *
 * The Persister of a dry run (REQ-007): writes nothing, hands out negative
 * synthetic ids, and remembers every source key on the run context exactly as
 * the real writers would through the crosswalk, so venues, contacts and
 * aliases resolve within the run and the dry-run report carries the same
 * warnings as a real run (missing featured image, check-in on an unmigrated
 * date, transaction status core would change).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class DryRunPersister implements Persister
{
    private int $next_id = -1;


    /**
     * The next synthetic id this persister will hand out, for carrying it to the next batch request.
     */
    public function nextSyntheticId(): int
    {
        return $this->next_id;
    }


    /**
     * Continues a dry run's synthetic ids from an earlier batch request, so ids stay unique across the run.
     */
    public function resumeSyntheticIds(int $next_id): void
    {
        $this->next_id = min(-1, $next_id);
    }


    /**
     * {@inheritDoc}
     *
     * Writes nothing: returns synthetic negative ids and remembers them on the context, so the dry run counts
     * and warns exactly as a real run would.
     *
     * @since 0.0.1.000
     */
    public function persistEvent(MappedEventGraph $graph, MigrationContext $context): PersistedEvent
    {
        $persisted = new PersistedEvent($graph->sourceId());
        $venue     = $graph->venue();
        if ($venue !== null && $venue->existingId() !== null) {
            $persisted->setVenueId($venue->existingId(), false);
        } elseif ($venue !== null) {
            $persisted->setVenueId($this->remember($context, CrosswalkKeys::MODEL_VENUE, CrosswalkKeys::TYPE_VENUE, $venue->toCreate()->sourceId()), true);
        }
        $event_id = $this->remember($context, CrosswalkKeys::MODEL_EVENT, CrosswalkKeys::TYPE_EVENT, $graph->sourceId());
        $persisted->setEventId($event_id);
        foreach ($graph->aliasIds() as $alias) {
            $this->rememberAs($context, CrosswalkKeys::MODEL_EVENT, CrosswalkKeys::TYPE_EVENT, $alias, $event_id);
        }
        if ($graph->seriesId() !== null) {
            $this->rememberAs($context, CrosswalkKeys::MODEL_EVENT, CrosswalkKeys::TYPE_SERIES, $graph->seriesId(), $event_id);
        }
        $attachment_id = $graph->featuredImageId();
        if (
            ($attachment_id !== null && ! $this->attachmentExists($attachment_id))
            || ($attachment_id === null && $graph->featuredImageUrl() !== '')
        ) {
            $context->addWarning(EventGraphPersister::featuredImageWarning($graph));
        }
        foreach ($graph->categories() as $name) {
            $this->category($name, $persisted, $context);
        }
        foreach ($graph->datetimes() as $datetime) {
            $persisted->addDatetime(
                $datetime->sourceId(),
                $this->remember($context, CrosswalkKeys::MODEL_DATETIME, CrosswalkKeys::TYPE_DATETIME, $datetime->sourceId())
            );
        }
        foreach ($graph->tickets() as $ticket) {
            $persisted->addTicket(
                $ticket->sourceId(),
                $this->remember($context, CrosswalkKeys::MODEL_TICKET, CrosswalkKeys::TYPE_TICKET, $ticket->sourceId())
            );
            $persisted->addPrice(
                $ticket->sourceId(),
                $this->remember($context, CrosswalkKeys::MODEL_PRICE, CrosswalkKeys::TYPE_PRICE, $ticket->sourceId())
            );
        }
        return $persisted;
    }


    /**
     * {@inheritDoc}
     *
     * Writes nothing: returns synthetic negative ids for what a real run would create.
     *
     * @since 0.0.1.000
     */
    public function persistBooking(MappedBooking $booking, PersistedEvent $event, MigrationContext $context): PersistedBooking
    {
        $ticket_ids = BookingPersister::ticketIds($booking, $event);
        $persisted  = new PersistedBooking($booking->sourceId());
        $this->contact($booking, $persisted, $context);
        $persisted->setTransaction($this->nextId(), $this->nextId());
        foreach ($booking->registrations() as $registration) {
            $persisted->addLineItem($this->nextId());
            $persisted->addRegistration(
                $registration->count(),
                $this->remember(
                    $context,
                    CrosswalkKeys::MODEL_REGISTRATION,
                    CrosswalkKeys::TYPE_REGISTRATION,
                    BookingPersister::registrationSourceId($booking->sourceId(), $registration->count())
                )
            );
            if ($registration->status() === 'RAP') {
                $persisted->addSoldIncrement($ticket_ids[ $registration->count() ]);
            }
        }
        foreach ($booking->payments() as $payment) {
            $persisted->addPayment(
                $payment->sourceId(),
                $this->remember($context, CrosswalkKeys::MODEL_PAYMENT, CrosswalkKeys::TYPE_PAYMENT, $payment->sourceId())
            );
        }
        foreach ($booking->answers() as $answer) {
            $persisted->addAnswer(
                $this->remember(
                    $context,
                    CrosswalkKeys::MODEL_ANSWER,
                    CrosswalkKeys::TYPE_ANSWER,
                    $booking->sourceId() . ':' . $answer->fieldSourceId()
                )
            );
        }
        foreach ($booking->checkins() as $checkin) {
            $source_id = $booking->sourceId() . ':' . $checkin->datetimeSourceId();
            if ($event->datetimeIdFor($checkin->datetimeSourceId()) === null) {
                $context->addWarning(BookingAnswersPersister::missingDatetimeWarning($source_id));
                continue;
            }
            $persisted->addCheckin($this->remember($context, CrosswalkKeys::MODEL_CHECKIN, CrosswalkKeys::TYPE_CHECKIN, $source_id));
        }
        $predicted = TransactionStatusRule::afterPayments(
            $booking->total(),
            $booking->payments(),
            $booking->transactionStatus()
        );
        if ($predicted !== $booking->transactionStatus()) {
            $context->addWarning(BookingPersister::reconciledWarning($booking, $predicted));
        }
        $this->rememberAs(
            $context,
            CrosswalkKeys::MODEL_TRANSACTION,
            CrosswalkKeys::TYPE_BOOKING,
            $booking->sourceId(),
            $persisted->transactionId()
        );
        return $persisted;
    }


    protected function attachmentExists(int $attachment_id): bool
    {
        $attachment = get_post($attachment_id);
        return $attachment instanceof WP_Post && $attachment->post_type === 'attachment';
    }


    protected function categoryExists(string $name): bool
    {
        return term_exists($name, EEM_CPT_Base::EVENT_CATEGORY_TAXONOMY) !== null;
    }


    /**
     * Counts a category as created the first time the run meets a name the site does not have, as the real
     * writer would (EEM_CPT_Base::add_event_category() reuses an existing term).
     */
    private function category(string $name, PersistedEvent $persisted, MigrationContext $context): void
    {
        $src_key = Crosswalk::sourceKey($context->source(), CrosswalkKeys::TYPE_CATEGORY, sanitize_title($name));
        if ($context->hasLookup($src_key, CrosswalkKeys::MODEL_TERM_TAXONOMY) || $this->categoryExists($name)) {
            return;
        }
        $persisted->addCreatedTermTaxonomy(
            $this->remember($context, CrosswalkKeys::MODEL_TERM_TAXONOMY, CrosswalkKeys::TYPE_CATEGORY, sanitize_title($name))
        );
    }


    private function contact(MappedBooking $booking, PersistedBooking $persisted, MigrationContext $context): void
    {
        $attendee = $booking->attendee();
        if ($attendee->existingId() !== null) {
            $persisted->setAttendeeId($attendee->existingId(), false);
            return;
        }
        $attendee_id = $this->remember(
            $context,
            CrosswalkKeys::MODEL_ATTENDEE,
            CrosswalkKeys::TYPE_ATTENDEE,
            $booking->attendeeSourceId()
        );
        $persisted->setAttendeeId($attendee_id, true);
        $email = (string) (($attendee->fieldsToCreate() ?? [])['ATT_email'] ?? '');
        if ($email !== '') {
            $context->remember(AttendeeResolver::emailKey($email), CrosswalkKeys::MODEL_ATTENDEE, $attendee_id);
        }
    }


    /**
     * A new synthetic id remembered under the source key.
     */
    private function remember(MigrationContext $context, string $model, string $type, string $source_id): int
    {
        $id = $this->nextId();
        $this->rememberAs($context, $model, $type, $source_id, $id);
        return $id;
    }


    private function rememberAs(MigrationContext $context, string $model, string $type, string $source_id, int $id): void
    {
        $context->remember(Crosswalk::sourceKey($context->source(), $type, $source_id), $model, $id);
    }


    private function nextId(): int
    {
        return $this->next_id--;
    }
}
// End of file DryRunPersister.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/DryRunPersister.php
