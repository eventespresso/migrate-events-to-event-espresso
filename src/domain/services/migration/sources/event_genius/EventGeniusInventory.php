<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\event_genius;

use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventGeniusInventory
 *
 * The pre-flight counts for the chosen options (REQ-003) over the same
 * occurrences EventGeniusScope selects. Venues (a first-venue choice) and
 * tickets (one per event that takes registrations) are counted from the
 * decoded meta of the events in scope; the rest are COUNT queries.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventGeniusInventory
{
    private SourceDatabase $db;

    private EventGeniusScope $scope;


    public function __construct(SourceDatabase $db, EventGeniusScope $scope)
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
        $rows          = $this->db->rows(
            'SELECT p.ID AS id, ' . EventGeniusScope::eventIdExpression($options) . ' AS migrated_id' . $from . ' ORDER BY p.ID',
            $args
        );
        $meta   = $this->db->postMetaAll(array_map('intval', array_column($rows, 'id')), ['evge_venue', 'evge_venue_order', 'evge_allow_registration', 'evge_virtual_join_url']);
        $groups = [];
        $venues = [];
        foreach ($rows as $row) {
            $fields = $meta[ (int) $row['id'] ] ?? [];
            if (! isset($groups[ $row['migrated_id'] ])) {
                $groups[ $row['migrated_id'] ] = ($fields['evge_allow_registration'][0] ?? '') === 'enabled' ? 1 : 0;
                $venue                         = EventGeniusEventReader::firstVenueId($fields);
                $link                          = trim((string) ($fields['evge_virtual_join_url'][0] ?? ''));
                if ($venue > 0 || $link !== '') {
                    $venues[ $venue > 0 ? 'v' . $venue : 'u' . $link ] = true;
                }
            }
        }
        $registrations = $this->db->table(EventGeniusSchema::REGISTRATIONS);
        $main          = ' FROM ' . $registrations . ' r WHERE r.parent = 0 AND r.event_id IN (' . $occurrences . ')';
        $counts        = [
            SourceInventory::COUNT_EVENTS      => count($groups),
            SourceInventory::COUNT_OCCURRENCES => count($rows),
            SourceInventory::COUNT_VENUES      => count($venues),
            SourceInventory::COUNT_CATEGORIES  => $this->db->countRows(
                'SELECT COUNT(DISTINCT tt.term_id) FROM ' . $this->db->table('term_relationships') . ' tr JOIN '
                . $this->db->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
                . ' WHERE tt.taxonomy = %s AND tr.object_id IN (' . $occurrences . ')',
                array_merge([EventGeniusSchema::TAXONOMY_CATEGORY], $args)
            ),
            SourceInventory::COUNT_TICKETS     => array_sum($groups),
            SourceInventory::COUNT_BOOKINGS    => $this->db->countRows('SELECT COUNT(*)' . $main, $args),
            SourceInventory::COUNT_ATTENDEES   => $this->db->countRows(
                "SELECT COUNT(DISTINCT CASE WHEN r.user_id > 0 THEN CONCAT('u', r.user_id)"
                . " ELSE CONCAT('g', LOWER(COALESCE(NULLIF(m.meta_value, ''), r.id))) END)"
                . ' FROM ' . $registrations . ' r LEFT JOIN ' . $this->db->table(EventGeniusSchema::REGISTRATIONS_META)
                . " m ON m.registration_id = r.id AND m.meta_key = 'email'"
                . ' WHERE r.parent = 0 AND r.event_id IN (' . $occurrences . ')',
                $args
            ),
            SourceInventory::COUNT_PAYMENTS    => $this->db->countRows(
                'SELECT COUNT(*) FROM ' . $this->db->table(EventGeniusSchema::PAYMENTS) . ' pay WHERE pay.registration_id IN (SELECT r.id'
                . $main . ')',
                $args
            ),
        ];
        return new SourceInventory(
            $counts,
            [],
            [
                __('Registration types and ticket tables (paid builds)', 'migrate-events-to-event-espresso'),
                __('Additional venues and organizers', 'migrate-events-to-event-espresso'),
                __('Series passes', 'migrate-events-to-event-espresso'),
                __('Waiting list and attendance (paid builds)', 'migrate-events-to-event-espresso'),
            ],
            $version,
            TimezoneModel::PER_EVENT
        );
    }
}
// End of file EventGeniusInventory.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\event_genius/EventGeniusInventory.php
