<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy;

use EventEspresso\EventMigrator\application\services\migration\records\SourceAnswerRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceAttendeeRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceBookingRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceCheckinRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceCustomFieldRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\records\SourcePaymentRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceSeatRecord;
use EventEspresso\EventMigrator\application\services\migration\records\UnmappedConcept;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SeatPricing;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventsMadeEasyBookingReader
 *
 * Reads a page of an event's eme_bookings (research profile §3, §8). A booking
 * holds seats per price slot (`booking_seats_mp`, "2||0||1"), priced from the
 * snapshot of the event's prices it was made at; the booker is an eme_people
 * row; a booking has one payment when it is paid (or part paid), recorded on
 * the booking itself, and EME keeps no refunds. The comment and the booking-
 * and person-level form answers are the booking's answers; check-ins are
 * eme_attendances rows. Discounts and extra charges change the booking total,
 * so the seats are scaled to it.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventsMadeEasyBookingReader
{
    private SourceDatabase $db;

    private EventsMadeEasyScope $scope;


    public function __construct(SourceDatabase $db, EventsMadeEasyScope $scope)
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
        $bookings  = $this->db->rows(
            'SELECT * FROM ' . $this->db->table(EventsMadeEasySchema::BOOKINGS)
            . ' WHERE event_id IN (' . SourceDatabase::placeholders($event_ids) . ') ORDER BY booking_id LIMIT %d OFFSET %d',
            array_merge($event_ids, [max(1, $limit), max(0, $offset)])
        );
        if ($bookings === []) {
            return [];
        }
        $ids      = array_map('intval', array_column($bookings, 'booking_id'));
        $people   = array_map('intval', array_column($bookings, 'person_id'));
        $events   = $this->events($event_ids);
        $owner    = $events[ $event_ids[0] ] ?? [];
        $answers  = $this->answers($ids, $people);
        $people_rows = $this->people($people);
        $checkins = $this->checkins($event_ids, $people);
        $fields   = array_map(
            static fn(SourceCustomFieldRecord $field): string => $field->sourceId(),
            (new EventsMadeEasyCustomFields($this->db))->definitions()
        );
        $records  = [];
        foreach ($bookings as $booking) {
            $records[] = $this->record(
                $booking,
                $events[ (int) $booking['event_id'] ] ?? $owner,
                $owner,
                $people_rows[ (int) $booking['person_id'] ] ?? [],
                $answers,
                $checkins[ (int) $booking['event_id'] ][ (int) $booking['person_id'] ] ?? [],
                [$source_event_id, $fields]
            );
        }
        return $records;
    }


    /**
     * @param array $booking  An eme_bookings row.
     * @param array $event    Its event row.
     * @param array $owner    The migrated event's first event row (its price slots name the tickets).
     * @param array $person   Its eme_people row (empty when deleted).
     * @param array $answers  The page's answers: ['booking' => id => rows, 'person' => id => rows].
     * @param array $checkins The booker's check-ins to the event: creation dates.
     * @param array $context  The migrated event id that prefixes ticket ids, then the ids of the fields to ask about.
     */
    private function record(array $booking, array $event, array $owner, array $person, array $answers, array $checkins, array $context): SourceBookingRecord
    {
        [$migrated, $fields] = $context;
        $id       = (int) $booking['booking_id'];
        $seats    = $this->seats($booking, $event, $owner, $migrated);
        $listed   = array_sum(array_map(static fn(SourceSeatRecord $seat): float => $seat->pricePaid() * $seat->quantity(), $seats));
        $total    = max(0.0, $listed + (float) $booking['extra_charge'] - (float) $booking['discount']);
        $currency = strtoupper(trim((string) ($event['currency'] ?? '')));
        $payments = $this->payments($booking, $total, $currency);
        $received = array_sum(array_map(static fn(SourcePaymentRecord $payment): float => (float) $payment->amount(), $payments));
        $status   = (int) $booking['waitinglist'] === 1 ? EventsMadeEasyStatusTable::BOOKING_WAITING : (string) (int) $booking['status'];
        return new SourceBookingRecord(
            (string) $id,
            $status,
            $this->attendee($person, (string) $booking['person_id']),
            SeatPricing::scaleToTotal($seats, $total),
            [
                'reference'          => (string) $booking['unique_nbr'],
                'transaction_status' => $total <= 0.0
                    ? EventsMadeEasyStatusTable::TXN_FREE
                    : ($received + 0.005 >= $total ? EventsMadeEasyStatusTable::TXN_PAID : $status),
                'created_at'         => $this->local((string) $booking['creation_date']),
                'total'              => $total,
                'currency'           => $currency,
                'payments'           => $payments,
                'answers'            => $this->bookingAnswers($booking, $answers, (int) $booking['person_id'], $fields),
                'checkins'           => array_map(
                    fn(string $at): SourceCheckinRecord => new SourceCheckinRecord((string) $booking['event_id'], new SourceDateTime($at, TimezoneModel::SITE_LOCAL)),
                    $checkins
                ),
                'unmapped'           => $this->unmapped($booking, $answers['booking'][ $id ] ?? []),
            ]
        );
    }


    /**
     * One seat line per price slot with seats; a booking without a per-slot list has all its seats on the one
     * price. The ticket is the migrated event's implicit ticket, or its Nth price slot.
     *
     * @return SourceSeatRecord[]
     */
    private function seats(array $booking, array $event, array $owner, string $migrated): array
    {
        $slots    = EventsMadeEasyProps::slots($owner['price'] ?? '');
        $multi    = count($slots) > 1;
        $prices   = EventsMadeEasyProps::slots($booking['event_price']) ?: EventsMadeEasyProps::slots($event['price'] ?? '');
        $counts   = EventsMadeEasyProps::slots($booking['booking_seats_mp']);
        $counts   = $counts !== [] ? $counts : [(string) $booking['booking_seats']];
        $implicit = EventsMadeEasySchema::TICKET_PREFIX . $migrated;
        $seats    = [];
        foreach ($counts as $index => $count) {
            $quantity = (int) $count;
            if ($quantity <= 0) {
                continue;
            }
            $price   = (float) ($prices[ $index ] ?? ($prices[0] ?? 0));
            $seats[] = new SourceSeatRecord($multi ? $implicit . ':' . $index : $implicit, $price, $quantity);
        }
        return $seats;
    }


    /**
     * The booking's payment when it is paid or part paid: what was received (the whole total when only the paid
     * flag is set), through the gateway recorded on the booking.
     *
     * @return SourcePaymentRecord[]
     */
    private function payments(array $booking, float $total, string $currency): array
    {
        $received = (float) $booking['received'];
        if ((int) $booking['booking_paid'] !== 1 && $received <= 0.0) {
            return [];
        }
        $paid_at = trim((string) $booking['payment_date']);
        return [
            new SourcePaymentRecord(
                (string) $booking['booking_id'],
                $received > 0.0 ? $received : $total,
                EventsMadeEasyStatusTable::PAYMENT_PAID,
                [
                    'currency'       => $currency,
                    'gateway'        => (string) $booking['pg'],
                    'gateway_txn_id' => (string) $booking['pg_pid'],
                    'paid_at'        => $this->local($paid_at),
                ]
            ),
        ];
    }


    private function attendee(array $person, string $person_id): SourceAttendeeRecord
    {
        $first = trim((string) ($person['firstname'] ?? ''));
        $last  = trim((string) ($person['lastname'] ?? ''));
        return new SourceAttendeeRecord(
            $person_id,
            [
                'first_name' => $first,
                'last_name'  => $last,
                'full_name'  => trim($first . ' ' . $last),
                'email'      => trim((string) ($person['email'] ?? '')),
                'phone'      => trim((string) ($person['phone'] ?? '')),
                'address'    => trim((string) ($person['address1'] ?? '')),
                'address2'   => trim((string) ($person['address2'] ?? '')),
                'city'       => trim((string) ($person['city'] ?? '')),
                'zip'        => trim((string) ($person['zip'] ?? '')),
                'state'      => trim((string) ($person['state_code'] ?? '')) ?: trim((string) ($person['state'] ?? '')),
                'country'    => trim((string) ($person['country_code'] ?? '')) ?: trim((string) ($person['country'] ?? '')),
                'wp_user_id' => (int) ($person['wp_id'] ?? 0),
            ]
        );
    }


    /**
     * The booking comment, then the booking-level answers, then the booker's own answers to fields the booking
     * did not answer; per-seat (grouped) answers are left to the unmapped concept, and so are answers to fields
     * that are not booking-form questions with a text answer.
     *
     * @param array    $booking   The eme_bookings row.
     * @param array    $answers   The page's answers.
     * @param int      $person_id The booker.
     * @param string[] $fields    The ids of the fields the form asks (EventsMadeEasyCustomFields::definitions()).
     * @return SourceAnswerRecord[]
     */
    private function bookingAnswers(array $booking, array $answers, int $person_id, array $fields): array
    {
        $found   = [];
        $comment = trim((string) $booking['booking_comment']);
        if ($comment !== '') {
            $found[ EventsMadeEasySchema::COMMENT_FIELD ] = new SourceAnswerRecord(EventsMadeEasySchema::COMMENT_FIELD, [$comment]);
        }
        foreach ([$answers['booking'][ (int) $booking['booking_id'] ] ?? [], $answers['person'][ $person_id ] ?? []] as $rows) {
            foreach ($rows as $row) {
                $answer = (int) $row['eme_grouping'] === 0
                    ? EventsMadeEasyCustomFields::answer((string) $row['field_id'], (string) $row['answer'])
                    : null;
                if ($answer instanceof SourceAnswerRecord && in_array($answer->fieldSourceId(), $fields, true) && ! isset($found[ $answer->fieldSourceId() ])) {
                    $found[ $answer->fieldSourceId() ] = $answer;
                }
            }
        }
        return array_values($found);
    }


    /**
     * Discounts, extra charges and per-seat answers have no counterpart on a migrated booking.
     *
     * @param array   $booking The eme_bookings row.
     * @param array[] $rows    Its answer rows.
     * @return UnmappedConcept[]
     */
    private function unmapped(array $booking, array $rows): array
    {
        $concepts = [];
        if ((float) $booking['discount'] > 0.0) {
            $concepts[] = new UnmappedConcept(
                'coupon',
                __('Discount', 'migrate-events-to-event-espresso'),
                [trim((string) $booking['dcodes_used'], '[]" ') . ' (' . (float) $booking['discount'] . ')']
            );
        }
        if ((float) $booking['extra_charge'] > 0.0) {
            $concepts[] = new UnmappedConcept('extra-charge', __('Extra charge', 'migrate-events-to-event-espresso'), [(string) $booking['extra_charge']]);
        }
        $seat_answers = [];
        foreach ($rows as $row) {
            if ((int) $row['eme_grouping'] > 0) {
                $seat_answers[] = $row['occurence'] . ': ' . $row['field_id'] . ' = ' . $row['answer'];
            }
        }
        if ($seat_answers !== []) {
            $concepts[] = new UnmappedConcept('seat-answers', __('Per-seat answers', 'migrate-events-to-event-espresso'), $seat_answers);
        }
        return $concepts;
    }


    /**
     * @param int[] $event_ids
     * @return array<int, array> event id => eme_events row
     * @throws MigrationRecordException
     */
    private function events(array $event_ids): array
    {
        $events = [];
        $rows   = $this->db->rows(
            'SELECT event_id, price, currency, event_start FROM ' . $this->db->table(EventsMadeEasySchema::EVENTS)
            . ' WHERE event_id IN (' . SourceDatabase::placeholders($event_ids) . ') ORDER BY event_start, event_id',
            $event_ids
        );
        foreach ($rows as $row) {
            $events[ (int) $row['event_id'] ] = $row;
        }
        return $events;
    }


    /**
     * @param int[] $person_ids
     * @return array<int, array> person id => eme_people row
     * @throws MigrationRecordException
     */
    private function people(array $person_ids): array
    {
        $people = [];
        $rows   = $this->db->rows(
            'SELECT * FROM ' . $this->db->table(EventsMadeEasySchema::PEOPLE)
            . ' WHERE person_id IN (' . SourceDatabase::placeholders($person_ids) . ')',
            $person_ids
        );
        foreach ($rows as $row) {
            $people[ (int) $row['person_id'] ] = $row;
        }
        return $people;
    }


    /**
     * The answers of a page of bookings and of their bookers.
     *
     * @param int[] $booking_ids
     * @param int[] $person_ids
     * @return array{booking: array<int, array[]>, person: array<int, array[]>}
     * @throws MigrationRecordException
     */
    private function answers(array $booking_ids, array $person_ids): array
    {
        $found = ['booking' => [], 'person' => []];
        foreach (['booking' => $booking_ids, 'person' => $person_ids] as $type => $ids) {
            $rows = $this->db->rows(
                'SELECT * FROM ' . $this->db->table(EventsMadeEasySchema::ANSWERS) . ' WHERE type = %s AND related_id IN ('
                . SourceDatabase::placeholders($ids) . ') ORDER BY answer_id',
                array_merge([$type], $ids)
            );
            foreach ($rows as $row) {
                $found[ $type ][ (int) $row['related_id'] ][] = $row;
            }
        }
        return $found;
    }


    /**
     * The check-ins of the bookers to the events: event id => person id => creation dates.
     *
     * @param int[] $event_ids
     * @param int[] $person_ids
     * @return array<int, array<int, string[]>>
     * @throws MigrationRecordException
     */
    private function checkins(array $event_ids, array $person_ids): array
    {
        $rows  = $this->db->rows(
            'SELECT person_id, related_id, creation_date FROM ' . $this->db->table(EventsMadeEasySchema::ATTENDANCES)
            . ' WHERE type = %s AND related_id IN (' . SourceDatabase::placeholders($event_ids) . ') AND person_id IN ('
            . SourceDatabase::placeholders($person_ids) . ') ORDER BY id',
            array_merge(['event'], $event_ids, $person_ids)
        );
        $found = [];
        foreach ($rows as $row) {
            if (trim((string) $row['creation_date']) !== '') {
                $found[ (int) $row['related_id'] ][ (int) $row['person_id'] ][] = (string) $row['creation_date'];
            }
        }
        return $found;
    }


    private function local(string $value): ?SourceDateTime
    {
        $value = trim($value);
        return $value !== '' && strpos($value, '0000-00-00') !== 0 ? new SourceDateTime($value, TimezoneModel::SITE_LOCAL) : null;
    }
}
// End of file EventsMadeEasyBookingReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy/EventsMadeEasyBookingReader.php
