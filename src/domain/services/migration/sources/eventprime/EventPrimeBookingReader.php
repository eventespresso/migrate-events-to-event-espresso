<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventprime;

use EventEspresso\EventMigrator\application\services\migration\records\SourceAnswerRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceAttendeeRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceBookingRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\records\SourcePaymentRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceSeatRecord;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SeatPricing;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventPrimeBookingReader
 *
 * Reads a page of an event's `em_booking` posts (research profile §3, §8). A
 * booking is one order: its ticket lines (`em_order_info['tickets']`, a
 * quantity each) are the seats, named from `em_attendee_names` when the
 * checkout asked for names; the buyer is the WordPress user on the booking;
 * the payment, if any, is described by the gateway log (`em_payment_log`) and
 * `em_payment_method`; answers are the booking-level checkout fields. The
 * order total includes fees, offers and the event's flat fee, so the seats
 * are scaled to it.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventPrimeBookingReader
{
    private const BOOKING_META = [
        'em_event',
        'em_user',
        'em_date',
        'em_status',
        'em_payment_method',
        'em_order_info',
        'em_payment_log',
        'em_attendee_names',
        'em_booking_fields_data',
    ];

    private SourceDatabase $db;

    private EventPrimeScope $scope;


    public function __construct(SourceDatabase $db, EventPrimeScope $scope)
    {
        $this->db    = $db;
        $this->scope = $scope;
    }


    /**
     * Whether a booking with this meta has a payment record: it costs something, went through a gateway, and either
     * logged a payment status or completed.
     *
     * @param array $meta The booking's post meta.
     */
    public static function hasPayment(array $meta): bool
    {
        $order = self::data((array) ($meta['em_order_info'] ?? []));
        $log   = self::data((array) ($meta['em_payment_log'] ?? []));
        $total = (float) ($order['booking_total'] ?? 0);
        $paid  = ($log['payment_status'] ?? '') !== '' || ($log['offline_status'] ?? '') !== '' || ($meta['em_status'] ?? '') === 'completed';
        return $total > 0.0 && ($meta['em_payment_method'] ?? 'none') !== 'none' && $paid;
    }


    /**
     * Post meta that held a serialized object (EventPrime stores some as stdClass) as a plain array.
     *
     * @param array $value A value read from post meta, cast to an array by the caller.
     */
    public static function data(array $value): array
    {
        $decoded = json_decode((string) wp_json_encode($value), true);
        return is_array($decoded) ? $decoded : [];
    }


    /**
     * @return SourceBookingRecord[]
     * @throws MigrationRecordException When a read fails.
     */
    public function read(string $source_event_id, int $offset, int $limit): array
    {
        $event_ids = $this->scope->occurrenceIdsOf($source_event_id);
        $excluded  = EventPrimeSchema::EXCLUDED_POST_STATUSES;
        $bookings  = $this->db->rows(
            'SELECT p.ID, p.post_status FROM ' . $this->db->table('posts') . ' p JOIN ' . $this->db->table('postmeta')
            . ' me ON me.post_id = p.ID AND me.meta_key = %s WHERE p.post_type = %s AND p.post_status NOT IN ('
            . SourceDatabase::placeholders($excluded, '%s') . ') AND me.meta_value IN ('
            . SourceDatabase::placeholders($event_ids, '%s') . ') ORDER BY p.ID LIMIT %d OFFSET %d',
            array_merge(['em_event', EventPrimeSchema::POST_TYPE_BOOKING], $excluded, array_map('strval', $event_ids), [max(1, $limit), max(0, $offset)])
        );
        if ($bookings === []) {
            return [];
        }
        $ids      = array_map('intval', array_column($bookings, 'ID'));
        $meta     = $this->db->postMeta($ids, self::BOOKING_META);
        $series   = EventPrimeScope::isSeries($source_event_id);
        $users    = $this->db->users(array_map(static fn(int $id): int => (int) ($meta[ $id ]['em_user'] ?? 0), $ids));
        $tickets  = $this->canonicalTickets($meta, $series);
        $currency = $this->currency();
        $records  = [];
        foreach ($bookings as $booking) {
            $id        = (int) $booking['ID'];
            $fields    = $meta[ $id ] ?? [];
            $fields['em_status'] = (string) ($fields['em_status'] ?? $booking['post_status']);
            $records[] = $this->record($id, $fields, $users, $tickets, $currency);
        }
        return $records;
    }


    /**
     * @param int               $id       The booking post id.
     * @param array             $fields   Its meta.
     * @param array<int, array> $users    WordPress users by id.
     * @param array<int, int>   $tickets  Ticket line id => source ticket id.
     * @param string            $currency The site's EventPrime currency.
     */
    private function record(int $id, array $fields, array $users, array $tickets, string $currency): SourceBookingRecord
    {
        $order  = self::data((array) ($fields['em_order_info'] ?? []));
        $total  = (float) ($order['booking_total'] ?? 0);
        $status = (string) $fields['em_status'];
        return new SourceBookingRecord(
            (string) $id,
            $status,
            $this->buyer($id, $fields, $order, $users),
            SeatPricing::scaleToTotal($this->seats($fields, $order, $tickets), $total),
            [
                'transaction_status' => $total <= 0.0 ? EventPrimeStatusTable::TXN_FREE : $status,
                'created_at'         => $this->utc((int) ($fields['em_date'] ?? 0)),
                'total'              => $total,
                'currency'           => $currency,
                'payments'           => $this->payments($id, $fields, $total, $currency),
                'answers'            => $this->answers($fields),
            ]
        );
    }


    /**
     * The buyer: the WordPress user on the booking, or a guest identified by a hash of the email.
     */
    private function buyer(int $id, array $fields, array $order, array $users): SourceAttendeeRecord
    {
        $user_id = (int) ($fields['em_user'] ?? 0);
        $user    = $users[ $user_id ] ?? [];
        $email   = strtolower(trim((string) ($order['user_email'] ?? $user['user_email'] ?? '')));
        $name    = trim((string) ($order['user_name'] ?? '')) ?: trim((string) ($user['display_name'] ?? ''));
        $source  = $user_id > 0 ? 'user:' . $user_id : ($email !== '' ? 'guest:' . md5($email) : 'guest-booking:' . $id);
        return new SourceAttendeeRecord($source, ['full_name' => $name, 'email' => $email, 'wp_user_id' => $user_id]);
    }


    /**
     * One seat per named attendee when the checkout asked for names, otherwise one line per ticket at its
     * quantity. The ticket is the line's price option, or its original for a copy in a series.
     *
     * @param array           $fields  The booking meta.
     * @param array           $order   The decoded order info.
     * @param array<int, int> $tickets Ticket line id => source ticket id.
     * @return SourceSeatRecord[]
     */
    private function seats(array $fields, array $order, array $tickets): array
    {
        $names = self::data((array) ($fields['em_attendee_names'] ?? []));
        $seats = [];
        foreach (array_filter((array) ($order['tickets'] ?? []), 'is_array') as $line) {
            $line_id  = (int) ($line['id'] ?? 0);
            $quantity = max(1, (int) ($line['qty'] ?? 1));
            $source   = (string) ($tickets[ $line_id ] ?? $line_id);
            $price    = (float) ($line['price'] ?? 0);
            $named    = array_values(array_filter(array_map([$this, 'attendeeName'], array_values((array) ($names[ $line_id ] ?? [])))));
            if (count($named) >= $quantity) {
                foreach (array_slice($named, 0, $quantity) as $name) {
                    $seats[] = new SourceSeatRecord($source, $price, 1, $name);
                }
                continue;
            }
            $seats[] = new SourceSeatRecord($source, $price, $quantity);
        }
        return $seats;
    }


    /**
     * The full name in one attendee's checkout entry; '' when it has none.
     */
    private function attendeeName($entry): string
    {
        $name = is_array($entry) ? ($entry['name'] ?? '') : '';
        if (is_array($name)) {
            return trim(implode(' ', array_filter([$name['first_name'] ?? '', $name['middle_name'] ?? '', $name['last_name'] ?? ''])));
        }
        return trim((string) $name);
    }


    /**
     * The payment described by the booking's gateway log, plus a refund of unknown amount when it was refunded.
     *
     * @return SourcePaymentRecord[]
     */
    private function payments(int $id, array $fields, float $total, string $currency): array
    {
        if (! self::hasPayment($fields)) {
            return [];
        }
        $log      = self::data((array) ($fields['em_payment_log'] ?? []));
        $status   = strtolower(trim((string) ($log['payment_status'] ?? '')));
        $offline  = strtolower(trim((string) ($log['offline_status'] ?? '')));
        $refunded = $status === EventPrimeStatusTable::PAYMENT_REFUNDED || $fields['em_status'] === 'refunded';
        $state    = $offline === 'cancelled' ? EventPrimeStatusTable::PAYMENT_VOIDED : ($offline !== '' ? $offline : ($status !== '' ? $status : 'completed'));
        $amount   = (float) ($log['total_amount'] ?? $total) ?: $total;
        $extra    = [
            'currency'       => strtoupper(trim((string) ($log['currency'] ?? ''))) ?: $currency,
            'gateway'        => (string) ($fields['em_payment_method'] ?? ''),
            'gateway_txn_id' => (string) ($log['id'] ?? ''),
            'paid_at'        => $this->utc((int) ($fields['em_date'] ?? 0)),
        ];
        if (! $refunded) {
            return [new SourcePaymentRecord((string) $id, $amount, $state, $extra)];
        }
        return [
            new SourcePaymentRecord((string) $id, $amount, EventPrimeStatusTable::PAYMENT_PAID, $extra),
            new SourcePaymentRecord($id . ':refund', null, EventPrimeStatusTable::PAYMENT_REFUND, array_merge($extra, ['refund' => true])),
        ];
    }


    /**
     * The booking-level checkout field answers: field id => `{label, value}` (or the bare value).
     *
     * @return SourceAnswerRecord[]
     */
    private function answers(array $fields): array
    {
        $answers = [];
        foreach (self::data((array) ($fields['em_booking_fields_data'] ?? [])) as $field_id => $entry) {
            $raw    = is_array($entry) && array_key_exists('value', $entry) ? $entry['value'] : (is_array($entry) ? ($entry['val'] ?? '') : $entry);
            $values = array_values(array_filter(
                array_map(static fn($item): string => is_scalar($item) ? trim((string) $item) : '', is_array($raw) ? $raw : [$raw]),
                static fn(string $item): bool => $item !== ''
            ));
            if ($values !== []) {
                $answers[] = new SourceAnswerRecord((string) $field_id, $values);
            }
        }
        return $answers;
    }


    /**
     * The source ticket id of every ticket line on the page: the line's own id, or in a series its original.
     *
     * @param array<int, array> $meta   Booking meta by booking id.
     * @param bool              $series Whether the migrated event is a series.
     * @return array<int, int>
     * @throws MigrationRecordException
     */
    private function canonicalTickets(array $meta, bool $series): array
    {
        $line_ids = [];
        foreach ($meta as $fields) {
            foreach (array_filter((array) (self::data((array) ($fields['em_order_info'] ?? []))['tickets'] ?? []), 'is_array') as $line) {
                $line_ids[ (int) ($line['id'] ?? 0) ] = (int) ($line['id'] ?? 0);
            }
        }
        $line_ids = array_values(array_filter($line_ids));
        if (! $series || $line_ids === []) {
            return array_combine($line_ids, $line_ids) ?: [];
        }
        $map  = [];
        $rows = $this->db->rows(
            'SELECT id, parent_price_option_id FROM ' . $this->db->table(EventPrimeSchema::TICKETS)
            . ' WHERE id IN (' . SourceDatabase::placeholders($line_ids) . ')',
            $line_ids
        );
        foreach ($rows as $row) {
            $map[ (int) $row['id'] ] = EventPrimeTicketReader::canonicalId($row, true);
        }
        return $map;
    }


    /**
     * The currency EventPrime charges in (its `em_global_settings` option); '' when unset.
     *
     * @throws MigrationRecordException
     */
    private function currency(): string
    {
        $settings = self::data((array) SourceDatabase::safeUnserialize((string) $this->db->option(EventPrimeSchema::SETTINGS_OPTION)));
        return strtoupper(trim((string) ($settings['currency'] ?? '')));
    }


    private function utc(int $timestamp): ?SourceDateTime
    {
        return $timestamp > 0 ? new SourceDateTime(gmdate('Y-m-d H:i:s', $timestamp), TimezoneModel::UTC) : null;
    }
}
// End of file EventPrimeBookingReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventprime/EventPrimeBookingReader.php
