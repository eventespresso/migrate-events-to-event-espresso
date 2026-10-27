<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\amelia;

use EventEspresso\EventMigrator\application\services\migration\records\SourceAnswerRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceAttendeeRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceBookingRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourcePaymentRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceSeatRecord;
use EventEspresso\EventMigrator\application\services\migration\records\UnmappedConcept;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class AmeliaBookingReader
 *
 * Reads a page of an event's bookings (research profile §3, §8). A booking
 * reaches an event through the periods it is linked to; its seats are the
 * ticket lines (customer_bookings_to_events_tickets) or, for an event sold at
 * its own price, one line for all its persons on the event's implicit ticket.
 * The booker is an Amelia customer (a contact, with the WordPress user id
 * when the customer has an account), payments are the payments rows (a
 * refunded payment becomes the payment plus a refund whose amount the engine
 * infers), and custom-field answers come from the booking's JSON and the
 * customer's. The cancel-link token is deliberately not carried over.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class AmeliaBookingReader
{
    private SourceDatabase $db;

    private AmeliaScope $scope;

    private AmeliaTicketReader $tickets;

    private AmeliaSettings $settings;


    public function __construct(
        SourceDatabase $db,
        AmeliaScope $scope,
        AmeliaTicketReader $tickets,
        AmeliaSettings $settings
    ) {
        $this->db       = $db;
        $this->scope    = $scope;
        $this->tickets  = $tickets;
        $this->settings = $settings;
    }


    /**
     * @return SourceBookingRecord[]
     * @throws MigrationRecordException When a read fails.
     */
    public function read(string $source_event_id, int $offset, int $limit): array
    {
        $event_ids = $this->scope->occurrenceIdsOf($source_event_id);
        $bookings  = $this->db->rows(
            'SELECT * FROM ' . $this->db->table(AmeliaSchema::BOOKINGS) . ' WHERE id IN ('
            . 'SELECT bp.customerBookingId FROM ' . $this->db->table(AmeliaSchema::BOOKING_PERIODS) . ' bp'
            . ' JOIN ' . $this->db->table(AmeliaSchema::PERIODS) . ' ep ON ep.id = bp.eventPeriodId'
            . ' WHERE ep.eventId IN (' . SourceDatabase::placeholders($event_ids) . '))'
            . ' ORDER BY id LIMIT %d OFFSET %d',
            array_merge($event_ids, [max(1, $limit), max(0, $offset)])
        );
        if ($bookings === []) {
            return [];
        }
        $ids       = array_map('intval', array_column($bookings, 'id'));
        $lines     = $this->grouped(AmeliaSchema::BOOKING_TICKETS, $ids);
        $payments  = $this->grouped(AmeliaSchema::PAYMENTS, $ids);
        $customers = $this->customers(array_map('intval', array_column($bookings, 'customerId')));
        $implicit  = $this->tickets->implicitTicketId($event_ids) ?? AmeliaSchema::IMPLICIT_TICKET_PREFIX . min($event_ids);
        $currency  = $this->settings->currency();
        $records   = [];
        foreach ($bookings as $booking) {
            $id        = (int) $booking['id'];
            $customer  = $customers[ (int) $booking['customerId'] ] ?? [];
            $records[] = $this->record($booking, $lines[ $id ] ?? [], $payments[ $id ] ?? [], $customer, $implicit, $currency);
        }
        return $records;
    }


    /**
     * @param array   $booking  An amelia_customer_bookings row.
     * @param array[] $lines    Its amelia_customer_bookings_to_events_tickets rows.
     * @param array[] $payments Its amelia_payments rows.
     * @param array   $customer Its customer's amelia_users row (empty when the customer was deleted).
     * @param string  $implicit The source id of the event's implicit ticket.
     * @param string  $currency The ISO currency code of Amelia's prices, or ''.
     */
    private function record(
        array $booking,
        array $lines,
        array $payments,
        array $customer,
        string $implicit,
        string $currency
    ): SourceBookingRecord {
        $total   = (float) $booking['price'];
        $records = [];
        foreach ($payments as $payment) {
            $records = array_merge($records, $this->paymentRecords($payment, $currency));
        }
        return new SourceBookingRecord(
            (string) $booking['id'],
            (string) $booking['status'],
            $this->attendee($booking, $customer),
            $this->seats($booking, $lines, $implicit),
            [
                'transaction_status' => $this->transactionStatus($booking, $total, $payments),
                'created_at'         => AmeliaDates::utc($booking['created']),
                'total'              => $total,
                'currency'           => $currency,
                'payments'           => $records,
                'answers'            => $this->answers($booking, $customer),
                'unmapped'           => $this->unmapped($booking),
            ]
        );
    }


    /**
     * The booker: the customer row, with the name and phone the customer gave on the booking as a fallback.
     */
    private function attendee(array $booking, array $customer): SourceAttendeeRecord
    {
        $info  = json_decode((string) $booking['info'], true);
        $info  = is_array($info) ? $info : [];
        $first = trim((string) ($customer['firstName'] ?? '')) ?: trim((string) ($info['firstName'] ?? ''));
        $last  = trim((string) ($customer['lastName'] ?? '')) ?: trim((string) ($info['lastName'] ?? ''));
        return new SourceAttendeeRecord(
            (string) $booking['customerId'],
            [
                'first_name' => $first,
                'last_name'  => $last,
                'full_name'  => trim($first . ' ' . $last),
                'email'      => (string) ($customer['email'] ?? ''),
                'phone'      => trim((string) ($customer['phone'] ?? '')) ?: trim((string) ($info['phone'] ?? '')),
                'wp_user_id' => (int) ($customer['externalId'] ?? 0),
            ]
        );
    }


    /**
     * One seat line per ticket line, or one line for all the booking's persons on the implicit ticket.
     *
     * @return SourceSeatRecord[]
     */
    private function seats(array $booking, array $lines, string $implicit): array
    {
        if ($lines === []) {
            $persons = max(1, (int) $booking['persons']);
            return [new SourceSeatRecord($implicit, (float) $booking['price'] / $persons, $persons)];
        }
        $seats = [];
        foreach ($lines as $line) {
            $persons = max(1, (int) $line['persons']);
            // Aggregated prices are per person; otherwise the line's price is for all its persons.
            $unit    = (int) $booking['aggregatedPrice'] === 1 ? (float) $line['price'] : (float) $line['price'] / $persons;
            $seats[] = new SourceSeatRecord((string) $line['eventTicketId'], $unit, $persons);
        }
        return $seats;
    }


    /**
     * A payment row as its record; a refunded one as the payment and a refund of unknown amount (Amelia does
     * not store the refunded amount separately).
     *
     * @return SourcePaymentRecord[]
     */
    private function paymentRecords(array $payment, string $currency): array
    {
        $refunded = $payment['status'] === 'refunded';
        $extra    = [
            'currency'       => $currency,
            'gateway'        => (string) $payment['gateway'],
            'gateway_txn_id' => (string) $payment['transactionId'],
            'paid_at'        => AmeliaDates::utc($payment['dateTime']),
        ];
        $records  = [
            new SourcePaymentRecord(
                (string) $payment['id'],
                (float) $payment['amount'],
                $refunded ? AmeliaStatusTable::PAYMENT_PAID : (string) $payment['status'],
                $extra
            ),
        ];
        if ($refunded) {
            $records[] = new SourcePaymentRecord(
                $payment['id'] . ':refund',
                null,
                AmeliaStatusTable::PAYMENT_REFUND,
                array_merge($extra, ['refund' => true])
            );
        }
        return $records;
    }


    /**
     * "free" when there is nothing to pay, "paid" when payments not refunded cover the total, otherwise the
     * booking status (AmeliaStatusTable).
     *
     * @param array   $booking  The amelia_customer_bookings row.
     * @param float   $total    The booking total.
     * @param array[] $payments Its amelia_payments rows.
     */
    private function transactionStatus(array $booking, float $total, array $payments): string
    {
        if ($total <= 0.0) {
            return AmeliaStatusTable::TXN_FREE;
        }
        $paid = 0.0;
        foreach ($payments as $payment) {
            if (in_array($payment['status'], ['paid', 'partiallyPaid'], true)) {
                $paid += (float) $payment['amount'];
            }
        }
        return $paid + 0.005 >= $total ? AmeliaStatusTable::TXN_PAID : (string) $booking['status'];
    }


    /**
     * The booking's answers, then the customer-level answers to fields the booking did not answer.
     *
     * @return SourceAnswerRecord[]
     */
    private function answers(array $booking, array $customer): array
    {
        $answers = [];
        foreach (AmeliaCustomFields::answers($booking['customFields']) as $answer) {
            $answers[ $answer->fieldSourceId() ] = $answer;
        }
        foreach (AmeliaCustomFields::answers($customer['customFields'] ?? null) as $answer) {
            $answers[ $answer->fieldSourceId() ] = $answers[ $answer->fieldSourceId() ] ?? $answer;
        }
        return array_values($answers);
    }


    /**
     * Coupon, tax and no-show have no Event Espresso counterpart on a migrated booking.
     *
     * @return UnmappedConcept[]
     */
    private function unmapped(array $booking): array
    {
        $concepts = [];
        if ((int) $booking['couponId'] > 0) {
            $concepts[] = new UnmappedConcept('coupon', __('Coupon', 'migrate-events-to-event-espresso'), [(string) $booking['couponId']]);
        }
        $tax = trim((string) $booking['tax']);
        if (! in_array($tax, ['', '[]', '{}', 'null', '0'], true)) {
            $concepts[] = new UnmappedConcept('tax', __('Tax included in the booking total', 'migrate-events-to-event-espresso'), [$tax]);
        }
        if ($booking['status'] === 'no-show') {
            $concepts[] = new UnmappedConcept('no-show', __('Did not attend', 'migrate-events-to-event-espresso'), ['1']);
        }
        return $concepts;
    }


    /**
     * @param int[] $customer_ids
     * @return array<int, array> customer id => amelia_users row
     * @throws MigrationRecordException
     */
    private function customers(array $customer_ids): array
    {
        $customers = [];
        $rows      = $this->db->rows(
            'SELECT * FROM ' . $this->db->table(AmeliaSchema::USERS)
            . ' WHERE id IN (' . SourceDatabase::placeholders($customer_ids) . ')',
            $customer_ids
        );
        foreach ($rows as $row) {
            $customers[ (int) $row['id'] ] = $row;
        }
        return $customers;
    }


    /**
     * Rows of a table keyed by customerBookingId for several bookings: booking id => rows in id order.
     *
     * @param string $table       An AmeliaSchema table constant with a customerBookingId column.
     * @param int[]  $booking_ids The bookings.
     * @return array<int, array[]>
     * @throws MigrationRecordException
     */
    private function grouped(string $table, array $booking_ids): array
    {
        $grouped = [];
        $rows    = $this->db->rows(
            'SELECT * FROM ' . $this->db->table($table)
            . ' WHERE customerBookingId IN (' . SourceDatabase::placeholders($booking_ids) . ') ORDER BY customerBookingId, id',
            $booking_ids
        );
        foreach ($rows as $row) {
            $grouped[ (int) $row['customerBookingId'] ][] = $row;
        }
        return $grouped;
    }
}
// End of file AmeliaBookingReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\amelia/AmeliaBookingReader.php
