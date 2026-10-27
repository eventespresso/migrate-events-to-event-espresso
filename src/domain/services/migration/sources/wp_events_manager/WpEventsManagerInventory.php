<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\wp_events_manager;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class WpEventsManagerInventory
 *
 * The pre-flight counts for the chosen options (REQ-003) over the same events
 * WpEventsManagerScope selects. Every event has the one ticket its price
 * describes; venues are the distinct address texts; attendees are the distinct
 * WordPress users who booked; payments are the completed and pending-payment
 * bookings that cost something.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class WpEventsManagerInventory
{
    private SourceDatabase $db;

    private WpEventsManagerScope $scope;


    public function __construct(SourceDatabase $db, WpEventsManagerScope $scope)
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
        foreach ($this->db->postMeta($ids, ['tp_event_location']) as $fields) {
            $text = strtolower(trim((string) ($fields['tp_event_location'] ?? '')));
            $text !== '' && $venues[ $text ] = true;
        }
        $categories = $ids === [] ? 0 : $this->db->countRows(
            'SELECT COUNT(DISTINCT tt.term_id) FROM ' . $this->db->table('term_relationships') . ' tr JOIN '
            . $this->db->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s AND tr.object_id IN ('
            . SourceDatabase::placeholders($ids) . ')',
            array_merge([WpEventsManagerSchema::TAXONOMY_CATEGORY], $ids)
        );
        [$bookings, $booking_args] = $this->bookingSubquery($ids);
        $posts                     = $this->db->table('posts');
        $postmeta                  = $this->db->table('postmeta');
        $booking_count             = $ids === [] ? 0 : $this->db->countRows(
            'SELECT COUNT(*) FROM (' . $bookings . ') b',
            $booking_args
        );
        // A booking whose user was deleted has no user meta; it is one empty contact (user 0), as the reader does.
        $users    = $ids === [] ? 0 : $this->db->countRows(
            'SELECT COUNT(DISTINCT COALESCE(mu.meta_value + 0, 0)) FROM (' . $bookings . ') b LEFT JOIN ' . $postmeta
            . " mu ON mu.post_id = b.ID AND mu.meta_key = 'ea_booking_user_id'",
            $booking_args
        );
        $payments = $ids === [] ? 0 : $this->db->countRows(
            'SELECT COUNT(*) FROM (' . $bookings . ') b JOIN ' . $posts . ' p ON p.ID = b.ID JOIN ' . $postmeta
            . " mp ON mp.post_id = b.ID AND mp.meta_key = 'ea_booking_price'"
            . " WHERE p.post_status IN ('ea-completed', 'ea-processing') AND mp.meta_value + 0 > 0",
            $booking_args
        );
        $counts = [
            SourceInventory::COUNT_EVENTS      => count($ids),
            SourceInventory::COUNT_OCCURRENCES => count($ids),
            SourceInventory::COUNT_VENUES      => count($venues),
            SourceInventory::COUNT_CATEGORIES  => $categories,
            SourceInventory::COUNT_TICKETS     => count($ids),
            SourceInventory::COUNT_BOOKINGS    => $booking_count,
            SourceInventory::COUNT_ATTENDEES   => $users,
            SourceInventory::COUNT_PAYMENTS    => $payments,
        ];
        return new SourceInventory(
            $counts,
            [],
            [
                __('Event tags and types', 'migrate-events-to-event-espresso'),
                __('Booking notes', 'migrate-events-to-event-espresso'),
                __('Parent events', 'migrate-events-to-event-espresso'),
                __('PayPal transaction ids (the plugin does not store them)', 'migrate-events-to-event-espresso'),
            ],
            $version,
            TimezoneModel::SITE_LOCAL
        );
    }


    /**
     * The SELECT of the ids of the bookings of the given events (trash and auto-drafts excluded), for use as a
     * derived table, and its arguments.
     *
     * @param int[] $event_ids
     * @return array{0: string, 1: array}
     */
    private function bookingSubquery(array $event_ids): array
    {
        $excluded = WpEventsManagerSchema::EXCLUDED_POST_STATUSES;
        return [
            'SELECT p.ID FROM ' . $this->db->table('posts') . ' p JOIN ' . $this->db->table('postmeta')
            . ' me ON me.post_id = p.ID AND me.meta_key = %s WHERE p.post_type = %s AND p.post_status NOT IN ('
            . SourceDatabase::placeholders($excluded, '%s') . ') AND me.meta_value IN ('
            . SourceDatabase::placeholders($event_ids ?: [0], '%s') . ')',
            array_merge(
                ['ea_booking_event_id', WpEventsManagerSchema::POST_TYPE_BOOKING],
                $excluded,
                array_map('strval', $event_ids ?: [0])
            ),
        ];
    }
}
// End of file WpEventsManagerInventory.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\wp_events_manager/WpEventsManagerInventory.php
