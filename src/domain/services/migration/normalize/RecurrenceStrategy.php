<?php

namespace EventEspresso\EventMigrator\domain\services\migration\normalize;

use EventEspresso\core\exceptions\InvalidDataTypeException;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\records\SourceEventRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceTicketRecord;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;

/**
 * Class RecurrenceStrategy
 *
 * Turns the source occurrence records of a series into EE events the way the
 * admin chose (REQ-006): one event with a datetime per occurrence, or one
 * event per occurrence. Records without a series id are always their own
 * event. Group order follows first appearance in the input. Pure.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class RecurrenceStrategy
{
    /**
     * @param SourceEventRecord[] $event_records The source events, occurrences of a series sharing a series id.
     * @param string              $mode          A MigrationOptions::RECURRENCE_* value.
     * @return EventGroup[]
     * @throws InvalidDataTypeException When the mode is unknown.
     */
    public function group(array $event_records, string $mode, MigrationContext $context): array
    {
        if (
            ! in_array(
                $mode,
                [MigrationOptions::RECURRENCE_SINGLE_EVENT, MigrationOptions::RECURRENCE_EVENT_PER_OCCURRENCE],
                true
            )
        ) {
            throw new InvalidDataTypeException('$mode', esc_html($mode), 'a MigrationOptions recurrence mode');
        }
        $records = array_values(array_filter(
            $event_records,
            static fn($record): bool => $record instanceof SourceEventRecord
        ));
        if ($mode === MigrationOptions::RECURRENCE_EVENT_PER_OCCURRENCE) {
            return array_map(
                static fn(SourceEventRecord $record): EventGroup => new EventGroup(
                    $record,
                    [$record->sourceId()],
                    $record->seriesId()
                ),
                $records
            );
        }
        return $this->collapseSeries($records, $context);
    }


    /**
     * @param SourceEventRecord[] $records
     * @return EventGroup[]
     */
    private function collapseSeries(array $records, MigrationContext $context): array
    {
        $buckets = [];
        foreach ($records as $record) {
            $series_id = (string) $record->seriesId();
            $key       = $series_id === '' ? 'event:' . $record->sourceId() : 'series:' . $series_id;
            $buckets[ $key ][] = $record;
        }
        $groups = [];
        foreach ($buckets as $members) {
            $groups[] = $this->mergeOccurrences($members, $context);
        }
        return $groups;
    }


    /**
     * @param SourceEventRecord[] $members
     */
    private function mergeOccurrences(array $members, MigrationContext $context): EventGroup
    {
        $first     = $members[0];
        $series_id = $first->seriesId();
        if (count($members) === 1) {
            return new EventGroup($first, [$first->sourceId()], $series_id);
        }
        $datetimes = [];
        $ids       = [];
        $differs   = false;
        $signature = $this->ticketSignature($first);
        foreach ($members as $member) {
            $ids[] = $member->sourceId();
            $differs = $differs || $this->ticketSignature($member) !== $signature;
            foreach ($member->datetimes() as $datetime) {
                $datetimes[ $datetime->sourceId() ] = $datetime;
            }
        }
        if ($differs) {
            $context->addWarning(
                new MigrationWarning(
                    WarningRule::SERIES_TICKETS_DIFFER,
                    CrosswalkKeys::TYPE_EVENT,
                    $first->sourceId(),
                    [(string) $series_id]
                )
            );
        }
        return new EventGroup($first->withDatetimes(array_values($datetimes)), $ids, $series_id);
    }


    /**
     * A comparable fingerprint of what an occurrence sells.
     */
    private function ticketSignature(SourceEventRecord $record): string
    {
        $tickets = array_map(
            static fn(SourceTicketRecord $ticket): array => [
                $ticket->name(),
                $ticket->price(),
                $ticket->rawPrice(),
                $ticket->quantity(),
                $ticket->min(),
                $ticket->max(),
            ],
            $record->tickets()
        );
        return (string) wp_json_encode($tickets);
    }
}
// End of file RecurrenceStrategy.php
// Location: EventEspresso\EventMigrator\domain\services\migration\normalize/RecurrenceStrategy.php
