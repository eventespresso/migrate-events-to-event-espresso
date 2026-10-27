<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\event_tickets;

use EventEspresso\EventMigrator\application\services\migration\records\SourceAttendeeRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceBookingRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceCheckinRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\records\SourcePaymentRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceSeatRecord;
use EventEspresso\EventMigrator\application\services\migration\records\UnmappedConcept;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventTicketsBookingReader
 *
 * Reads a page of an event's Tickets Commerce orders (research profile §2.3,
 * §2.4, §8). One order is one booking for an event; its attendee posts are the
 * seats. An RSVP attendee who answered "not going" is left out, and so is an
 * order with no seat left for the event. An order that covers several events
 * is one booking per event, the lowest event id keeping the order id and the
 * others `<order>-<event>`. A Tickets Commerce order carries its payment as
 * meta, so payments are derived from the order's status, total and gateway.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventTicketsBookingReader
{
    /**
     * Order statuses whose payment is the captured amount.
     */
    private const PAID = ['completed', 'refunded', 'reversed'];

    private const PENDING = ['pending', 'action-req', 'approved'];

    private SourceDatabase $db;

    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * @return SourceBookingRecord[]
     * @throws MigrationRecordException When a read fails.
     */
    public function read(string $source_event_id, int $offset, int $limit): array
    {
        $event_id = (int) $source_event_id;
        $orders   = $this->orderIds($event_id, $limit, $offset);
        if ($orders === []) {
            return [];
        }
        $posts     = $this->db->posts($orders);
        $meta      = $this->db->postMeta($orders, EventTicketsSchema::orderMetaKeys());
        $attendees = $this->attendees($orders);
        $records   = [];
        foreach ($orders as $order_id) {
            $seats = array_values(
                array_filter(
                    $attendees[ $order_id ] ?? [],
                    static fn(array $attendee): bool => $attendee['event'] === $event_id && $attendee['rsvp'] !== 'no'
                )
            );
            if ($seats === [] || ! isset($posts[ $order_id ])) {
                continue;
            }
            $events    = array_unique(array_column($attendees[ $order_id ] ?? [], 'event'));
            $booking   = $event_id === min($events) ? (string) $order_id : $order_id . '-' . $event_id;
            $order     = $meta[ $order_id ] ?? [];
            if (count($events) > 1) {
                // The order total covers several events: this booking's total is its own seats.
                $order[ EventTicketsSchema::ORDER_TOTAL ] = array_sum(array_map(static fn(array $seat): float => (float) ($seat['price'] ?? 0), $seats));
            }
            $records[] = $this->record($booking, $event_id, $posts[ $order_id ], $order, $seats);
        }
        return $records;
    }


    /**
     * The orders that have a seat for an event, ascending, one page.
     *
     * @return int[]
     * @throws MigrationRecordException
     */
    private function orderIds(int $event_id, int $limit, int $offset): array
    {
        return array_map(
            'intval',
            $this->db->column(
                'SELECT DISTINCT a.post_parent FROM ' . $this->db->table('posts') . ' a'
                . ' JOIN ' . $this->db->table('postmeta') . ' ev ON ev.post_id = a.ID AND ev.meta_key = %s AND ev.meta_value = %s'
                . ' LEFT JOIN ' . $this->db->table('postmeta') . ' rs ON rs.post_id = a.ID AND rs.meta_key = %s'
                . ' JOIN ' . $this->db->table('posts') . ' o ON o.ID = a.post_parent AND o.post_type = %s AND o.post_status <> %s'
                . ' WHERE a.post_type = %s AND (rs.meta_value IS NULL OR rs.meta_value <> %s)'
                . ' ORDER BY a.post_parent LIMIT %d OFFSET %d',
                [
                    EventTicketsSchema::ATTENDEE_EVENT,
                    (string) $event_id,
                    EventTicketsSchema::ATTENDEE_RSVP_STATUS,
                    EventTicketsSchema::POST_TYPE_ORDER,
                    'trash',
                    EventTicketsSchema::POST_TYPE_ATTENDEE,
                    'no',
                    max(1, $limit),
                    max(0, $offset),
                ]
            )
        );
    }


    /**
     * Every attendee of the orders (refunded orders' attendees are trashed, so all statuses): order id => seats.
     *
     * @param int[] $order_ids
     * @return array<int, array<int, array<string, mixed>>>
     * @throws MigrationRecordException
     */
    private function attendees(array $order_ids): array
    {
        $rows = $this->db->rows(
            'SELECT ID, post_parent FROM ' . $this->db->table('posts')
            . ' WHERE post_type = %s AND post_status <> %s AND post_parent IN (' . SourceDatabase::placeholders($order_ids) . ')'
            . ' ORDER BY post_parent, ID',
            array_merge([EventTicketsSchema::POST_TYPE_ATTENDEE, 'auto-draft'], $order_ids)
        );
        $meta = $this->db->postMeta(array_map('intval', array_column($rows, 'ID')), EventTicketsSchema::attendeeMetaKeys());
        $by_order = [];
        foreach ($rows as $row) {
            $id     = (int) $row['ID'];
            $fields = $meta[ $id ] ?? [];
            $by_order[ (int) $row['post_parent'] ][] = [
                'id'         => $id,
                'event'      => (int) ($fields[ EventTicketsSchema::ATTENDEE_EVENT ] ?? 0),
                'ticket'     => (string) ($fields[ EventTicketsSchema::ATTENDEE_TICKET ] ?? ''),
                'price'      => $fields[ EventTicketsSchema::ATTENDEE_PRICE_PAID ] ?? null,
                'rsvp'       => (string) ($fields[ EventTicketsSchema::ATTENDEE_RSVP_STATUS ] ?? ''),
                'optout'     => (string) ($fields[ EventTicketsSchema::ATTENDEE_OPTOUT ] ?? '') === '1',
                'checked_in' => (string) ($fields[ EventTicketsSchema::ATTENDEE_CHECKED_IN ] ?? '') === '1',
                'details'    => $fields[ EventTicketsSchema::ATTENDEE_CHECKED_IN_DETAILS ] ?? [],
                'name'       => (string) ($fields[ EventTicketsSchema::ATTENDEE_NAME ] ?? ''),
            ];
        }
        return $by_order;
    }


    /**
     * @param string $booking  The booking's source id.
     * @param int    $event_id The event.
     * @param array  $post     The order post row.
     * @param array  $meta     The order meta.
     * @param array  $seats    The order's seats for the event.
     */
    private function record(string $booking, int $event_id, array $post, array $meta, array $seats): SourceBookingRecord
    {
        $status   = $post['post_status'];
        $slug     = strpos($status, EventTicketsSchema::ORDER_STATUS_PREFIX) === 0
            ? substr($status, strlen(EventTicketsSchema::ORDER_STATUS_PREFIX))
            : $status;
        $total    = isset($meta[ EventTicketsSchema::ORDER_TOTAL ]) ? (float) $meta[ EventTicketsSchema::ORDER_TOTAL ] : null;
        $currency = strtoupper(trim((string) ($meta[ EventTicketsSchema::ORDER_CURRENCY ] ?? '')));
        $checkins = [];
        foreach ($seats as $seat) {
            if ($seat['checked_in'] && $checkins === []) {
                $when       = is_array($seat['details']) ? trim((string) ($seat['details']['date'] ?? '')) : '';
                $checkins[] = new SourceCheckinRecord(
                    (string) $event_id,
                    new SourceDateTime($when !== '' ? $when : $post['post_date'], TimezoneModel::SITE_LOCAL),
                    true
                );
            }
        }
        return new SourceBookingRecord(
            $booking,
            $status,
            $this->purchaser($meta, $seats),
            array_map(
                static fn(array $seat): SourceSeatRecord => new SourceSeatRecord(
                    $seat['ticket'],
                    (float) ($seat['price'] ?? 0),
                    1,
                    $seat['name'] !== '' ? $seat['name'] : null
                ),
                $seats
            ),
            [
                'reference'          => (string) ($meta[ EventTicketsSchema::ORDER_HASH ] ?? ''),
                'transaction_status' => $status,
                'created_at'         => new SourceDateTime($post['post_date'], TimezoneModel::SITE_LOCAL),
                'total'              => $total,
                'currency'           => $currency,
                'payments'           => $this->payments($booking, $slug, $total, $currency, $meta),
                'checkins'           => $checkins,
                'unmapped'           => $this->coupons($booking, $meta),
            ]
        );
    }


    /**
     * The coupons applied to the order, as "<code> (<discount>)" (REQ-016: Event Espresso has no coupon to map them
     * to). An order split over several events reports them on the booking that keeps the order id only.
     *
     * @return UnmappedConcept[]
     */
    private function coupons(string $booking, array $meta): array
    {
        $items = $meta[ EventTicketsSchema::ORDER_ITEMS ] ?? [];
        if (! is_array($items) || strpos($booking, '-') !== false) {
            return [];
        }
        $coupons = [];
        foreach ($items as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'coupon') {
                $coupons[] = trim((string) ($item['slug'] ?? ''))
                    . ' (' . abs((float) ($item['sub_total'] ?? 0)) . ')';
            }
        }
        return $coupons !== [] ? [new UnmappedConcept('coupon', __('Coupons', 'migrate-events-to-event-espresso'), $coupons)] : [];
    }


    /**
     * The purchaser: a WordPress user, or a guest identified by a hash of the email.
     */
    private function purchaser(array $meta, array $seats): SourceAttendeeRecord
    {
        $user_id = (int) ($meta[ EventTicketsSchema::ORDER_USER_ID ] ?? 0);
        $email   = trim((string) ($meta[ EventTicketsSchema::ORDER_EMAIL ] ?? ''));
        $id      = $user_id > 0 ? 'user:' . $user_id : ($email !== '' ? 'guest:' . md5(strtolower($email)) : 'guest-order:' . md5(wp_json_encode($meta)));
        return new SourceAttendeeRecord(
            $id,
            [
                'first_name' => (string) ($meta[ EventTicketsSchema::ORDER_FIRST_NAME ] ?? ''),
                'last_name'  => (string) ($meta[ EventTicketsSchema::ORDER_LAST_NAME ] ?? ''),
                'full_name'  => (string) ($meta[ EventTicketsSchema::ORDER_FULL_NAME ] ?? ''),
                'email'      => $email,
                'wp_user_id' => $user_id,
                'opted_out'  => $seats !== [] && count(array_filter($seats, static fn(array $seat): bool => $seat['optout'])) === count($seats),
            ]
        );
    }


    /**
     * The order's payments, derived from its status (research profile §6): none for a free or unpaid order, one
     * for a captured or pending one, a refund as well for a refunded or reversed one. A refund's amount is not
     * stored, so it is left empty for the engine to infer.
     *
     * @return SourcePaymentRecord[]
     */
    private function payments(string $booking, string $slug, ?float $total, string $currency, array $meta): array
    {
        if ($total === null || $total <= 0.0) {
            return [];
        }
        $common = [
            'currency'       => $currency,
            'gateway'        => (string) ($meta[ EventTicketsSchema::ORDER_GATEWAY ] ?? ''),
            'gateway_txn_id' => (string) ($meta[ EventTicketsSchema::ORDER_GATEWAY_ORDER_ID ] ?? ''),
        ];
        $paid_at = $this->logged($meta, 'completed');
        if (in_array($slug, self::PAID, true)) {
            $payments = [new SourcePaymentRecord($booking . '-pay', $total, 'paid', $common + ['paid_at' => $paid_at])];
            if ($slug !== 'completed') {
                $payments[] = new SourcePaymentRecord(
                    $booking . '-refund',
                    null,
                    'refund',
                    $common + ['paid_at' => $this->logged($meta, $slug), 'refund' => true]
                );
            }
            return $payments;
        }
        $states = ['denied' => 'declined', 'not-completed' => 'failed', 'voided' => 'voided'];
        if (in_array($slug, self::PENDING, true)) {
            return [new SourcePaymentRecord($booking . '-pay', $total, 'pending', $common)];
        }
        return isset($states[ $slug ]) ? [new SourcePaymentRecord($booking . '-pay', $total, $states[ $slug ], $common)] : [];
    }


    /**
     * When an order entered a status: the log stores epoch milliseconds.
     */
    private function logged(array $meta, string $slug): ?SourceDateTime
    {
        $milliseconds = (string) ($meta[ EventTicketsSchema::ORDER_STATUS_LOG . $slug ] ?? '');
        return ctype_digit($milliseconds) && strlen($milliseconds) >= 10
            ? new SourceDateTime((string) intdiv((int) $milliseconds, 1000), TimezoneModel::UTC)
            : null;
    }
}
// End of file EventTicketsBookingReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\event_tickets/EventTicketsBookingReader.php
