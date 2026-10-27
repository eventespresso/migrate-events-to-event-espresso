<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\amelia;

use EventEspresso\EventMigrator\application\services\migration\records\SourceTicketRecord;
use EventEspresso\EventMigrator\application\services\migration\records\UnmappedConcept;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class AmeliaTicketReader
 *
 * Reads the tickets of Amelia events (research profile §3, §8). An event
 * with custom tickets (amelia_events_to_tickets) has those; every other event
 * sells at its own price, so the events of a group without tickets share one
 * implicit ticket whose price and sale window come from the first of them
 * (Phase 2 implicit-ticket contract); the capacity stays on the dates.
 * Early-bird price ranges and disabled tickets have no Event Espresso
 * counterpart and are reported as unmapped concepts.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class AmeliaTicketReader
{
    private SourceDatabase $db;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * @param array[] $events  The amelia_events rows of the migrated event.
     * @param array[] $periods Their amelia_events_periods rows.
     * @return array{records: SourceTicketRecord[], unmapped: UnmappedConcept[]}
     * @throws MigrationRecordException When a read fails.
     */
    public function read(array $events, array $periods): array
    {
        $period_ids = [];
        foreach ($periods as $period) {
            $period_ids[ (int) $period['eventId'] ][] = (string) $period['id'];
        }
        $by_event = $this->ticketRows(array_map('intval', array_column($events, 'id')));
        $records  = [];
        $implicit = [];
        $ranges   = [];
        $disabled = [];
        foreach ($events as $event) {
            $id = (int) $event['id'];
            if (! isset($by_event[ $id ])) {
                $implicit[] = $event;
                continue;
            }
            foreach ($by_event[ $id ] as $ticket) {
                $records[] = $this->record($ticket, $event, $period_ids[ $id ] ?? []);
                $ranges    = array_merge($ranges, $this->priceRanges($ticket));
                if ((int) $ticket['enabled'] === 0) {
                    $disabled[] = (string) $ticket['name'];
                }
            }
        }
        if ($implicit !== []) {
            $records[] = $this->implicitRecord($implicit, $period_ids);
        }
        $unmapped = [];
        if ($ranges !== []) {
            $unmapped[] = new UnmappedConcept('ticket-price-ranges', __('Ticket price date ranges', 'migrate-events-to-event-espresso'), $ranges);
        }
        if ($disabled !== []) {
            $unmapped[] = new UnmappedConcept('disabled-tickets', __('Disabled tickets', 'migrate-events-to-event-espresso'), $disabled);
        }
        return ['records' => $records, 'unmapped' => $unmapped];
    }


    /**
     * The source id of the implicit ticket of a group of events: the lowest event id among those without
     * tickets of their own; null when every event has tickets.
     *
     * @param int[] $event_ids The amelia_events ids of the migrated event.
     * @throws MigrationRecordException When a read fails.
     */
    public function implicitTicketId(array $event_ids): ?string
    {
        $with_tickets = $this->ticketRows($event_ids);
        $without      = array_values(array_filter($event_ids, static fn(int $id): bool => ! isset($with_tickets[ $id ])));
        return $without === [] ? null : AmeliaSchema::IMPLICIT_TICKET_PREFIX . min($without);
    }


    /**
     * @param int[] $event_ids
     * @return array<int, array[]> event id => ticket rows in id order
     * @throws MigrationRecordException
     */
    private function ticketRows(array $event_ids): array
    {
        $rows     = $this->db->rows(
            'SELECT * FROM ' . $this->db->table(AmeliaSchema::TICKETS)
            . ' WHERE eventId IN (' . SourceDatabase::placeholders($event_ids) . ') ORDER BY eventId, id',
            $event_ids
        );
        $by_event = [];
        foreach ($rows as $row) {
            $by_event[ (int) $row['eventId'] ][] = $row;
        }
        return $by_event;
    }


    /**
     * @param array    $ticket       An amelia_events_to_tickets row.
     * @param array    $event        Its amelia_events row.
     * @param string[] $datetime_ids The event's period ids.
     */
    private function record(array $ticket, array $event, array $datetime_ids): SourceTicketRecord
    {
        $price = (float) $ticket['price'];
        return new SourceTicketRecord(
            (string) $ticket['id'],
            (string) $ticket['name'],
            [
                'price'               => $price,
                'raw_price'           => $this->priceText($price),
                'quantity'            => (int) $ticket['spots'] > 0 ? (int) $ticket['spots'] : null,
                'max'                 => $this->maxPerBooking($event),
                'sale_start'          => AmeliaDates::utc($event['bookingOpens'] ?? null),
                'sale_end'            => AmeliaDates::utc($event['bookingCloses'] ?? null),
                'datetime_source_ids' => $datetime_ids,
            ]
        );
    }


    /**
     * @param array[] $events     The events without tickets of their own.
     * @param array[] $period_ids Period ids by event id.
     */
    private function implicitRecord(array $events, array $period_ids): SourceTicketRecord
    {
        $first = $events[0];
        $ids   = [];
        foreach ($events as $event) {
            $ids = array_merge($ids, $period_ids[ (int) $event['id'] ] ?? []);
        }
        return new SourceTicketRecord(
            AmeliaSchema::IMPLICIT_TICKET_PREFIX . min(array_map('intval', array_column($events, 'id'))),
            '',
            [
                'implicit'            => true,
                'price'               => (float) $first['price'],
                'raw_price'           => $this->priceText((float) $first['price']),
                'max'                 => $this->maxPerBooking($first),
                'sale_start'          => AmeliaDates::utc($first['bookingOpens'] ?? null),
                'sale_end'            => AmeliaDates::utc($first['bookingCloses'] ?? null),
                'datetime_source_ids' => $ids,
            ]
        );
    }


    /**
     * The most seats one booking may hold: one when the customer cannot bring anyone, the extra-people limit
     * plus the customer otherwise, unlimited (null) when the limit is not set.
     */
    private function maxPerBooking(array $event): ?int
    {
        if ((int) ($event['bringingAnyone'] ?? 1) === 0) {
            return 1;
        }
        return $event['maxExtraPeople'] !== null && (int) $event['maxExtraPeople'] > 0 ? (int) $event['maxExtraPeople'] + 1 : null;
    }


    /**
     * A price as the shortest decimal text ("15", "12.5") the ticket synthesizer reads back exactly.
     */
    private function priceText(float $price): string
    {
        return rtrim(rtrim(number_format($price, 4, '.', ''), '0'), '.');
    }


    /**
     * The ticket's early-bird windows (dateRanges JSON) as "<name>: <start> to <end> = <price>" lines.
     *
     * @return string[]
     */
    private function priceRanges(array $ticket): array
    {
        $ranges = json_decode((string) $ticket['dateRanges'], true);
        $lines  = [];
        foreach (is_array($ranges) ? $ranges : [] as $range) {
            if (is_array($range) && isset($range['startDate'], $range['endDate'], $range['price'])) {
                $lines[] = sprintf(
                    '%s: %s to %s = %s',
                    $ticket['name'],
                    $range['startDate'],
                    $range['endDate'],
                    $this->priceText((float) $range['price'])
                );
            }
        }
        return $lines;
    }
}
// End of file AmeliaTicketReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\amelia/AmeliaTicketReader.php
