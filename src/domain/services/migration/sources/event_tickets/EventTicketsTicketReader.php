<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\event_tickets;

use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\records\SourceTicketRecord;
use EventEspresso\EventMigrator\application\services\migration\records\UnmappedConcept;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventTicketsTicketReader
 *
 * Reads an event's Tickets Commerce tickets (research profile §2.2, §8):
 * name, description (the post excerpt), regular price, capacity, sale window
 * and the shared capacity of the event. A ticket on shared ("global") stock is
 * unlimited and the pool becomes the event date's capacity; a "capped" ticket
 * takes its cap. A sale price has no counterpart and is kept as text.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventTicketsTicketReader
{
    private SourceDatabase $db;

    private EventTicketsScope $scope;


    public function __construct(SourceDatabase $db, EventTicketsScope $scope)
    {
        $this->db    = $db;
        $this->scope = $scope;
    }


    /**
     * @return array{records: SourceTicketRecord[], capacity: int|null, unmapped: UnmappedConcept[]}
     * @throws MigrationRecordException When a read fails.
     */
    public function read(int $event_id): array
    {
        $tickets = $this->scope->tickets($event_id);
        $meta    = $this->db->postMeta(array_keys($tickets), EventTicketsSchema::ticketMetaKeys());
        $records = [];
        $sales   = [];
        foreach ($tickets as $id => $ticket) {
            $fields    = $meta[ $id ] ?? [];
            $records[] = $this->record($id, $ticket, $fields, $event_id);
            $sale      = trim((string) ($fields[ EventTicketsSchema::TICKET_SALE_PRICE ] ?? ''));
            if ($sale !== '' && ($fields[ EventTicketsSchema::TICKET_SALE_CHECKED ] ?? '') === 'yes') {
                $sales[] = $ticket['post_title'] . ': ' . $sale;
            }
        }
        $unmapped = $sales !== [] ? [new UnmappedConcept('sale-price', __('Sale prices', 'migrate-events-to-event-espresso'), $sales)] : [];
        return ['records' => $records, 'capacity' => $this->sharedCapacity($event_id), 'unmapped' => $unmapped];
    }


    /**
     * @param int   $id       The ticket post id.
     * @param array $ticket   The ticket post row.
     * @param array $fields   Its meta.
     * @param int   $event_id The event it is for.
     */
    private function record(int $id, array $ticket, array $fields, int $event_id): SourceTicketRecord
    {
        $mode     = (string) ($fields[ EventTicketsSchema::TICKET_STOCK_MODE ] ?? 'own');
        $capacity = (int) ($fields[ EventTicketsSchema::TICKET_CAPACITY ] ?? -1);
        if ($mode === 'global') {
            $capacity = -1;
        } elseif ($mode === 'capped') {
            $capacity = (int) ($fields[ EventTicketsSchema::TICKET_STOCK_CAP ] ?? -1);
        }
        $start = trim((string) ($fields[ EventTicketsSchema::TICKET_START ] ?? ''));
        $end   = trim((string) ($fields[ EventTicketsSchema::TICKET_END ] ?? ''));
        return new SourceTicketRecord(
            (string) $id,
            $ticket['post_title'],
            [
                'price'               => (float) ($fields[ EventTicketsSchema::TICKET_PRICE ] ?? 0),
                'raw_price'           => (string) ($fields[ EventTicketsSchema::TICKET_PRICE ] ?? ''),
                'quantity'            => $capacity > 0 ? $capacity : null,
                'description'         => $ticket['post_excerpt'],
                'sale_start'          => $start !== '' ? new SourceDateTime($start, TimezoneModel::SITE_LOCAL) : null,
                'sale_end'            => $end !== '' ? new SourceDateTime($end, TimezoneModel::SITE_LOCAL) : null,
                'datetime_source_ids' => [(string) $event_id],
            ]
        );
    }


    /**
     * The event's shared capacity, when its tickets draw on one pool.
     *
     * @throws MigrationRecordException
     */
    private function sharedCapacity(int $event_id): ?int
    {
        $meta = $this->db->postMeta(
            [$event_id],
            [EventTicketsSchema::EVENT_USE_GLOBAL_STOCK, EventTicketsSchema::EVENT_GLOBAL_STOCK_LEVEL]
        )[ $event_id ] ?? [];
        $level = (int) ($meta[ EventTicketsSchema::EVENT_GLOBAL_STOCK_LEVEL ] ?? 0);
        return (string) ($meta[ EventTicketsSchema::EVENT_USE_GLOBAL_STOCK ] ?? '') === '1' && $level > 0 ? $level : null;
    }
}
// End of file EventTicketsTicketReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\event_tickets/EventTicketsTicketReader.php
