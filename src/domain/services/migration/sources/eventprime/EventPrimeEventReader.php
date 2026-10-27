<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventprime;

use EventEspresso\EventMigrator\application\services\migration\records\SourceDatetimeRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\records\SourceEventRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceVenueRecord;
use EventEspresso\EventMigrator\application\services\migration\records\UnmappedConcept;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventPrimeEventReader
 *
 * Reads one EventPrime event into a SourceEventRecord (research profile §8):
 * the post's title, content, author and status; one datetime per occurrence
 * (the event, or every date of a recurring series), stored as Unix
 * timestamps; the event type as its category; the venue, which is a term
 * whose address is term meta; the tickets (EventPrimeTicketReader); and the
 * concepts Event Espresso has no counterpart for (organizers, performers,
 * extra dates, the external booking link, the flat event fee). The event's
 * venue, type and organizers are read from its meta, which holds the term
 * ids, not from the term relationships.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventPrimeEventReader
{
    private const VENUE_META = ['em_address', 'em_lat', 'em_lng', 'em_locality', 'em_state', 'em_country', 'em_postal_code'];

    private SourceDatabase $db;

    private EventPrimeScope $scope;

    private EventPrimeTicketReader $tickets;


    public function __construct(SourceDatabase $db, EventPrimeScope $scope, EventPrimeTicketReader $tickets)
    {
        $this->db      = $db;
        $this->scope   = $scope;
        $this->tickets = $tickets;
    }


    /**
     * @throws MigrationRecordException When the event does not exist, has no readable date, or a read fails.
     */
    public function read(string $source_event_id): SourceEventRecord
    {
        $ids   = $this->scope->occurrenceIdsOf($source_event_id);
        $posts = $this->db->posts($ids);
        $meta  = $this->db->postMeta($ids, EventPrimeSchema::eventMetaKeys());
        $owner = EventPrimeScope::parentId($source_event_id);
        $owner = isset($posts[ $owner ]) ? $owner : (int) ($ids[0] ?? 0);
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        if (! isset($posts[ $owner ])) {
            throw $this->failure(esc_html__('The source event no longer exists.', 'migrate-events-to-event-espresso'), $source_event_id);
        }
        $datetimes = $this->datetimes($ids, $meta);
        if ($datetimes === []) {
            throw $this->failure(esc_html__('The source event has no readable date.', 'migrate-events-to-event-espresso'), $source_event_id);
        }
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        $fields  = $meta[ $owner ] ?? [];
        $times   = [];
        foreach ($ids as $id) {
            $times[ $id ] = ['start' => (int) ($meta[ $id ]['em_start_date_time'] ?? 0), 'end' => (int) ($meta[ $id ]['em_end_date_time'] ?? 0)];
        }
        $tickets = $this->tickets->read($ids, EventPrimeScope::isSeries($source_event_id), $times);
        $post    = $posts[ $owner ];
        return new SourceEventRecord(
            $source_event_id,
            (string) $post['post_title'],
            [
                'description'       => (string) $post['post_content'],
                'status'            => strtolower((string) $post['post_status']),
                'author_wp_user_id' => (int) $post['post_author'],
                'featured_image_id' => (int) ($fields['_thumbnail_id'] ?? 0),
                'categories'        => $this->termNames([(int) ($fields['em_event_type'] ?? 0)]),
                'venue'             => $this->venue((int) ($fields['em_venue'] ?? 0)),
                'datetimes'         => $datetimes,
                'tickets'           => $tickets['records'],
                'unmapped'          => array_merge($tickets['unmapped'], $this->unmapped($fields)),
                'series_id'         => EventPrimeScope::isSeries($source_event_id) ? (string) $owner : null,
                'created_at'        => (string) $post['post_date'] !== ''
                    ? new SourceDateTime((string) $post['post_date'], TimezoneModel::SITE_LOCAL)
                    : null,
                'slug'              => (string) $post['post_name'],
            ]
        );
    }


    /**
     * @param int[]   $ids  The occurrence post ids.
     * @param array[] $meta Their meta.
     * @return SourceDatetimeRecord[] Occurrences with a start, earliest first
     */
    private function datetimes(array $ids, array $meta): array
    {
        $records = [];
        foreach ($ids as $id) {
            $fields = $meta[ $id ] ?? [];
            $start  = (int) ($fields['em_start_date_time'] ?? 0);
            if ($start <= 0) {
                continue;
            }
            $end       = (int) ($fields['em_end_date_time'] ?? 0) ?: $start;
            $all_day   = (string) ($fields['em_all_day'] ?? '0') === '1';
            $records[] = new SourceDatetimeRecord((string) $id, $this->moment($start, $all_day), $this->moment($end, $all_day));
        }
        usort($records, static fn($a, $b): int => strcmp($a->start()->raw(), $b->start()->raw()));
        return $records;
    }


    /**
     * A Unix timestamp as a source value: the exact instant, or for an all-day event the site-local day it is on.
     */
    private function moment(int $timestamp, bool $all_day): SourceDateTime
    {
        return $all_day
            ? new SourceDateTime(wp_date('Y-m-d H:i:s', $timestamp), TimezoneModel::SITE_LOCAL, null, true)
            : new SourceDateTime(gmdate('Y-m-d H:i:s', $timestamp), TimezoneModel::UTC);
    }


    /**
     * The venue term, with its address in term meta; null when the event has none.
     *
     * @throws MigrationRecordException
     */
    private function venue(int $term_id): ?SourceVenueRecord
    {
        $names = $this->termNames([$term_id]);
        if ($names === []) {
            return null;
        }
        $meta = $this->termMeta($term_id, self::VENUE_META);
        $lat  = $meta['em_lat'] ?? '';
        $lng  = $meta['em_lng'] ?? '';
        return new SourceVenueRecord(
            (string) $term_id,
            $names[0],
            [
                'address'   => (string) ($meta['em_address'] ?? ''),
                'city'      => (string) ($meta['em_locality'] ?? ''),
                'state'     => (string) ($meta['em_state'] ?? ''),
                'country'   => (string) ($meta['em_country'] ?? ''),
                'zip'       => (string) ($meta['em_postal_code'] ?? ''),
                'latitude'  => is_numeric($lat) && (float) $lat !== 0.0 ? (float) $lat : null,
                'longitude' => is_numeric($lng) && (float) $lng !== 0.0 ? (float) $lng : null,
            ]
        );
    }


    /**
     * @param int[] $term_ids
     * @return string[] The names of the terms that exist
     * @throws MigrationRecordException
     */
    private function termNames(array $term_ids): array
    {
        $term_ids = array_values(array_filter(array_map('intval', $term_ids)));
        if ($term_ids === []) {
            return [];
        }
        return array_values(array_filter(array_map('trim', $this->db->column(
            'SELECT name FROM ' . $this->db->table('terms') . ' WHERE term_id IN (' . SourceDatabase::placeholders($term_ids) . ') ORDER BY term_id',
            $term_ids
        ))));
    }


    /**
     * @param int      $term_id The term.
     * @param string[] $keys    The meta keys to read.
     * @return array<string, string> key => value of one term's meta
     * @throws MigrationRecordException
     */
    private function termMeta(int $term_id, array $keys): array
    {
        $meta = [];
        $rows = $this->db->rows(
            'SELECT meta_key, meta_value FROM ' . $this->db->table('termmeta') . ' WHERE term_id = %d AND meta_key IN ('
            . SourceDatabase::placeholders($keys, '%s') . ') ORDER BY meta_id',
            array_merge([$term_id], $keys)
        );
        foreach ($rows as $row) {
            $meta[ (string) $row['meta_key'] ] ??= (string) $row['meta_value'];
        }
        return $meta;
    }


    /**
     * @param array $fields The owner's meta.
     * @return UnmappedConcept[]
     * @throws MigrationRecordException
     */
    private function unmapped(array $fields): array
    {
        $concepts   = [];
        $organizers = $this->termNames((array) ($fields['em_organizer'] ?? []));
        if ($organizers !== []) {
            $concepts[] = new UnmappedConcept('organizer', __('Organizers', 'migrate-events-to-event-espresso'), $organizers);
        }
        $performers = array_column($this->db->posts(array_map('intval', (array) ($fields['em_performer'] ?? []))), 'post_title');
        if ($performers !== []) {
            $concepts[] = new UnmappedConcept('performers', __('Performers', 'migrate-events-to-event-espresso'), array_values($performers));
        }
        $more = array_filter((array) ($fields['em_event_add_more_dates'] ?? []), 'is_array');
        if ($more !== []) {
            $concepts[] = new UnmappedConcept(
                'more-dates',
                __('Extra dates', 'migrate-events-to-event-espresso'),
                array_values(array_map(static fn(array $row): string => trim(($row['label'] ?? '') . ' ' . ($row['date'] ?? '')), $more))
            );
        }
        $link = trim((string) ($fields['em_custom_link'] ?? ''));
        if ($link !== '') {
            $concepts[] = new UnmappedConcept('external-link', __('External booking link', 'migrate-events-to-event-espresso'), [$link]);
        }
        if ((float) ($fields['em_fixed_event_price'] ?? 0) > 0.0) {
            $concepts[] = new UnmappedConcept('fixed-event-price', __('Fee added to every booking', 'migrate-events-to-event-espresso'), [(string) $fields['em_fixed_event_price']]);
        }
        return $concepts;
    }


    private function failure(string $message, string $source_event_id): MigrationRecordException
    {
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        return new MigrationRecordException($message, WarningRule::RECORD_FAILED, CrosswalkKeys::TYPE_EVENT, $source_event_id);
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }
}
// End of file EventPrimeEventReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventprime/EventPrimeEventReader.php
