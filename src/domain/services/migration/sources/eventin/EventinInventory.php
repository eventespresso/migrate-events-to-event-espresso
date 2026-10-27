<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventin;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventinInventory
 *
 * The pre-flight counts for the chosen options (REQ-003) over the same
 * occurrences EventinScope selects. Venues and tickets live inside serialized
 * meta, so those two are counted from the decoded values of the events in
 * scope; everything else is a COUNT query.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventinInventory
{
    private SourceDatabase $db;

    private EventinScope $scope;


    public function __construct(SourceDatabase $db, EventinScope $scope)
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
        $orders        = $this->orderFrom($occurrences);
        $order_args    = array_merge(
            [EventinSchema::POST_TYPE_ORDER],
            EventinSchema::EXCLUDED_POST_STATUSES,
            $args
        );
        $counts        = [
            SourceInventory::COUNT_EVENTS      => $this->db->countRows(
                'SELECT COUNT(DISTINCT ' . EventinScope::eventIdExpression($options) . ')' . $from,
                $args
            ),
            SourceInventory::COUNT_OCCURRENCES => count($ids),
            SourceInventory::COUNT_CATEGORIES  => $this->db->countRows(
                'SELECT COUNT(DISTINCT tt.term_id) FROM ' . $this->db->table('term_relationships') . ' tr JOIN '
                . $this->db->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
                . ' WHERE tt.taxonomy = %s AND tr.object_id IN (' . $occurrences . ')',
                array_merge([EventinSchema::TAXONOMY_CATEGORY], $args)
            ),
            SourceInventory::COUNT_BOOKINGS    => $this->db->countRows('SELECT COUNT(*)' . $orders, $order_args),
            SourceInventory::COUNT_ATTENDEES   => $this->db->countRows(
                "SELECT COUNT(DISTINCT CASE WHEN COALESCE(NULLIF(mc.meta_value, ''), NULLIF(mu.meta_value, ''), '0') <> '0'"
                . " THEN CONCAT('u', COALESCE(NULLIF(mc.meta_value, ''), mu.meta_value))"
                . " ELSE CONCAT('g', LOWER(COALESCE(NULLIF(mm.meta_value, ''), o.ID))) END)"
                . $this->orderFrom(
                    $occurrences,
                    $this->meta('mc', 'customer_id') . $this->meta('mu', 'user_id') . $this->meta('mm', 'customer_email')
                ),
                $order_args
            ),
            SourceInventory::COUNT_PAYMENTS    => $this->db->countRows(
                'SELECT COUNT(*)' . $this->orderFrom(
                    $occurrences,
                    ' JOIN ' . $this->db->table('postmeta') . " mt ON mt.post_id = o.ID AND mt.meta_key = 'total_price'"
                    . $this->meta('ms', 'status'),
                    " AND mt.meta_value + 0 > 0 AND COALESCE(ms.meta_value, '') NOT IN ('waiting', '')"
                ),
                $order_args
            ),
        ];
        $meta   = $this->db->postMeta($ids, ['etn_event_location', 'location', 'meeting_link', 'etn_ticket_variations']);
        $venues = [];
        $slugs  = [];
        foreach ($ids as $id) {
            $location = $meta[ $id ]['etn_event_location'] ?? $meta[ $id ]['location'] ?? [];
            $address  = strtolower(trim((string) (is_array($location) ? ($location['address'] ?? '') : '')));
            $link     = strtolower(trim((string) ($meta[ $id ]['meeting_link'] ?? '')));
            if ($address !== '' || $link !== '') {
                $venues[ $address !== '' ? $address : $link ] = true;
            }
            foreach (is_array($meta[ $id ]['etn_ticket_variations'] ?? null) ? $meta[ $id ]['etn_ticket_variations'] : [] as $ticket) {
                if (is_array($ticket) && trim((string) ($ticket['etn_ticket_slug'] ?? '')) !== '') {
                    $slugs[ $this->ticketOwner($id, $options) . ':' . $ticket['etn_ticket_slug'] ] = true;
                }
            }
        }
        $counts[ SourceInventory::COUNT_VENUES ]  = count($venues);
        $counts[ SourceInventory::COUNT_TICKETS ] = count($slugs);
        return new SourceInventory(
            $counts,
            [],
            [
                __('Coupons and refund gateways', 'migrate-events-to-event-espresso'),
                __('Speakers, organizers and schedules', 'migrate-events-to-event-espresso'),
                __('Seat maps, RSVP and certificates', 'migrate-events-to-event-espresso'),
                __('Check-in status', 'migrate-events-to-event-espresso'),
            ],
            $version,
            TimezoneModel::PER_EVENT
        );
    }


    /**
     * The post id that prefixes an occurrence's ticket ids: its series parent in single-event mode.
     */
    private function ticketOwner(int $id, MigrationOptions $options): int
    {
        if ($options->isEventPerOccurrence()) {
            return $id;
        }
        $parent = (int) $this->db->scalar('SELECT post_parent FROM ' . $this->db->table('posts') . ' WHERE ID = %d', [$id]);
        return $parent > 0 ? $parent : $id;
    }


    /**
     * FROM and WHERE for the orders of the occurrences in scope (alias o); the arguments are the order post type,
     * the excluded statuses, then those of the occurrence query.
     *
     * @param string $occurrences The occurrence query (selects the post ids).
     * @param string $joins       Extra JOIN clauses on o.
     * @param string $where       Extra conditions, each starting with AND.
     */
    private function orderFrom(string $occurrences, string $joins = '', string $where = ''): string
    {
        return ' FROM ' . $this->db->table('posts') . ' o JOIN ' . $this->db->table('postmeta') . " me ON me.post_id = o.ID AND me.meta_key = 'event_id'"
            . $joins . ' WHERE o.post_type = %s AND o.post_status NOT IN (' . SourceDatabase::placeholders(EventinSchema::EXCLUDED_POST_STATUSES, '%s') . ')'
            . ' AND me.meta_value IN (SELECT CAST(x.ID AS CHAR) FROM (' . $occurrences . ') x)' . $where;
    }


    private function meta(string $alias, string $key): string
    {
        return ' LEFT JOIN ' . $this->db->table('postmeta') . ' ' . $alias . ' ON ' . $alias . ".post_id = o.ID AND " . $alias . ".meta_key = '" . $key . "'";
    }
}
// End of file EventinInventory.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventin/EventinInventory.php
