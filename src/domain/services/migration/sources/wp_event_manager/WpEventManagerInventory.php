<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\wp_event_manager;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class WpEventManagerInventory
 *
 * The pre-flight counts for the chosen options (REQ-003) over the same events
 * WpEventManagerScope selects. WP Event Manager's free plugin has no
 * attendees, bookings or payments, so those are reported as not available in
 * this source. Venues follow the reader: the first venue post, or the event's
 * own address text, or its video link; tickets are the events with a
 * free/paid option.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class WpEventManagerInventory
{
    private SourceDatabase $db;

    private WpEventManagerScope $scope;


    public function __construct(SourceDatabase $db, WpEventManagerScope $scope)
    {
        $this->db    = $db;
        $this->scope = $scope;
    }


    /**
     * @throws MigrationRecordException When a count fails.
     */
    public function build(MigrationOptions $options, string $version): SourceInventory
    {
        [$from, $args] = $this->scope->eventFrom($options);
        $ids           = array_map('intval', $this->db->column('SELECT p.ID' . $from, $args));
        $venues        = [];
        $tickets       = 0;
        $keys          = ['_event_venue_ids', '_event_location', '_event_video_url', '_event_online', '_event_ticket_options'];
        $meta          = [];
        foreach (array_chunk($ids, SourceDatabase::META_CHUNK) as $chunk) {
            $meta += $this->db->postMeta($chunk, $keys);
        }
        foreach ($meta as $fields) {
            $venue = WpEventManagerEventReader::firstId((array) ($fields['_event_venue_ids'] ?? []));
            $text  = strtolower(trim((string) ($fields['_event_location'] ?? '')));
            $link  = trim((string) ($fields['_event_video_url'] ?? ''));
            if ($venue > 0) {
                $venues[ 'v' . $venue ] = true;
            } elseif ($text !== '') {
                $venues[ 'l' . $text ] = true;
            } elseif (($fields['_event_online'] ?? '') === 'yes' && $link !== '') {
                $venues[ 'u' . $link ] = true;
            }
            $tickets += trim((string) ($fields['_event_ticket_options'] ?? '')) !== '' ? 1 : 0;
        }
        $categories = $ids === [] ? 0 : $this->db->countRows(
            'SELECT COUNT(DISTINCT tt.term_id) FROM ' . $this->db->table('term_relationships') . ' tr JOIN '
            . $this->db->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s AND tr.object_id IN ('
            . SourceDatabase::placeholders($ids) . ')',
            array_merge([WpEventManagerSchema::TAXONOMY_CATEGORY], $ids)
        );
        $counts = [
            SourceInventory::COUNT_EVENTS      => count($ids),
            SourceInventory::COUNT_OCCURRENCES => count($ids),
            SourceInventory::COUNT_VENUES      => count($venues),
            SourceInventory::COUNT_CATEGORIES  => $categories,
            SourceInventory::COUNT_TICKETS     => $tickets,
        ];
        return new SourceInventory(
            $counts,
            [SourceInventory::COUNT_ATTENDEES, SourceInventory::COUNT_BOOKINGS, SourceInventory::COUNT_PAYMENTS],
            [
                __('Organizers', 'migrate-events-to-event-espresso'),
                __('Event types and tags', 'migrate-events-to-event-espresso'),
                __('Registrations and tickets (paid add-ons)', 'migrate-events-to-event-espresso'),
            ],
            $version,
            TimezoneModel::SITE_LOCAL
        );
    }
}
// End of file WpEventManagerInventory.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\wp_event_manager/WpEventManagerInventory.php
