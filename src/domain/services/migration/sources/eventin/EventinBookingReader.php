<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventin;

use EventEspresso\EventMigrator\application\services\migration\records\SourceAttendeeRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceBookingRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourcePaymentRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceSeatRecord;
use EventEspresso\EventMigrator\application\services\migration\records\UnmappedConcept;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SeatPricing;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;

/**
 * Class EventinBookingReader
 *
 * Reads a page of an event's orders (research profile §3.5, §3.7, §8). An
 * `etn-order` post is a booking and its transaction; its `etn-attendee`
 * posts are the seats (one per seat, with the ticket slug and the price paid).
 * The buyer is a WordPress user or a guest from the order meta. An order has
 * at most one payment (its total, in the order's status) plus a refund per
 * row of its `etn_refunds` JSON. The first attendee's extra-field answers are
 * the booking's answers; the others' are listed as an unmapped concept.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventinBookingReader
{
    private const ORDER_META = [
        'customer_fname',
        'customer_lname',
        'customer_email',
        'customer_phone',
        'customer_country',
        'customer_state',
        'customer_city',
        'customer_zip',
        'customer_id',
        'user_id',
        'tickets',
        'total_price',
        'coupon_code',
        'discount_total',
        'tax_total',
        'payment_method',
        'payment_id',
        'status',
        'currency',
        'etn_refunds',
    ];

    private const ATTENDEE_META = [
        'etn_name',
        'etn_email',
        'etn_phone',
        'ticket_slug',
        'etn_ticket_price',
        'etn_unique_ticket_id',
        'etn_attendeee_ticket_status',
    ];

    private SourceDatabase $db;

    private EventinScope $scope;

    private EventinSettings $settings;


    public function __construct(SourceDatabase $db, EventinScope $scope, EventinSettings $settings)
    {
        $this->db       = $db;
        $this->scope    = $scope;
        $this->settings = $settings;
    }


    /**
     * @return SourceBookingRecord[]
     * @throws MigrationRecordException When a read fails.
     */
    public function read(string $source_event_id, int $offset, int $limit): array
    {
        $event_ids = $this->scope->occurrenceIdsOf($source_event_id);
        $orders    = $this->db->rows(
            'SELECT p.ID, p.post_date FROM ' . $this->db->table('posts') . ' p JOIN ' . $this->db->table('postmeta') . ' me'
            . ' ON me.post_id = p.ID AND me.meta_key = %s WHERE p.post_type = %s'
            . ' AND p.post_status NOT IN (' . SourceDatabase::placeholders(EventinSchema::EXCLUDED_POST_STATUSES, '%s') . ')'
            . ' AND me.meta_value IN (' . SourceDatabase::placeholders($event_ids, '%s') . ') ORDER BY p.ID LIMIT %d OFFSET %d',
            array_merge(
                ['event_id', EventinSchema::POST_TYPE_ORDER],
                EventinSchema::EXCLUDED_POST_STATUSES,
                array_map('strval', $event_ids),
                [max(1, $limit), max(0, $offset)]
            )
        );
        if ($orders === []) {
            return [];
        }
        $ids       = array_map('intval', array_column($orders, 'ID'));
        $meta      = $this->db->postMeta($ids, self::ORDER_META);
        $attendees = $this->attendees($ids);
        $prefix    = EventinScope::parentId($source_event_id);
        $skip      = $this->fileKeys($event_ids);
        $records   = [];
        foreach ($orders as $order) {
            $id        = (int) $order['ID'];
            $records[] = $this->record($order, $meta[ $id ] ?? [], $attendees[ $id ] ?? [], $prefix, $skip);
        }
        return $records;
    }


    /**
     * @param array    $order     The order post row (ID, post_date).
     * @param array    $fields    The order meta.
     * @param array[]  $attendees The order's attendees: ['id', 'meta', 'answers'] in id order.
     * @param int      $prefix    The post id that prefixes ticket ids.
     * @param string[] $skip      Field keys whose answers are not carried over (file fields).
     * @throws MigrationRecordException
     */
    private function record(array $order, array $fields, array $attendees, int $prefix, array $skip): SourceBookingRecord
    {
        $status   = (string) ($fields['status'] ?? '');
        $total    = (float) ($fields['total_price'] ?? 0);
        $currency = strtoupper(trim((string) ($fields['currency'] ?? ''))) ?: $this->settings->currency();
        $answers  = $attendees !== [] ? EventinCustomFields::answers($attendees[0]['answers'], $skip) : [];
        return new SourceBookingRecord(
            (string) $order['ID'],
            $status,
            $this->buyer($fields, (string) $order['ID']),
            $this->discounted($this->seats($fields, $attendees, $prefix), $total, (float) ($fields['discount_total'] ?? 0)),
            [
                'reference'          => (string) ($attendees[0]['meta']['etn_unique_ticket_id'] ?? ''),
                'transaction_status' => $total <= 0.0 ? EventinStatusTable::TXN_FREE : $status,
                'created_at'         => (string) $order['post_date'] !== ''
                    ? new SourceDateTime((string) $order['post_date'], TimezoneModel::SITE_LOCAL)
                    : null,
                'total'              => $total,
                'currency'           => $currency,
                'payments'           => $this->payments((string) $order['ID'], $fields, $total, $status, $currency, (string) $order['post_date']),
                'answers'            => $answers,
                'unmapped'           => $this->unmapped($fields, $attendees),
            ]
        );
    }


    /**
     * The meta keys of the file fields in the global schema and in the events' own schemas.
     *
     * @param int[] $event_ids
     * @return string[]
     * @throws MigrationRecordException
     */
    private function fileKeys(array $event_ids): array
    {
        $keys = EventinCustomFields::fileKeys($this->settings->extraFields());
        foreach ($this->db->postMeta($event_ids, ['attendee_extra_fields']) as $fields) {
            $keys = array_merge($keys, EventinCustomFields::fileKeys((array) ($fields['attendee_extra_fields'] ?? [])));
        }
        return $keys;
    }


    /**
     * The buyer: the WordPress user on the order, or a guest identified by a hash of the email.
     */
    private function buyer(array $fields, string $order_id): SourceAttendeeRecord
    {
        $user_id = (int) ($fields['customer_id'] ?? 0) ?: (int) ($fields['user_id'] ?? 0);
        $email   = strtolower(trim((string) ($fields['customer_email'] ?? '')));
        $first   = trim((string) ($fields['customer_fname'] ?? ''));
        $last    = trim((string) ($fields['customer_lname'] ?? ''));
        $source  = $user_id > 0 ? 'user:' . $user_id : ($email !== '' ? 'guest:' . md5($email) : 'guest-order:' . $order_id);
        return new SourceAttendeeRecord(
            $source,
            [
                'first_name' => $first,
                'last_name'  => $last,
                'full_name'  => trim($first . ' ' . $last),
                'email'      => $email,
                'phone'      => trim((string) ($fields['customer_phone'] ?? '')),
                'city'       => trim((string) ($fields['customer_city'] ?? '')),
                'state'      => trim((string) ($fields['customer_state'] ?? '')),
                'country'    => trim((string) ($fields['customer_country'] ?? '')),
                'zip'        => trim((string) ($fields['customer_zip'] ?? '')),
                'wp_user_id' => $user_id,
            ]
        );
    }


    /**
     * One seat per attendee (the ticket and the price it was bought at), or the order's ticket lines at the
     * quantities ordered when the order has no attendee posts.
     *
     * @return SourceSeatRecord[]
     */
    private function seats(array $fields, array $attendees, int $prefix): array
    {
        $lines = is_array($fields['tickets'] ?? null) ? array_values(array_filter($fields['tickets'], 'is_array')) : [];
        $seats = [];
        foreach ($attendees as $index => $attendee) {
            $slug    = (string) ($attendee['meta']['ticket_slug'] ?? '') ?: (string) ($lines[ $index ]['ticket_slug'] ?? ($lines[0]['ticket_slug'] ?? ''));
            $name    = trim((string) ($attendee['meta']['etn_name'] ?? ''));
            $seats[] = new SourceSeatRecord(
                EventinTicketReader::ticketId($prefix, $slug),
                (float) ($attendee['meta']['etn_ticket_price'] ?? 0),
                1,
                $name !== '' ? $name : null
            );
        }
        if ($seats !== []) {
            return $seats;
        }
        $count = max(1, array_sum(array_map(static fn(array $line): int => max(1, (int) ($line['ticket_quantity'] ?? 1)), $lines)));
        foreach ($lines as $line) {
            $quantity = max(1, (int) ($line['ticket_quantity'] ?? 1));
            $seats[]  = new SourceSeatRecord(
                EventinTicketReader::ticketId($prefix, (string) ($line['ticket_slug'] ?? '')),
                (float) ($fields['total_price'] ?? 0) / $count,
                $quantity
            );
        }
        return $seats;
    }


    /**
     * When the order total is the seats' list prices less the order's discount, the seats are scaled to the total
     * (a coupon is a discount on the order in Eventin, but a seat carries the ticket's list price). Orders whose
     * difference is something else (tax) keep the list prices.
     *
     * @param SourceSeatRecord[] $seats
     * @return SourceSeatRecord[]
     */
    private function discounted(array $seats, float $total, float $discount): array
    {
        $listed = 0.0;
        foreach ($seats as $seat) {
            $listed += $seat->pricePaid() * $seat->quantity();
        }
        return $discount > 0.0 && abs($listed - $discount - $total) <= 0.01 ? SeatPricing::scaleToTotal($seats, $total) : $seats;
    }


    /**
     * The order's payment (its total, in the order's status) and one refund per refund row; a refunded order
     * without refund rows has one refund of unknown amount.
     *
     * @return SourcePaymentRecord[]
     */
    private function payments(string $order_id, array $fields, float $total, string $status, string $currency, string $placed): array
    {
        if ($total <= 0.0 || $status === EventinSchema::STATUS_WAITING) {
            return [];
        }
        $attempted = trim((string) ($fields['payment_id'] ?? '')) !== '';
        $state     = [
            'completed'          => EventinStatusTable::PAYMENT_PAID,
            'partially_refunded' => EventinStatusTable::PAYMENT_PAID,
            'refunded'           => EventinStatusTable::PAYMENT_PAID,
            'pending'            => EventinStatusTable::PAYMENT_PENDING,
            'failed'             => $attempted ? EventinStatusTable::PAYMENT_FAILED : '',
            'cancelled'          => $attempted ? EventinStatusTable::PAYMENT_VOIDED : '',
        ][ $status ] ?? '';
        if ($state === '') {
            return [];
        }
        $when     = new SourceDateTime($placed, TimezoneModel::SITE_LOCAL);
        $extra    = [
            'currency'       => $currency,
            'gateway'        => (string) ($fields['payment_method'] ?? ''),
            'gateway_txn_id' => (string) ($fields['payment_id'] ?? ''),
            'paid_at'        => $placed !== '' ? $when : null,
        ];
        $payments = [new SourcePaymentRecord($order_id, $total, $state, $extra)];
        foreach ($this->refunds($fields) as $index => $refund) {
            $amount     = (float) ($refund['amount'] ?? 0);
            $payments[] = new SourcePaymentRecord(
                $order_id . ':refund:' . ($refund['id'] ?? $index),
                $amount > 0.0 ? $amount : null,
                EventinStatusTable::PAYMENT_REFUND,
                array_merge($extra, ['refund' => true, 'paid_at' => $this->refundTime($refund, $when)])
            );
        }
        if (count($payments) === 1 && $status === EventinSchema::STATUS_REFUNDED) {
            $payments[] = new SourcePaymentRecord($order_id . ':refund', null, EventinStatusTable::PAYMENT_REFUND, array_merge($extra, ['refund' => true]));
        }
        return $payments;
    }


    /**
     * @param array $fields The order meta, whose etn_refunds is a JSON string or an array.
     * @return array[]
     */
    private function refunds(array $fields): array
    {
        $stored = $fields['etn_refunds'] ?? [];
        $rows   = is_string($stored) ? json_decode($stored, true) : $stored;
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }


    private function refundTime(array $refund, SourceDateTime $fallback): SourceDateTime
    {
        $at = trim((string) ($refund['created_at'] ?? ''));
        return $at !== '' && strtotime($at) !== false
            ? new SourceDateTime(gmdate('Y-m-d H:i:s', (int) strtotime($at)), TimezoneModel::SITE_LOCAL)
            : $fallback;
    }


    /**
     * @param array   $fields    The order meta.
     * @param array[] $attendees The order's attendees.
     * @return UnmappedConcept[]
     */
    private function unmapped(array $fields, array $attendees): array
    {
        $concepts = [];
        $coupon   = trim((string) ($fields['coupon_code'] ?? ''));
        if ($coupon !== '') {
            $concepts[] = new UnmappedConcept('coupon', __('Coupon', 'migrate-events-to-event-espresso'), [$coupon . ': ' . (float) ($fields['discount_total'] ?? 0)]);
        }
        if ((float) ($fields['tax_total'] ?? 0) > 0.0) {
            $concepts[] = new UnmappedConcept('tax', __('Tax included in the order total', 'migrate-events-to-event-espresso'), [(string) $fields['tax_total']]);
        }
        $used = [];
        $more = [];
        foreach ($attendees as $index => $attendee) {
            if (($attendee['meta']['etn_attendeee_ticket_status'] ?? '') === 'used') {
                $used[] = (string) ($attendee['meta']['etn_name'] ?? $attendee['id']);
            }
            foreach ($index > 0 ? EventinCustomFields::answers($attendee['answers'], []) : [] as $answer) {
                $more[] = trim((string) ($attendee['meta']['etn_name'] ?? '')) . ': ' . $answer->fieldSourceId() . ' = ' . $answer->value();
            }
        }
        if ($used !== []) {
            $concepts[] = new UnmappedConcept('checked-in', __('Checked in', 'migrate-events-to-event-espresso'), $used);
        }
        if ($more !== []) {
            $concepts[] = new UnmappedConcept('seat-answers', __('Answers of the other attendees', 'migrate-events-to-event-espresso'), $more);
        }
        return $concepts;
    }


    /**
     * The attendee posts of several orders: order id => [['id', 'meta', 'answers']] in post id order.
     *
     * @param int[] $order_ids
     * @return array<int, array[]>
     * @throws MigrationRecordException
     */
    private function attendees(array $order_ids): array
    {
        $rows = $this->db->rows(
            'SELECT p.ID, mo.meta_value AS order_id FROM ' . $this->db->table('posts') . ' p JOIN ' . $this->db->table('postmeta')
            . ' mo ON mo.post_id = p.ID AND mo.meta_key = %s WHERE p.post_type = %s AND p.post_status NOT IN ('
            . SourceDatabase::placeholders(EventinSchema::EXCLUDED_POST_STATUSES, '%s') . ') AND mo.meta_value IN ('
            . SourceDatabase::placeholders($order_ids, '%s') . ') ORDER BY p.ID',
            array_merge(['eventin_order_id', EventinSchema::POST_TYPE_ATTENDEE], EventinSchema::EXCLUDED_POST_STATUSES, array_map('strval', $order_ids))
        );
        if ($rows === []) {
            return [];
        }
        $ids     = array_map('intval', array_column($rows, 'ID'));
        $meta    = $this->db->postMeta($ids, self::ATTENDEE_META);
        $answers = $this->answerMeta($ids);
        $grouped = [];
        foreach ($rows as $row) {
            $id                                    = (int) $row['ID'];
            $grouped[ (int) $row['order_id'] ][] = ['id' => $id, 'meta' => $meta[ $id ] ?? [], 'answers' => $answers[ $id ] ?? []];
        }
        return $grouped;
    }


    /**
     * The extra-field answers of attendees: post id => meta key => value.
     *
     * @param int[] $attendee_ids
     * @return array<int, array<string, mixed>>
     * @throws MigrationRecordException
     */
    private function answerMeta(array $attendee_ids): array
    {
        $rows    = $this->db->rows(
            'SELECT post_id, meta_key, meta_value FROM ' . $this->db->table('postmeta') . ' WHERE meta_key LIKE %s AND post_id IN ('
            . SourceDatabase::placeholders($attendee_ids) . ') ORDER BY meta_id',
            array_merge([EventinSchema::ANSWER_META_PREFIX . '%'], $attendee_ids)
        );
        $answers = [];
        foreach ($rows as $row) {
            $answers[ (int) $row['post_id'] ][ (string) $row['meta_key'] ] = SourceDatabase::safeUnserialize((string) $row['meta_value']);
        }
        return $answers;
    }
}
// End of file EventinBookingReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventin/EventinBookingReader.php
