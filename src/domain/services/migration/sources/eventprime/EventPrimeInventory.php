<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventprime;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventPrimeInventory
 *
 * The pre-flight counts for the chosen options (REQ-003) over the same
 * occurrences EventPrimeScope selects. The venue, event type, ticket and
 * payment counts follow the readers: the venue and type are term ids in the
 * event meta, a series' copied tickets count once, and a payment exists only
 * for a booking that cost something and went through a gateway.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventPrimeInventory
{
    private SourceDatabase $db;

    private EventPrimeScope $scope;


    public function __construct(SourceDatabase $db, EventPrimeScope $scope)
    {
        $this->db    = $db;
        $this->scope = $scope;
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function build(MigrationOptions $options, string $version): SourceInventory
    {
        [$from, $args] = $this->scope->occurrenceFrom($options);
        $occurrences   = 'SELECT p.ID' . $from;
        $ids           = array_map('intval', $this->db->column($occurrences, $args));
        $per_date      = $options->isEventPerOccurrence();
        $meta          = $this->db->postMeta($ids, ['em_venue', 'em_event_type']);
        $venues        = [];
        $types         = [];
        foreach ($meta as $fields) {
            ! empty($fields['em_venue']) && $venues[ (int) $fields['em_venue'] ] = true;
            ! empty($fields['em_event_type']) && $types[ (int) $fields['em_event_type'] ] = true;
        }
        $tickets  = $this->db->table(EventPrimeSchema::TICKETS);
        $bookings = $this->bookingIds($ids);
        $users    = [];
        $payments = 0;
        // The buyer's email and the order total exist only inside the order blobs, so the bookings are read in
        // chunks with just the keys these two tallies need.
        $keys = ['em_user', 'em_status', 'em_payment_method', 'em_order_info', 'em_payment_log'];
        foreach (array_chunk($bookings, SourceDatabase::META_CHUNK) as $chunk) {
            foreach ($this->db->postMeta($chunk, $keys) as $id => $fields) {
                $users[ $this->buyerKey((int) $id, $fields) ] = true;
                $payments += EventPrimeBookingReader::hasPayment($fields) ? 1 : 0;
            }
        }
        $counts = [
            SourceInventory::COUNT_EVENTS      => $this->db->countRows(
                'SELECT COUNT(DISTINCT ' . EventPrimeScope::eventIdExpression($options) . ')' . $from,
                $args
            ),
            SourceInventory::COUNT_OCCURRENCES => count($ids),
            SourceInventory::COUNT_VENUES      => count($venues),
            SourceInventory::COUNT_CATEGORIES  => count($types),
            SourceInventory::COUNT_TICKETS     => $this->db->countRows(
                'SELECT COUNT(DISTINCT ' . ($per_date ? 'id' : 'CASE WHEN parent_price_option_id > 0 THEN parent_price_option_id ELSE id END')
                . ') FROM ' . $tickets . ' WHERE event_id IN (' . $occurrences . ')',
                $args
            ),
            SourceInventory::COUNT_BOOKINGS    => count($bookings),
            SourceInventory::COUNT_ATTENDEES   => count($users),
            SourceInventory::COUNT_PAYMENTS    => $payments,
        ];
        return new SourceInventory(
            $counts,
            [],
            [
                __('Performers and organizers', 'migrate-events-to-event-espresso'),
                __('Seat maps, coupons and offers (extensions)', 'migrate-events-to-event-espresso'),
                __('Ticket fees and shared-capacity categories', 'migrate-events-to-event-espresso'),
                __('Extra event dates', 'migrate-events-to-event-espresso'),
            ],
            $version,
            TimezoneModel::UTC
        );
    }


    /**
     * Who the booking is for, as EventPrimeBookingReader identifies the buyer: the user, or the guest's email.
     */
    private function buyerKey(int $id, array $fields): string
    {
        $user = (int) ($fields['em_user'] ?? 0);
        $mail = strtolower(trim((string) (EventPrimeBookingReader::data((array) ($fields['em_order_info'] ?? []))['user_email'] ?? '')));
        return $user > 0 ? 'u' . $user : ($mail !== '' ? 'g' . $mail : 'b' . $id);
    }


    /**
     * The ids of the bookings of the given events (trash and auto-drafts excluded).
     *
     * @param int[] $event_ids
     * @return int[]
     * @throws MigrationRecordException
     */
    private function bookingIds(array $event_ids): array
    {
        if ($event_ids === []) {
            return [];
        }
        $excluded = EventPrimeSchema::EXCLUDED_POST_STATUSES;
        return array_map(
            'intval',
            $this->db->column(
                'SELECT p.ID FROM ' . $this->db->table('posts') . ' p JOIN ' . $this->db->table('postmeta')
                . ' me ON me.post_id = p.ID AND me.meta_key = %s WHERE p.post_type = %s AND p.post_status NOT IN ('
                . SourceDatabase::placeholders($excluded, '%s') . ') AND me.meta_value IN ('
                . SourceDatabase::placeholders($event_ids, '%s') . ') ORDER BY p.ID',
                array_merge(['em_event', EventPrimeSchema::POST_TYPE_BOOKING], $excluded, array_map('strval', $event_ids))
            )
        );
    }
}
// End of file EventPrimeInventory.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventprime/EventPrimeInventory.php
