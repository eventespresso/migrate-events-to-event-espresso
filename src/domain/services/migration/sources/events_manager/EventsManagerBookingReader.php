<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_manager;

use EventEspresso\EventMigrator\application\services\migration\records\SourceAnswerRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceAttendeeRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceBookingRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\records\SourcePaymentRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceSeatRecord;
use EventEspresso\EventMigrator\application\services\migration\records\UnmappedConcept;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventsManagerBookingReader
 *
 * Reads a page of an event's em_bookings (research profile §3, §8): one seat
 * line per em_tickets_bookings row, the booker (a WordPress user, or a guest
 * from the `_registration|*` booking meta), the comment as an answer, and the
 * Events Manager Pro payments when em_transactions exists. Guest ids are a
 * hash of the email, so the crosswalk and log never carry it (REQ-107).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventsManagerBookingReader
{
    public const COMMENT_FIELD = 'booking_comment';

    private const GUEST_FIELDS = ['user_email', 'user_name', 'first_name', 'last_name', 'dbem_phone'];

    private const USER_META = ['first_name', 'last_name', 'dbem_phone'];

    private SourceDatabase $db;

    private EventsManagerScope $scope;

    private EventsManagerTicketReader $tickets;


    public function __construct(SourceDatabase $db, EventsManagerScope $scope, EventsManagerTicketReader $tickets)
    {
        $this->db      = $db;
        $this->scope   = $scope;
        $this->tickets = $tickets;
    }


    /**
     * @return SourceBookingRecord[]
     * @throws MigrationRecordException When a read fails.
     */
    public function read(string $source_event_id, int $offset, int $limit): array
    {
        $event_ids = $this->scope->occurrenceIdsOf((int) $source_event_id);
        $bookings  = $this->db->rows(
            'SELECT * FROM ' . $this->db->table(EventsManagerSchema::BOOKINGS)
            . ' WHERE event_id IN (' . SourceDatabase::placeholders($event_ids) . ')'
            . ' ORDER BY booking_id LIMIT %d OFFSET %d',
            array_merge($event_ids, [max(1, $limit), max(0, $offset)])
        );
        if ($bookings === []) {
            return [];
        }
        $ids      = array_map('intval', array_column($bookings, 'booking_id'));
        $seats    = $this->grouped(EventsManagerSchema::TICKETS_BOOKINGS, $ids, 'ticket_booking_order, ticket_booking_id');
        $payments = $this->db->tableExists(EventsManagerSchema::TRANSACTIONS)
            ? $this->grouped(EventsManagerSchema::TRANSACTIONS, $ids, 'transaction_id')
            : [];
        $guests   = $this->guests($ids);
        $users    = $this->db->users(array_column($bookings, 'person_id'), self::USER_META);
        $records  = [];
        foreach ($bookings as $booking) {
            $id        = (int) $booking['booking_id'];
            $records[] = $this->record($booking, $seats[ $id ] ?? [], $payments[ $id ] ?? [], $guests[ $id ] ?? [], $users);
        }
        return $records;
    }


    /**
     * @param array   $booking  An em_bookings row.
     * @param array[] $seats    Its em_tickets_bookings rows.
     * @param array[] $payments Its em_transactions rows.
     * @param array   $guest    Its `_registration|*` meta, field => value.
     * @param array   $users    WordPress users by id.
     * @throws MigrationRecordException
     */
    private function record(array $booking, array $seats, array $payments, array $guest, array $users): SourceBookingRecord
    {
        $id       = (string) $booking['booking_id'];
        $currency = $this->tickets->currency();
        $total    = (float) $booking['booking_price'];
        $payment_records = array_map(
            fn(array $payment): SourcePaymentRecord => $this->payment($payment, $currency),
            $payments
        );
        $comment  = trim((string) $booking['booking_comment']);
        $unmapped = [];
        if ((float) $booking['booking_taxes'] > 0) {
            $unmapped[] = new UnmappedConcept('tax', __('Tax included in the booking total', 'migrate-events-to-event-espresso'), [(string) $booking['booking_taxes']]);
        }
        if ((int) $booking['timeslot_id'] > 0) {
            $unmapped[] = new UnmappedConcept('timeslots', __('Timeslot', 'migrate-events-to-event-espresso'), [(string) $booking['timeslot_id']]);
        }
        return new SourceBookingRecord(
            $id,
            (string) (int) $booking['booking_status'],
            $this->attendee((int) $booking['person_id'], $guest, $users, $id),
            array_map(
                static fn(array $seat): SourceSeatRecord => new SourceSeatRecord(
                    (string) $seat['ticket_id'],
                    (float) $seat['ticket_booking_price'] / max(1, (int) $seat['ticket_booking_spaces']),
                    max(1, (int) $seat['ticket_booking_spaces'])
                ),
                $seats
            ),
            [
                'transaction_status' => $this->transactionStatus($booking, $total, $payment_records),
                'created_at'         => new SourceDateTime((string) $booking['booking_date'], TimezoneModel::SITE_LOCAL),
                'total'              => $total,
                'currency'           => $currency,
                'payments'           => $payment_records,
                'answers'            => $comment !== '' ? [new SourceAnswerRecord(self::COMMENT_FIELD, [$comment])] : [],
                'unmapped'           => $unmapped,
            ]
        );
    }


    /**
     * The booker: the WordPress user, or the guest identity from the booking meta.
     */
    private function attendee(int $person_id, array $guest, array $users, string $booking_id): SourceAttendeeRecord
    {
        if ($person_id > 0) {
            $user = $users[ $person_id ] ?? [];
            return new SourceAttendeeRecord(
                'user:' . $person_id,
                [
                    'first_name' => $user['first_name'] ?? '',
                    'last_name'  => $user['last_name'] ?? '',
                    'full_name'  => $user['display_name'] ?? '',
                    'email'      => $user['user_email'] ?? '',
                    'phone'      => $user['dbem_phone'] ?? '',
                    'wp_user_id' => $person_id,
                ]
            );
        }
        $email = strtolower(trim((string) ($guest['user_email'] ?? '')));
        return new SourceAttendeeRecord(
            $email !== '' ? 'guest:' . md5($email) : 'guest-booking:' . $booking_id,
            [
                'first_name' => $guest['first_name'] ?? '',
                'last_name'  => $guest['last_name'] ?? '',
                'full_name'  => $guest['user_name'] ?? '',
                'email'      => $guest['user_email'] ?? '',
                'phone'      => $guest['dbem_phone'] ?? '',
            ]
        );
    }


    /**
     * @throws MigrationRecordException
     */
    private function payment(array $row, string $currency): SourcePaymentRecord
    {
        $status = strtolower(trim((string) $row['transaction_status']));
        $amount = $row['transaction_total_amount'] !== null ? (float) $row['transaction_total_amount'] : null;
        return new SourcePaymentRecord(
            (string) $row['transaction_id'],
            $amount !== null ? abs($amount) : null,
            $status,
            [
                'currency'       => (string) ($row['transaction_currency'] ?: $currency),
                'gateway'        => (string) $row['transaction_gateway'],
                'gateway_txn_id' => (string) $row['transaction_gateway_id'],
                'paid_at'        => new SourceDateTime((string) $row['transaction_timestamp'], TimezoneModel::SITE_LOCAL),
                'refund'         => $status === 'refunded' || ($amount !== null && $amount < 0),
            ]
        );
    }


    /**
     * "free" when there is nothing to pay, "paid" when approved payments cover the total, otherwise the booking
     * status (EventsManagerStatusTable).
     *
     * @param array                 $booking  The em_bookings row.
     * @param float                 $total    The booking total.
     * @param SourcePaymentRecord[] $payments The booking's payments.
     */
    private function transactionStatus(array $booking, float $total, array $payments): string
    {
        if ($total <= 0.0) {
            return EventsManagerStatusTable::TXN_FREE;
        }
        $approved = EventsManagerStatusTable::table()->payment();
        $paid     = 0.0;
        foreach ($payments as $payment) {
            if (($approved[ $payment->status() ] ?? '') === 'PAP' && ! $payment->isRefund()) {
                $paid += (float) $payment->amount();
            }
        }
        return $paid + 0.005 >= $total ? EventsManagerStatusTable::TXN_PAID : (string) (int) $booking['booking_status'];
    }


    /**
     * @param int[] $booking_ids
     * @return array<int, array<string, string>> booking id => guest field => value
     * @throws MigrationRecordException
     */
    private function guests(array $booking_ids): array
    {
        $keys   = array_map(static fn(string $field): string => EventsManagerSchema::GUEST_META_PREFIX . $field, self::GUEST_FIELDS);
        $rows   = $this->db->rows(
            'SELECT booking_id, meta_key, meta_value FROM ' . $this->db->table(EventsManagerSchema::BOOKINGS_META)
            . ' WHERE booking_id IN (' . SourceDatabase::placeholders($booking_ids) . ')'
            . ' AND meta_key IN (' . SourceDatabase::placeholders($keys, '%s') . ') ORDER BY meta_id',
            array_merge($booking_ids, $keys)
        );
        $guests = [];
        foreach ($rows as $row) {
            $field = substr((string) $row['meta_key'], strlen(EventsManagerSchema::GUEST_META_PREFIX));
            $guests[ (int) $row['booking_id'] ][ $field ] = (string) $row['meta_value'];
        }
        return $guests;
    }


    /**
     * Rows of a booking-keyed table for several bookings: booking id => rows.
     *
     * @param string $table       An adapter table constant keyed by booking_id.
     * @param int[]  $booking_ids The bookings.
     * @param string $order       Extra ORDER BY columns from the table's own constants.
     * @return array<int, array[]>
     * @throws MigrationRecordException
     */
    private function grouped(string $table, array $booking_ids, string $order): array
    {
        $grouped = [];
        $rows    = $this->db->rows(
            'SELECT * FROM ' . $this->db->table($table)
            . ' WHERE booking_id IN (' . SourceDatabase::placeholders($booking_ids) . ') ORDER BY booking_id, ' . $order,
            $booking_ids
        );
        foreach ($rows as $row) {
            $grouped[ (int) $row['booking_id'] ][] = $row;
        }
        return $grouped;
    }
}
// End of file EventsManagerBookingReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_manager/EventsManagerBookingReader.php
