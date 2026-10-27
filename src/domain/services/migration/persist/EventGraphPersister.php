<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

use DateTime;
use DateTimeImmutable;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\exceptions\MigrationCleanupFailedException;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\mapping\MappedEventGraph;
use EventEspresso\EventMigrator\domain\services\migration\mapping\SourceEventMapper;
use Throwable;

/**
 * Class EventGraphPersister
 *
 * Writes one MappedEventGraph: venue (when it must be created) → event →
 * aliases → featured image → categories → the event's crosswalk entry (its
 * fingerprint covers the image and categories) → datetimes → tickets with
 * their base prices and datetime links. Every created id is recorded in
 * the PersistedEvent the moment its save succeeds; on any Throwable the
 * EventGraphRemover removes exactly those, and a removal that cannot be
 * proven raises MigrationCleanupFailedException (REQ-021). Models receive
 * mutable DateTime objects only.
 *
 * Every model write goes through EventGraphWriter, so tests substitute the
 * writer and exercise the ordering, tracking and compensation logic.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventGraphPersister
{
    private EventGraphWriter $writer;

    private EventGraphRemover $remover;


    public function __construct(EventGraphWriter $writer, EventGraphRemover $remover)
    {
        $this->writer  = $writer;
        $this->remover = $remover;
    }


    /**
     * @throws MigrationCleanupFailedException When a failure could not be fully compensated.
     * @throws Throwable The original failure, after compensation succeeded.
     */
    public function persistEvent(MappedEventGraph $graph, MigrationContext $context): PersistedEvent
    {
        $persisted   = new PersistedEvent($graph->sourceId());
        $this->writer->reset();
        try {
            $fields           = $graph->eventFields();
            $fields['status'] = $this->guardedStatus((string) ($fields['status'] ?? 'draft'), $graph, $context);
            $venue_id         = $this->persistVenue($graph, $persisted, $context);
            if ($venue_id !== null) {
                $fields['VNU_ID'] = $venue_id;
            }
            $event_id = $this->writer->insert(CrosswalkKeys::MODEL_EVENT, $fields);
            $persisted->setEventId($event_id);
            $held_status = $graph->heldSourceStatus($fields['status']);
            if ($held_status !== '') {
                $this->writer->addSourceStatus($event_id, $held_status);
            }
            $this->persistAliases($graph, $event_id, $context);
            $this->persistFeaturedImage($graph, $event_id, $context);
            $this->persistCategories($graph, $event_id, $persisted, $context);
            // The event's crosswalk entry is recorded last so its fingerprint covers the featured image and
            // categories just applied (FingerprintFields::relationsFor()), not only the row's columns.
            $this->writer->recordCrosswalk(
                CrosswalkKeys::MODEL_EVENT,
                $event_id,
                CrosswalkKeys::TYPE_EVENT,
                $graph->sourceId(),
                $context
            );
            $this->persistDatetimes($graph, $event_id, $persisted, $context);
            $this->persistTickets($graph, $persisted, $context);
            return $persisted;
        } catch (Throwable $failure) {
            if ($persisted->hasCreated() && ! $this->remover->remove($persisted)) {
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new MigrationCleanupFailedException(
                    sprintf(
                        /* translators: %1$s: source event id, %2$d: EE event id, %3$s: the original error message */
                        esc_html__(
                            'Source event %1$s failed to migrate ("%3$s") and what it created (event %2$d) could not be fully removed. The migration has been stopped; remove the partial event before retrying.',
                            'migrate-events-to-event-espresso'
                        ),
                        esc_html($graph->sourceId()),
                        $persisted->eventId(),
                        esc_html($failure->getMessage())
                    ),
                    0,
                    $failure
                );
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            if ($persisted->hasCreated()) {
                self::forgetCreated($persisted, $context);
            }
            throw $failure;
        } finally {
            $this->writer->reset();
        }
    }


    /**
     * Visible statuses are re-checked against ee_publish_events here as well as in the mapper (REQ-011).
     */
    private function guardedStatus(string $status, MappedEventGraph $graph, MigrationContext $context): string
    {
        if (! in_array($status, SourceEventMapper::VISIBLE_STATUSES, true) || $this->canPublish()) {
            return $status;
        }
        $context->addWarning(
            new MigrationWarning(
                WarningRule::STATUS_NOT_APPLIED,
                CrosswalkKeys::TYPE_EVENT,
                $graph->sourceId(),
                [$status]
            )
        );
        return 'draft';
    }


    private function persistVenue(MappedEventGraph $graph, PersistedEvent $persisted, MigrationContext $context): ?int
    {
        $venue = $graph->venue();
        if ($venue === null) {
            return null;
        }
        if ($venue->existingId() !== null) {
            $persisted->setVenueId($venue->existingId(), false);
            return $venue->existingId();
        }
        $mapped   = $venue->toCreate();
        $venue_id = $this->writer->insert(CrosswalkKeys::MODEL_VENUE, $mapped->fields());
        $persisted->setVenueId($venue_id, true);
        $this->writer->recordCrosswalk(
            CrosswalkKeys::MODEL_VENUE,
            $venue_id,
            CrosswalkKeys::TYPE_VENUE,
            $mapped->sourceId(),
            $context
        );
        return $venue_id;
    }


    /**
     * The other occurrences of a collapsed series, and the series itself, resolve to this event.
     */
    private function persistAliases(MappedEventGraph $graph, int $event_id, MigrationContext $context): void
    {
        foreach ($graph->aliasIds() as $alias) {
            $this->writer->recordAlias(CrosswalkKeys::MODEL_EVENT, $event_id, CrosswalkKeys::TYPE_EVENT, $alias, $context);
        }
        if ($graph->seriesId() !== null) {
            $this->writer->recordAlias(
                CrosswalkKeys::MODEL_EVENT,
                $event_id,
                CrosswalkKeys::TYPE_SERIES,
                $graph->seriesId(),
                $context
            );
        }
    }


    /**
     * A known attachment becomes the featured image. A URL-only image is not downloaded during the
     * migration and is reported instead.
     */
    private function persistFeaturedImage(MappedEventGraph $graph, int $event_id, MigrationContext $context): void
    {
        $attachment_id = $graph->featuredImageId();
        $assigned      = $attachment_id !== null && $this->writer->assignFeaturedImage($event_id, $attachment_id);
        if ($assigned || ($attachment_id === null && $graph->featuredImageUrl() === '')) {
            return;
        }
        $context->addWarning(self::featuredImageWarning($graph));
    }


    /**
     * The not-modelled warning for a featured image that could not be carried over.
     */
    public static function featuredImageWarning(MappedEventGraph $graph): MigrationWarning
    {
        return new MigrationWarning(
            WarningRule::NOT_MODELLED,
            CrosswalkKeys::TYPE_EVENT,
            $graph->sourceId(),
            [__('featured image', 'migrate-events-to-event-espresso')]
        );
    }


    /**
     * After compensation removed them, later lookups in the run must not resolve to these ids.
     */
    private static function forgetCreated(PersistedEvent $persisted, MigrationContext $context): void
    {
        $context->forgetIds(CrosswalkKeys::MODEL_EVENT, array_filter([$persisted->eventId()]));
        $context->forgetIds(CrosswalkKeys::MODEL_VENUE, array_filter([(int) $persisted->createdVenueId()]));
        $context->forgetIds(CrosswalkKeys::MODEL_DATETIME, array_values($persisted->datetimeIds()));
        $context->forgetIds(CrosswalkKeys::MODEL_TICKET, array_values($persisted->ticketIds()));
        $context->forgetIds(CrosswalkKeys::MODEL_PRICE, array_values($persisted->priceIds()));
        $context->forgetIds(CrosswalkKeys::MODEL_TERM_TAXONOMY, $persisted->createdTermTaxonomyIds());
    }


    private function persistCategories(
        MappedEventGraph $graph,
        int $event_id,
        PersistedEvent $persisted,
        MigrationContext $context
    ): void {
        foreach ($graph->categories() as $name) {
            $category = $this->writer->addCategory($event_id, $name);
            if (! $category['created']) {
                continue;
            }
            $persisted->addCreatedTermTaxonomy($category['id']);
            $this->writer->recordCrosswalk(
                CrosswalkKeys::MODEL_TERM_TAXONOMY,
                $category['id'],
                CrosswalkKeys::TYPE_CATEGORY,
                sanitize_title($name),
                $context
            );
        }
    }


    private function persistDatetimes(
        MappedEventGraph $graph,
        int $event_id,
        PersistedEvent $persisted,
        MigrationContext $context
    ): void {
        foreach ($graph->datetimes() as $datetime) {
            $fields           = $datetime->fields();
            $fields['EVT_ID'] = $event_id;
            $datetime_id      = $this->writer->insert(CrosswalkKeys::MODEL_DATETIME, self::modelReady($fields));
            $persisted->addDatetime($datetime->sourceId(), $datetime_id);
            $this->writer->recordCrosswalk(
                CrosswalkKeys::MODEL_DATETIME,
                $datetime_id,
                CrosswalkKeys::TYPE_DATETIME,
                $datetime->sourceId(),
                $context
            );
        }
    }


    private function persistTickets(MappedEventGraph $graph, PersistedEvent $persisted, MigrationContext $context): void
    {
        foreach ($graph->tickets() as $ticket) {
            $ticket_id = $this->writer->insert(CrosswalkKeys::MODEL_TICKET, self::modelReady($ticket->fields()));
            $persisted->addTicket($ticket->sourceId(), $ticket_id);
            $this->writer->recordCrosswalk(
                CrosswalkKeys::MODEL_TICKET,
                $ticket_id,
                CrosswalkKeys::TYPE_TICKET,
                $ticket->sourceId(),
                $context
            );
            $price_id = $this->writer->insert(CrosswalkKeys::MODEL_PRICE, $ticket->priceFields());
            $persisted->addPrice($ticket->sourceId(), $price_id);
            $this->writer->recordCrosswalk(
                CrosswalkKeys::MODEL_PRICE,
                $price_id,
                CrosswalkKeys::TYPE_PRICE,
                $ticket->sourceId(),
                $context
            );
            $this->writer->linkTicketToPrice($ticket_id, $price_id);
            $refs         = $ticket->datetimeSourceIds();
            $datetime_ids = $refs === []
                ? $persisted->datetimeIds()
                : array_intersect_key($persisted->datetimeIds(), array_flip($refs));
            foreach ($datetime_ids as $datetime_id) {
                $this->writer->linkDatetimeToTicket($datetime_id, $ticket_id);
            }
        }
    }


    /**
     * The fields with every DateTimeImmutable replaced by a mutable DateTime, the only date object
     * EE_Datetime_Field accepts without re-parsing.
     */
    public static function modelReady(array $fields): array
    {
        foreach ($fields as $name => $value) {
            if ($value instanceof DateTimeImmutable) {
                $fields[ $name ] = DateTime::createFromImmutable($value);
            }
        }
        return $fields;
    }


    protected function canPublish(): bool
    {
        return current_user_can('ee_publish_events');
    }
}
// End of file EventGraphPersister.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/EventGraphPersister.php
