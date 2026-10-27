<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\event_genius;

use EventEspresso\EventMigrator\application\services\migration\records\SourceAnswerRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceAttendeeRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceBookingRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\records\SourcePaymentRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceSeatRecord;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventGeniusBookingReader
 *
 * Reads a page of an event's registrations (research profile §3, §8). A main
 * registration (`parent` 0) is the booking; its `quantity` is the number of
 * guests including the booker, and the registrations whose parent it is are
 * those guests, each with answers of its own. Every seat is the event's cost.
 * The booker's details and form answers are the registration's meta
 * (`first`, `last`, `email`, any other key a form field slug); the payment, if
 * any, is a row of evge_payments (the free build records an `offline` one when
 * the event has a cost). All dates are UTC.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventGeniusBookingReader
{
    private SourceDatabase $db;

    private EventGeniusScope $scope;


    public function __construct(SourceDatabase $db, EventGeniusScope $scope)
    {
        $this->db    = $db;
        $this->scope = $scope;
    }


    /**
     * @return SourceBookingRecord[]
     * @throws MigrationRecordException When a read fails.
     */
    public function read(string $source_event_id, int $offset, int $limit): array
    {
        $event_ids = $this->scope->occurrenceIdsOf($source_event_id);
        $mains     = $this->db->rows(
            'SELECT * FROM ' . $this->db->table(EventGeniusSchema::REGISTRATIONS)
            . ' WHERE parent = 0 AND event_id IN (' . SourceDatabase::placeholders($event_ids) . ') ORDER BY id LIMIT %d OFFSET %d',
            array_merge($event_ids, [max(1, $limit), max(0, $offset)])
        );
        if ($mains === []) {
            return [];
        }
        $main_ids = array_map('intval', array_column($mains, 'id'));
        $guests   = $this->grouped(
            'SELECT * FROM ' . $this->db->table(EventGeniusSchema::REGISTRATIONS) . ' WHERE parent IN ('
            . SourceDatabase::placeholders($main_ids) . ') ORDER BY id',
            $main_ids,
            'parent'
        );
        $payments = $this->grouped(
            'SELECT * FROM ' . $this->db->table(EventGeniusSchema::PAYMENTS) . ' WHERE registration_id IN ('
            . SourceDatabase::placeholders($main_ids) . ') ORDER BY transaction_id',
            $main_ids,
            'registration_id'
        );
        $every_id = array_merge($main_ids, array_map('intval', array_column(array_merge(...array_values($guests) ?: [[]]), 'id')));
        $meta     = $this->registrationMeta($every_id);
        $costs    = $this->costs($event_ids);
        $records  = [];
        foreach ($mains as $main) {
            $id        = (int) $main['id'];
            $records[] = $this->record($main, $guests[ $id ] ?? [], $payments[ $id ] ?? [], $meta, (float) ($costs[ (int) $main['event_id'] ] ?? 0), $source_event_id);
        }
        return $records;
    }


    /**
     * @param array   $main     The booking's main evge_registrations row.
     * @param array[] $guests   Its guest rows.
     * @param array[] $payments Its evge_payments rows.
     * @param array   $meta     Registration meta for the page: registration id => key => value.
     * @param float   $cost     The event's cost per seat.
     * @param string  $migrated The migrated event id that prefixes the ticket id.
     */
    private function record(array $main, array $guests, array $payments, array $meta, float $cost, string $migrated): SourceBookingRecord
    {
        $id       = (int) $main['id'];
        $fields   = $meta[ $id ] ?? [];
        $records  = [];
        foreach ($payments as $payment) {
            $records = array_merge($records, $this->paymentRecords($payment));
        }
        $quantity = max(1, (int) $main['quantity']);
        $total    = $payments !== [] ? (float) $payments[0]['payment_gross'] : $cost * $quantity;
        // A refunded payment is not money kept.
        $paid     = array_sum(array_map(
            static fn(array $payment): float => in_array(strtolower((string) $payment['payment_status']), ['completed', 'paid'], true) ? (float) $payment['payment_gross'] : 0.0,
            $payments
        ));
        $status   = (string) $main['status'];
        return new SourceBookingRecord(
            (string) $id,
            $status,
            $this->attendee($main, $fields),
            $this->seats($migrated, $quantity, $cost, $guests, $meta, $fields),
            [
                'reference'          => (string) $main['confirmation_code'],
                'transaction_status' => $total <= 0.0
                    ? EventGeniusStatusTable::TXN_FREE
                    : ($paid + 0.005 >= $total ? EventGeniusStatusTable::TXN_PAID : $status),
                'created_at'         => $this->utc((string) $main['registration_date']),
                'total'              => $total,
                'currency'           => strtoupper(trim((string) ($payments[0]['currency_code'] ?? ''))),
                'payments'           => $records,
                'answers'            => $this->answers($fields),
            ]
        );
    }


    /**
     * The booker: the WordPress user on the registration, or a guest identified by a hash of the email.
     */
    private function attendee(array $main, array $fields): SourceAttendeeRecord
    {
        $user_id = (int) $main['user_id'];
        $email   = strtolower(trim((string) ($fields['email'] ?? '')));
        $first   = trim((string) ($fields['first'] ?? ''));
        $last    = trim((string) ($fields['last'] ?? ''));
        $source  = $user_id > 0 ? 'user:' . $user_id : ($email !== '' ? 'guest:' . md5($email) : 'guest-registration:' . $main['id']);
        return new SourceAttendeeRecord(
            $source,
            ['first_name' => $first, 'last_name' => $last, 'full_name' => trim($first . ' ' . $last), 'email' => $email, 'wp_user_id' => $user_id]
        );
    }


    /**
     * One seat per guest including the booker, all on the event's implicit ticket; the guest rows give names.
     *
     * @param string  $migrated The migrated event id that prefixes the ticket id.
     * @param int     $quantity The number of guests including the booker.
     * @param float   $cost     The event's cost per seat.
     * @param array[] $guests   The guest rows.
     * @param array   $meta     Registration meta for the page.
     * @param array   $booker   The booker's meta.
     * @return SourceSeatRecord[]
     */
    private function seats(string $migrated, int $quantity, float $cost, array $guests, array $meta, array $booker): array
    {
        $names = [trim(($booker['first'] ?? '') . ' ' . ($booker['last'] ?? ''))];
        foreach ($guests as $guest) {
            $fields  = $meta[ (int) $guest['id'] ] ?? [];
            $names[] = trim(($fields['first'] ?? '') . ' ' . ($fields['last'] ?? ''));
        }
        $seats = [];
        for ($index = 0; $index < $quantity; $index++) {
            $name    = trim((string) ($names[ $index ] ?? ''));
            $seats[] = new SourceSeatRecord(EventGeniusSchema::TICKET_PREFIX . $migrated, $cost, 1, $name !== '' ? $name : null);
        }
        return $seats;
    }


    /**
     * A payment row as its record; a refunded one as the payment and a refund of unknown amount.
     *
     * @return SourcePaymentRecord[]
     */
    private function paymentRecords(array $payment): array
    {
        $status = strtolower(trim((string) $payment['payment_status']));
        $extra  = [
            'currency'       => strtoupper(trim((string) $payment['currency_code'])),
            'gateway'        => (string) $payment['gateway'],
            'gateway_txn_id' => (string) $payment['payment_id'],
            'paid_at'        => $this->utc((string) $payment['payment_date']),
        ];
        $id     = (string) $payment['transaction_id'];
        $amount = (float) $payment['payment_gross'];
        if ($status !== EventGeniusStatusTable::PAYMENT_REFUNDED) {
            return [new SourcePaymentRecord($id, $amount, $status, $extra)];
        }
        return [
            new SourcePaymentRecord($id, $amount, EventGeniusStatusTable::PAYMENT_PAID, $extra),
            new SourcePaymentRecord($id . ':refund', null, EventGeniusStatusTable::PAYMENT_REFUND, array_merge($extra, ['refund' => true])),
        ];
    }


    /**
     * The booker's answers: every meta key that is not one of the booker's details.
     *
     * @return SourceAnswerRecord[]
     */
    private function answers(array $fields): array
    {
        $answers = [];
        foreach ($fields as $key => $value) {
            if (in_array($key, EventGeniusSchema::SYSTEM_META, true)) {
                continue;
            }
            $values = array_values(array_filter(
                array_map(static fn($item): string => is_scalar($item) ? trim((string) $item) : '', is_array($value) ? $value : [$value]),
                static fn(string $item): bool => $item !== ''
            ));
            if ($values !== []) {
                $answers[] = new SourceAnswerRecord((string) $key, $values);
            }
        }
        return $answers;
    }


    /**
     * Registration meta, registration id => key => value (the first row of a key, unserialised); a registration
     * with no meta rows falls back to its `meta_cache` JSON.
     *
     * @param int[] $registration_ids
     * @return array<int, array<string, mixed>>
     * @throws MigrationRecordException
     */
    private function registrationMeta(array $registration_ids): array
    {
        $meta = [];
        $rows = $this->db->rows(
            'SELECT registration_id, meta_key, meta_value FROM ' . $this->db->table(EventGeniusSchema::REGISTRATIONS_META)
            . ' WHERE registration_id IN (' . SourceDatabase::placeholders($registration_ids) . ') ORDER BY id',
            $registration_ids
        );
        foreach ($rows as $row) {
            $meta[ (int) $row['registration_id'] ][ (string) $row['meta_key'] ] ??= SourceDatabase::safeUnserialize($row['meta_value']);
        }
        $caches = $this->db->rows(
            'SELECT id, meta_cache FROM ' . $this->db->table(EventGeniusSchema::REGISTRATIONS)
            . ' WHERE id IN (' . SourceDatabase::placeholders($registration_ids) . ')',
            $registration_ids
        );
        foreach ($caches as $row) {
            $cache = json_decode((string) $row['meta_cache'], true);
            if (! isset($meta[ (int) $row['id'] ]) && is_array($cache)) {
                $meta[ (int) $row['id'] ] = $cache;
            }
        }
        return $meta;
    }


    /**
     * The cost per seat of each event.
     *
     * @param int[] $event_ids
     * @return array<int, string> event post id => cost amount
     * @throws MigrationRecordException
     */
    private function costs(array $event_ids): array
    {
        $costs = [];
        foreach ($this->db->postMeta($event_ids, ['evge_cost_amount']) as $id => $fields) {
            $costs[ $id ] = (string) ($fields['evge_cost_amount'] ?? '');
        }
        return $costs;
    }


    /**
     * Rows grouped by one of their columns.
     *
     * @param string $sql    A SELECT with a single IN (...) of the ids.
     * @param int[]  $ids    The ids.
     * @param string $column The column to group by.
     * @return array<int, array[]>
     * @throws MigrationRecordException
     */
    private function grouped(string $sql, array $ids, string $column): array
    {
        $grouped = [];
        foreach ($this->db->rows($sql, $ids) as $row) {
            $grouped[ (int) $row[ $column ] ][] = $row;
        }
        return $grouped;
    }


    private function utc(string $value): ?SourceDateTime
    {
        $value = trim($value);
        return $value !== '' && strpos($value, '0000-00-00') !== 0 ? new SourceDateTime($value, TimezoneModel::UTC) : null;
    }
}
// End of file EventGeniusBookingReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\event_genius/EventGeniusBookingReader.php
