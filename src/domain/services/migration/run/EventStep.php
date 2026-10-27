<?php

namespace EventEspresso\EventMigrator\domain\services\migration\run;

use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\SourceAdapter;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\exceptions\MigrationCleanupFailedException;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\mapping\MappedEventGraph;
use EventEspresso\EventMigrator\domain\services\migration\mapping\SourceEventMapper;
use EventEspresso\EventMigrator\domain\services\migration\normalize\EventGroup;
use EventEspresso\EventMigrator\domain\services\migration\normalize\RecurrenceStrategy;
use EventEspresso\EventMigrator\domain\services\migration\persist\PersistedEvent;
use EventEspresso\EventMigrator\domain\services\migration\persist\Persister;

/**
 * Class EventStep
 *
 * Migrates one source event's graph: read → RecurrenceStrategy::group() →
 * crosswalk check → SourceEventMapper::map() → Persister::persistEvent(). An
 * event already migrated by an earlier run is not written again (REQ-009),
 * but its ids are looked up so its new bookings can still be migrated.
 * Returns the cursor's "current" array for the booking phase, or null when
 * the event was skipped.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventStep
{
    private RecurrenceStrategy $recurrence;

    private SourceEventMapper $mapper;

    private Crosswalk $crosswalk;


    public function __construct(RecurrenceStrategy $recurrence, SourceEventMapper $mapper, Crosswalk $crosswalk)
    {
        $this->recurrence = $recurrence;
        $this->mapper     = $mapper;
        $this->crosswalk  = $crosswalk;
    }


    /**
     * @return array|null {source_id, event_id, datetime_ids, ticket_ids, ticket_prices}, or null when skipped.
     * @throws MigrationRecordException When the event cannot be read or mapped.
     * @throws MigrationCleanupFailedException When a failed persist could not be compensated.
     */
    public function run(
        string $source_event_id,
        SourceAdapter $adapter,
        Persister $persister,
        MigrationContext $context,
        MigrationRunRecord $record
    ): ?array {
        $options = $context->options();
        $groups  = $this->recurrence->group(
            [$adapter->readEvent($source_event_id, $options)],
            $options->recurrence(),
            $context
        );
        $group   = $groups[0] ?? null;
        if (! $group instanceof EventGroup) {
            return null;
        }
        $source = $context->source();
        $this->crosswalk->prefetch(
            $source,
            CrosswalkKeys::TYPE_EVENT,
            $group->occurrenceIds(),
            CrosswalkKeys::MODEL_EVENT,
            $context
        );
        $existing = $this->crosswalk->targetId(
            $source,
            CrosswalkKeys::TYPE_EVENT,
            $group->event()->sourceId(),
            CrosswalkKeys::MODEL_EVENT,
            $context
        );
        if (
            $existing === null
            && $this->crosswalk->isTargetDeleted($source, CrosswalkKeys::TYPE_EVENT, $group->event()->sourceId(), $context)
        ) {
            // findTarget() added the target-deleted warning; neither the event nor its bookings are migrated again.
            $record->increment('event', MigrationRunRecord::COUNT_SKIPPED);
            return null;
        }
        if ($existing !== null) {
            $context->addWarning(
                new MigrationWarning(WarningRule::ALREADY_MIGRATED, CrosswalkKeys::TYPE_EVENT, $group->event()->sourceId())
            );
            $record->increment('event', MigrationRunRecord::COUNT_SKIPPED);
            return $this->existing($group, $existing, $adapter, $context);
        }
        $graph = $this->mapper->map($group, $adapter->statusTable(), $context);
        if ($graph === null) {
            $record->increment('event', MigrationRunRecord::COUNT_SKIPPED);
            return null;
        }
        $persisted = $persister->persistEvent($graph, $context);
        $record->increment('event', MigrationRunRecord::COUNT_CREATED);
        $record->increment('datetime', MigrationRunRecord::COUNT_CREATED, count($persisted->datetimeIds()));
        $record->increment('ticket', MigrationRunRecord::COUNT_CREATED, count($persisted->ticketIds()));
        $record->increment('category', MigrationRunRecord::COUNT_CREATED, count($persisted->createdTermTaxonomyIds()));
        if ($persisted->createdVenueId() !== null) {
            $record->increment('venue', MigrationRunRecord::COUNT_CREATED);
        }
        return self::currentFrom($persisted, self::ticketPrices($graph));
    }


    /**
     * The cursor's "current" array for an event graph.
     *
     * @param PersistedEvent       $persisted     The event's ids.
     * @param array<string, float> $ticket_prices Source ticket id => migrated ticket price.
     */
    public static function currentFrom(PersistedEvent $persisted, array $ticket_prices): array
    {
        return [
            'source_id'     => $persisted->sourceId(),
            'event_id'      => $persisted->eventId(),
            'datetime_ids'  => $persisted->datetimeIds(),
            'ticket_ids'    => $persisted->ticketIds(),
            'ticket_prices' => $ticket_prices,
        ];
    }


    /**
     * Rebuilds the PersistedEvent a booking chunk needs from the cursor's "current" array.
     */
    public static function persistedFrom(array $current): PersistedEvent
    {
        $persisted = new PersistedEvent((string) ($current['source_id'] ?? ''));
        $persisted->setEventId((int) ($current['event_id'] ?? 0));
        foreach ((array) ($current['datetime_ids'] ?? []) as $source_id => $id) {
            $persisted->addDatetime((string) $source_id, (int) $id);
        }
        foreach ((array) ($current['ticket_ids'] ?? []) as $source_id => $id) {
            $persisted->addTicket((string) $source_id, (int) $id);
        }
        return $persisted;
    }


    /**
     * @return array<string, float> source ticket id => migrated ticket price
     */
    private static function ticketPrices(MappedEventGraph $graph): array
    {
        $prices = [];
        foreach ($graph->tickets() as $ticket) {
            $prices[ $ticket->sourceId() ] = $ticket->price();
        }
        return $prices;
    }


    /**
     * The ids of an event an earlier run migrated. The event is mapped against a scratch context, so its
     * warnings and reuse notes are not reported a second time.
     */
    private function existing(EventGroup $group, int $event_id, SourceAdapter $adapter, MigrationContext $context): ?array
    {
        $scratch = new MigrationContext(
            $context->runId(),
            $context->source(),
            $context->options(),
            $context->siteTimezone(),
            $context->userId(),
            $context->isDryRun(),
            $context->siteCurrency()
        );
        $graph   = $this->mapper->map($group, $adapter->statusTable(), $scratch);
        if ($graph === null) {
            return null;
        }
        $persisted = new PersistedEvent($graph->sourceId());
        $persisted->setEventId($event_id);
        $this->addExisting($graph, $persisted, $context);
        return self::currentFrom($persisted, self::ticketPrices($graph));
    }


    private function addExisting(MappedEventGraph $graph, PersistedEvent $persisted, MigrationContext $context): void
    {
        $source       = $context->source();
        $datetime_ids = array_map(static fn($datetime): string => $datetime->sourceId(), $graph->datetimes());
        $ticket_ids   = array_map(static fn($ticket): string => $ticket->sourceId(), $graph->tickets());
        $this->crosswalk->prefetch($source, CrosswalkKeys::TYPE_DATETIME, $datetime_ids, CrosswalkKeys::MODEL_DATETIME, $context);
        $this->crosswalk->prefetch($source, CrosswalkKeys::TYPE_TICKET, $ticket_ids, CrosswalkKeys::MODEL_TICKET, $context);
        foreach ($datetime_ids as $source_id) {
            $id = $this->crosswalk->targetId($source, CrosswalkKeys::TYPE_DATETIME, $source_id, CrosswalkKeys::MODEL_DATETIME, $context);
            if ($id !== null) {
                $persisted->addDatetime($source_id, $id);
            }
        }
        foreach ($ticket_ids as $source_id) {
            $id = $this->crosswalk->targetId($source, CrosswalkKeys::TYPE_TICKET, $source_id, CrosswalkKeys::MODEL_TICKET, $context);
            if ($id !== null) {
                $persisted->addTicket($source_id, $id);
            }
        }
    }
}
// End of file EventStep.php
// Location: EventEspresso\EventMigrator\domain\services\migration\run/EventStep.php
