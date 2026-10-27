<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventprime;

use DateInterval;
use DateTimeImmutable;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\records\SourceTicketRecord;
use EventEspresso\EventMigrator\application\services\migration\records\UnmappedConcept;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventPrimeTicketReader
 *
 * Reads the tickets (`em_price_options` rows, research profile §3, §8) of an
 * event's occurrences. EventPrime copies a recurring parent's tickets into
 * every child with `parent_price_option_id` pointing at the original, so in a
 * series the copies are one ticket (the original's id) on all the dates.
 * Registration windows are JSON (a fixed date, the event's start or end, or a
 * number of days before or after it). Fees, offers, shared-capacity ticket
 * categories and inactive tickets have no counterpart and are unmapped
 * concepts.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventPrimeTicketReader
{
    private SourceDatabase $db;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * The source id a ticket row has in a run: the original's id for a copy in a series, its own id otherwise.
     *
     * @param array $row    An em_price_options row.
     * @param bool  $series Whether the migrated event is a series.
     */
    public static function canonicalId(array $row, bool $series): int
    {
        return $series && (int) $row['parent_price_option_id'] > 0 ? (int) $row['parent_price_option_id'] : (int) $row['id'];
    }


    /**
     * @param int[]             $event_ids The occurrence post ids, parent first.
     * @param bool              $series    Whether the migrated event is a series.
     * @param array<int, int[]> $times     Event id => ['start' => timestamp, 'end' => timestamp].
     * @return array{records: SourceTicketRecord[], unmapped: UnmappedConcept[]}
     * @throws MigrationRecordException When a read fails.
     */
    public function read(array $event_ids, bool $series, array $times): array
    {
        $rows       = $this->db->rows(
            'SELECT * FROM ' . $this->db->table(EventPrimeSchema::TICKETS)
            . ' WHERE event_id IN (' . SourceDatabase::placeholders($event_ids) . ') ORDER BY event_id, priority, id',
            $event_ids
        );
        $tickets    = [];
        $dates      = [];
        $disabled   = [];
        $fees       = [];
        $offers     = [];
        $categories = $this->categories(array_values(array_filter(array_map(static fn(array $row): int => (int) $row['category_id'], $rows))));
        $grouped    = [];
        foreach ($rows as $row) {
            $id = self::canonicalId($row, $series);
            $dates[ $id ][] = (string) $row['event_id'];
            if (isset($tickets[ $id ])) {
                continue;
            }
            $tickets[ $id ] = $this->record($id, $row, $times[ (int) $row['event_id'] ] ?? []);
            if ((int) $row['status'] === 0) {
                $disabled[] = (string) $row['name'];
            }
            if ($this->listed((string) $row['additional_fees'])) {
                $fees[] = (string) $row['name'];
            }
            if ($this->listed((string) $row['offers'])) {
                $offers[] = (string) $row['name'];
            }
            if ((int) $row['category_id'] > 0 && isset($categories[ (int) $row['category_id'] ])) {
                $grouped[] = $row['name'] . ': ' . $categories[ (int) $row['category_id'] ];
            }
        }
        $records = [];
        foreach ($tickets as $id => $ticket) {
            $records[] = $ticket->datetimeSourceIds() === [] ? $this->withDates($ticket, $dates[ $id ]) : $ticket;
        }
        $unmapped = [];
        foreach (
            [
                'disabled-tickets' => [__('Inactive tickets', 'migrate-events-to-event-espresso'), $disabled],
                'ticket-fees'      => [__('Tickets with additional fees', 'migrate-events-to-event-espresso'), $fees],
                'ticket-offers'    => [__('Tickets with offers', 'migrate-events-to-event-espresso'), $offers],
                'ticket-categories' => [__('Tickets in a category with a shared capacity', 'migrate-events-to-event-espresso'), $grouped],
            ] as $concept => [$label, $values]
        ) {
            if ($values !== []) {
                $unmapped[] = new UnmappedConcept($concept, $label, $values);
            }
        }
        return ['records' => $records, 'unmapped' => $unmapped];
    }


    private function record(int $id, array $row, array $times): SourceTicketRecord
    {
        $price = trim((string) $row['price']);
        $min   = (int) $row['min_ticket_no'];
        $max   = (int) $row['max_ticket_no'];
        return new SourceTicketRecord(
            (string) $id,
            (string) $row['name'],
            [
                'price'      => is_numeric($price) ? (float) $price : 0.0,
                'raw_price'  => $price,
                'quantity'   => (int) $row['capacity'] > 0 ? (int) $row['capacity'] : null,
                'description' => (string) $row['description'],
                'min'        => $min > 0 ? $min : 0,
                'max'        => $max > 0 ? $max : null,
                'sale_start' => $this->window((string) $row['booking_starts'], 'start', $times),
                'sale_end'   => $this->window((string) $row['booking_ends'], 'end', $times),
            ]
        );
    }


    /**
     * A copy of the record that is on the given events' dates.
     *
     * @param SourceTicketRecord $ticket    The record.
     * @param string[]           $event_ids The events whose dates it is on.
     */
    private function withDates(SourceTicketRecord $ticket, array $event_ids): SourceTicketRecord
    {
        return new SourceTicketRecord(
            $ticket->sourceId(),
            $ticket->name(),
            [
                'price'               => $ticket->price(),
                'raw_price'           => $ticket->rawPrice(),
                'quantity'            => $ticket->quantity(),
                'description'         => $ticket->description(),
                'min'                 => $ticket->min(),
                'max'                 => $ticket->max(),
                'sale_start'          => $ticket->saleStart(),
                'sale_end'            => $ticket->saleEnd(),
                'datetime_source_ids' => array_values(array_unique($event_ids)),
            ]
        );
    }


    /**
     * When sales open or close: a fixed date and time, the event's start or end, or that moment shifted by a
     * number of days. Null when the JSON is blank, of an unknown type, or the event's dates are unknown.
     *
     * @param string $json  The booking_starts or booking_ends JSON.
     * @param string $which Either "start" or "end".
     * @param int[]  $times The event's ['start' => timestamp, 'end' => timestamp].
     */
    private function window(string $json, string $which, array $times): ?SourceDateTime
    {
        $config = json_decode($json, true);
        $type   = is_array($config) ? (string) ($config['booking_type'] ?? '') : '';
        if ($type === 'custom_date') {
            $date = trim((string) ($config[ $which . '_date' ] ?? ''));
            $time = trim((string) ($config[ $which . '_time' ] ?? ''));
            return $date !== '' && strtotime($date . ' ' . $time) !== false
                ? new SourceDateTime(gmdate('Y-m-d H:i:s', (int) strtotime($date . ' ' . $time)), TimezoneModel::SITE_LOCAL)
                : null;
        }
        $base = (int) ($times[ ($config['event_option'] ?? 'start') === 'end' ? 'end' : 'start' ] ?? 0);
        if (! in_array($type, ['event_date', 'relative_date'], true) || $base <= 0) {
            return null;
        }
        $moment = (new DateTimeImmutable('@' . $base));
        if ($type === 'relative_date' && (int) ($config['days'] ?? 0) > 0) {
            $interval = new DateInterval('P' . (int) $config['days'] . 'D');
            $moment   = ($config['days_option'] ?? 'before') === 'after' ? $moment->add($interval) : $moment->sub($interval);
        }
        return new SourceDateTime($moment->format('Y-m-d H:i:s'), TimezoneModel::UTC);
    }


    /**
     * Whether a JSON or serialized list value has any entry.
     */
    private function listed(string $stored): bool
    {
        $value = trim($stored);
        if ($value === '') {
            return false;
        }
        $decoded = is_serialized($value) ? SourceDatabase::safeUnserialize($value) : json_decode($value, true);
        return is_array($decoded) && $decoded !== [];
    }


    /**
     * @param int[] $category_ids
     * @return array<int, string> category id => "<name> (<capacity> shared)"
     * @throws MigrationRecordException
     */
    private function categories(array $category_ids): array
    {
        if ($category_ids === []) {
            return [];
        }
        $found = [];
        $rows  = $this->db->rows(
            'SELECT id, name, capacity FROM ' . $this->db->table(EventPrimeSchema::TICKET_CATEGORIES)
            . ' WHERE id IN (' . SourceDatabase::placeholders($category_ids) . ')',
            $category_ids
        );
        foreach ($rows as $row) {
            $found[ (int) $row['id'] ] = $row['name'] . ' (' . (int) $row['capacity'] . ' shared)';
        }
        return $found;
    }
}
// End of file EventPrimeTicketReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventprime/EventPrimeTicketReader.php
