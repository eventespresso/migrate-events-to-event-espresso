<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventon_lite;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventonInventory
 *
 * The pre-flight counts for the chosen options (REQ-003) over the same events
 * EventonScope selects. EventON Lite has no tickets, bookings, attendees or
 * payments, so those are reported as not available in this source.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventonInventory
{
    private SourceDatabase $db;

    private EventonScope $scope;


    public function __construct(SourceDatabase $db, EventonScope $scope)
    {
        $this->db    = $db;
        $this->scope = $scope;
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function build(MigrationOptions $options, string $version): SourceInventory
    {
        $found       = $this->scope->inRange($options);
        $ids         = array_keys($found);
        $occurrences = array_sum(array_map(static fn(array $event): int => count($event['intervals']), $found));
        $counts      = [
            SourceInventory::COUNT_EVENTS      => count($this->scope->eventIds($options)),
            SourceInventory::COUNT_OCCURRENCES => $occurrences,
            SourceInventory::COUNT_VENUES      => $this->terms($ids, [EventonSchema::TAXONOMY_LOCATION]) + $this->virtualVenues($ids),
            SourceInventory::COUNT_CATEGORIES  => $this->terms($ids, EventonSchema::TYPE_TAXONOMIES),
        ];
        return new SourceInventory(
            $counts,
            [
                SourceInventory::COUNT_TICKETS,
                SourceInventory::COUNT_ATTENDEES,
                SourceInventory::COUNT_BOOKINGS,
                SourceInventory::COUNT_PAYMENTS,
            ],
            [
                __('Organizers', 'migrate-events-to-event-espresso'),
                __('Tags', 'migrate-events-to-event-espresso'),
                __('Custom data fields', 'migrate-events-to-event-espresso'),
                __('Tickets, RSVPs and bookings (EventON add-ons)', 'migrate-events-to-event-espresso'),
            ],
            $version,
            TimezoneModel::WALL_CLOCK_AS_UTC
        );
    }


    /**
     * The distinct join links of virtual events that have no location term (each is a venue, as in the reader).
     *
     * @param int[] $post_ids
     * @throws MigrationRecordException
     */
    private function virtualVenues(array $post_ids): int
    {
        $links = [];
        foreach ($this->db->postMeta($post_ids, ['_virtual', '_vir_url']) as $id => $fields) {
            $link = trim((string) ($fields['_vir_url'] ?? ''));
            if (($fields['_virtual'] ?? '') === 'yes' && $link !== '' && $this->terms([(int) $id], [EventonSchema::TAXONOMY_LOCATION]) === 0) {
                $links[ $link ] = true;
            }
        }
        return count($links);
    }


    /**
     * The number of distinct terms of the given taxonomies on the given posts.
     *
     * @param int[]    $post_ids
     * @param string[] $taxonomies
     * @throws MigrationRecordException
     */
    private function terms(array $post_ids, array $taxonomies): int
    {
        if ($post_ids === []) {
            return 0;
        }
        return $this->db->countRows(
            'SELECT COUNT(DISTINCT tt.term_id) FROM ' . $this->db->table('term_relationships') . ' tr JOIN '
            . $this->db->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy IN ('
            . SourceDatabase::placeholders($taxonomies, '%s') . ') AND tr.object_id IN (' . SourceDatabase::placeholders($post_ids) . ')',
            array_merge($taxonomies, $post_ids)
        );
    }
}
// End of file EventonInventory.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventon_lite/EventonInventory.php
