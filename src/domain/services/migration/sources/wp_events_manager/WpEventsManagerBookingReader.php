<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\wp_events_manager;

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
 * Class WpEventsManagerBookingReader
 *
 * Reads a page of an event's `event_auth_book` posts (research profile §3,
 * §8). A booking is one row for `ea_booking_qty` seats at the total
 * `ea_booking_price` (so each seat is a share of it); its buyer is always a
 * WordPress user, and the booking holds no name or email of its own. The status
 * is the post's. A completed booking that cost something has one payment of
 * its total through the gateway id on the booking (PayPal's transaction id is
 * not stored); a booking PayPal reported pending has a pending payment; others
 * have none.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class WpEventsManagerBookingReader
{
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
        $excluded = WpEventsManagerSchema::EXCLUDED_POST_STATUSES;
        $bookings = $this->db->rows(
            'SELECT p.ID, p.post_status, p.post_date FROM ' . $this->db->table('posts') . ' p JOIN ' . $this->db->table('postmeta')
            . ' me ON me.post_id = p.ID AND me.meta_key = %s WHERE p.post_type = %s AND p.post_status NOT IN ('
            . SourceDatabase::placeholders($excluded, '%s') . ') AND me.meta_value = %s ORDER BY p.ID LIMIT %d OFFSET %d',
            array_merge(['ea_booking_event_id', WpEventsManagerSchema::POST_TYPE_BOOKING], $excluded, [$source_event_id, max(1, $limit), max(0, $offset)])
        );
        if ($bookings === []) {
            return [];
        }
        $ids     = array_map('intval', array_column($bookings, 'ID'));
        $meta    = $this->db->postMeta($ids, WpEventsManagerSchema::bookingMetaKeys());
        $users   = $this->db->users(
            array_map(static fn(int $id): int => (int) ($meta[ $id ]['ea_booking_user_id'] ?? 0), $ids),
            ['first_name', 'last_name']
        );
        $records = [];
        foreach ($bookings as $booking) {
            $id        = (int) $booking['ID'];
            $records[] = $this->record($booking, $meta[ $id ] ?? [], $users);
        }
        return $records;
    }


    /**
     * @param array             $booking The post row (ID, post_status, post_date).
     * @param array             $fields  Its meta.
     * @param array<int, array> $users   WordPress users by id.
     */
    private function record(array $booking, array $fields, array $users): SourceBookingRecord
    {
        $status = strtolower((string) $booking['post_status']);
        $qty    = max(1, (int) ($fields['ea_booking_qty'] ?? 1));
        $total  = (float) ($fields['ea_booking_price'] ?? 0);
        $placed = $this->local((string) $booking['post_date']);
        return new SourceBookingRecord(
            (string) $booking['ID'],
            $status,
            $this->buyer((int) ($fields['ea_booking_user_id'] ?? 0), $users),
            [new SourceSeatRecord('event:' . (string) ($fields['ea_booking_event_id'] ?? ''), $total / $qty, $qty)],
            [
                'transaction_status' => $total <= 0.0 ? WpEventsManagerStatusTable::TXN_FREE : $status,
                'created_at'         => $placed,
                'total'              => $total,
                'currency'           => strtoupper(trim((string) ($fields['ea_booking_currency'] ?? ''))),
                'payments'           => $this->payments((string) $booking['ID'], $status, $total, $fields, $placed),
                'unmapped'           => $this->unmapped($fields),
            ]
        );
    }


    /**
     * The buyer: always a WordPress user (the user may since have been deleted, leaving a contact with no details).
     */
    private function buyer(int $user_id, array $users): SourceAttendeeRecord
    {
        $user  = $users[ $user_id ] ?? [];
        $first = trim((string) ($user['first_name'] ?? ''));
        $last  = trim((string) ($user['last_name'] ?? ''));
        $name  = trim($first . ' ' . $last) ?: trim((string) ($user['display_name'] ?? ''));
        return new SourceAttendeeRecord(
            'user:' . $user_id,
            [
                'first_name' => $first,
                'last_name'  => $last,
                'full_name'  => $name,
                'email'      => strtolower(trim((string) ($user['user_email'] ?? ''))),
                'wp_user_id' => $user_id,
            ]
        );
    }


    /**
     * The booking's payment: its total, paid when the booking is completed, pending when PayPal reported it so.
     *
     * @return SourcePaymentRecord[]
     */
    private function payments(string $id, string $status, float $total, array $fields, ?SourceDateTime $placed): array
    {
        $state = ['ea-completed' => WpEventsManagerSchema::PAID, 'ea-processing' => WpEventsManagerSchema::PENDING][ $status ] ?? '';
        if ($total <= 0.0 || $state === '') {
            return [];
        }
        return [
            new SourcePaymentRecord(
                $id,
                $total,
                $state,
                [
                    'currency' => strtoupper(trim((string) ($fields['ea_booking_currency'] ?? ''))),
                    'gateway'  => trim((string) ($fields['ea_booking_payment_id'] ?? '')),
                    'paid_at'  => $placed,
                ]
            ),
        ];
    }


    /**
     * @return UnmappedConcept[]
     */
    private function unmapped(array $fields): array
    {
        $note = trim((string) ($fields['ea_booking_note'] ?? ''));
        return $note !== '' ? [new UnmappedConcept('booking-note', __('Booking note', 'migrate-events-to-event-espresso'), [$note])] : [];
    }


    private function local(string $value): ?SourceDateTime
    {
        $value = trim($value);
        return $value !== '' && strpos($value, '0000-00-00') !== 0 ? new SourceDateTime($value, TimezoneModel::SITE_LOCAL) : null;
    }
}
// End of file WpEventsManagerBookingReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\wp_events_manager/WpEventsManagerBookingReader.php
