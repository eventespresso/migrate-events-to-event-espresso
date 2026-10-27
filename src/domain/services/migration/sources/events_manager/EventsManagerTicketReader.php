<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_manager;

use EventEspresso\EventMigrator\application\services\migration\records\SourceTicketRecord;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventsManagerTicketReader
 *
 * Reads an event's em_tickets rows (research profile §8). Each date gets its
 * own tickets: a child of a series has copies of the parent's tickets
 * (ticket_parent), so a booking keeps the date it was for. A date without
 * tickets of its own falls back to the series parent's tickets. An event
 * that takes bookings but has no ticket at all gets one implicit ticket with
 * the event's space limit on its datetime (Phase 2 implicit-ticket contract);
 * one that takes no bookings gets none and the engine adds a free ticket.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventsManagerTicketReader
{
    private SourceDatabase $db;

    private ?string $currency = null;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * @param array[] $occurrences The event's date rows (em_events).
     * @param array   $owner       The row owning the event (a series parent, or the event itself).
     * @return array{records: SourceTicketRecord[], members_only: string[]}
     * @throws MigrationRecordException When a read fails.
     */
    public function read(array $occurrences, array $owner): array
    {
        $own_ids   = array_map('intval', array_column($occurrences, 'event_id'));
        $parent_id = (int) $owner['event_id'];
        $by_event  = $this->ticketRows(array_values(array_unique(array_merge($own_ids, [$parent_id]))));
        $records   = [];
        $members   = [];
        $orphans   = [];
        foreach ($occurrences as $occurrence) {
            $event_id = (int) $occurrence['event_id'];
            if (! isset($by_event[ $event_id ]) || ($event_id === $parent_id && count($occurrences) > 1)) {
                $orphans[] = (string) $event_id;
                continue;
            }
            foreach ($by_event[ $event_id ] as $ticket) {
                $records[] = $this->record($ticket, [(string) $event_id], (string) ($occurrence['event_timezone'] ?? ''));
                $members   = $this->membersOnly($ticket, $members);
            }
        }
        if ($orphans !== [] && isset($by_event[ $parent_id ]) && ! in_array((string) $parent_id, $orphans, true)) {
            foreach ($by_event[ $parent_id ] as $ticket) {
                $records[] = $this->record($ticket, $orphans, (string) ($owner['event_timezone'] ?? ''));
                $members   = $this->membersOnly($ticket, $members);
            }
        } elseif ($orphans !== [] && (int) $owner['event_rsvp'] === 1) {
            $records[] = new SourceTicketRecord(
                (string) $owner['event_id'],
                '',
                ['implicit' => true, 'raw_price' => '', 'raw_quantity' => '', 'datetime_source_ids' => $orphans]
            );
        }
        return ['records' => $records, 'members_only' => $members];
    }


    /**
     * The site's Events Manager currency, read once.
     *
     * @throws MigrationRecordException
     */
    public function currency(): string
    {
        if ($this->currency === null) {
            $this->currency = strtoupper(trim((string) $this->db->option(EventsManagerSchema::CURRENCY_OPTION)));
        }
        return $this->currency;
    }


    /**
     * @param int[] $event_ids
     * @return array<int, array[]> event id => ticket rows in display order
     * @throws MigrationRecordException
     */
    private function ticketRows(array $event_ids): array
    {
        $rows     = $this->db->rows(
            'SELECT * FROM ' . $this->db->table(EventsManagerSchema::TICKETS)
            . ' WHERE event_id IN (' . SourceDatabase::placeholders($event_ids) . ')'
            . ' ORDER BY event_id, ticket_order, ticket_id',
            $event_ids
        );
        $by_event = [];
        foreach ($rows as $row) {
            $by_event[ (int) $row['event_id'] ][] = $row;
        }
        return $by_event;
    }


    /**
     * @param array    $ticket       An em_tickets row.
     * @param string[] $datetime_ids The dates the ticket is for.
     * @param string   $timezone     The event's stored timezone, for the sale window.
     * @throws MigrationRecordException
     */
    private function record(array $ticket, array $datetime_ids, string $timezone): SourceTicketRecord
    {
        $zone = EventsManagerDates::zone($timezone);
        return new SourceTicketRecord(
            (string) $ticket['ticket_id'],
            (string) $ticket['ticket_name'],
            [
                'price'               => $ticket['ticket_price'] !== null ? (float) $ticket['ticket_price'] : 0.0,
                'raw_price'           => (string) $ticket['ticket_price'],
                'currency'            => $this->currency(),
                'quantity'            => $ticket['ticket_spaces'] !== null && (int) $ticket['ticket_spaces'] > 0
                    ? (int) $ticket['ticket_spaces']
                    : null,
                'min'                 => (int) $ticket['ticket_min'],
                'max'                 => $ticket['ticket_max'] !== null && (int) $ticket['ticket_max'] > 0
                    ? (int) $ticket['ticket_max']
                    : null,
                'description'         => (string) $ticket['ticket_description'],
                'sale_start'          => EventsManagerDates::local($ticket['ticket_start'], $zone),
                'sale_end'            => EventsManagerDates::local($ticket['ticket_end'], $zone),
                'datetime_source_ids' => $datetime_ids,
                'required'            => (int) $ticket['ticket_required'] === 1,
            ]
        );
    }


    /**
     * @param array    $ticket An em_tickets row.
     * @param string[] $names  The members-only ticket names so far.
     * @return string[]
     */
    private function membersOnly(array $ticket, array $names): array
    {
        if ((int) $ticket['ticket_members'] === 1 && ! in_array((string) $ticket['ticket_name'], $names, true)) {
            $names[] = (string) $ticket['ticket_name'];
        }
        return $names;
    }
}
// End of file EventsManagerTicketReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_manager/EventsManagerTicketReader.php
