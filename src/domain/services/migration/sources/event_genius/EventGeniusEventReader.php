<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\event_genius;

use DateInterval;
use DateTimeImmutable;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDatetimeRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\records\SourceEventRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceTicketRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceVenueRecord;
use EventEspresso\EventMigrator\application\services\migration\records\UnmappedConcept;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceZone;

/**
 * Class EventGeniusEventReader
 *
 * Reads one Event Genius event into a SourceEventRecord (research profile §8):
 * the post's title, content, summary, author, status and categories; one
 * datetime per occurrence (the event, or every date of a recurring series),
 * stored in UTC with the event's own timezone; its first venue; and, for an
 * event that takes registrations, the implicit ticket its cost and capacity
 * describe (Phase 2 implicit-ticket contract). Further venues, organizers and
 * tags are unmapped concepts.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventGeniusEventReader
{
    private const META_KEYS = [
        '_thumbnail_id',
        'evge_start_date',
        'evge_end_date',
        'evge_start_date_utc',
        'evge_end_date_utc',
        'evge_timezone',
        'evge_all_day',
        'evge_summary',
        'evge_venue',
        'evge_venue_order',
        'evge_organizer',
        'evge_organizer_order',
        'evge_cost_amount',
        'evge_allow_registration',
        'evge_unlimited_capacity',
        'evge_capacity',
        'evge_capacity_per_registration',
        'evge_open_type',
        'evge_open_date_utc',
        'evge_relative_open_offset',
        'evge_relative_open_offset_type',
        'evge_close_type',
        'evge_close_utc',
        'evge_relative_close_offset',
        'evge_relative_close_offset_type',
        'evge_recurrence_type',
        'evge_event_type',
        'evge_virtual_join_url',
    ];

    private SourceDatabase $db;

    private EventGeniusScope $scope;


    public function __construct(SourceDatabase $db, EventGeniusScope $scope)
    {
        $this->db    = $db;
        $this->scope = $scope;
    }


    /**
     * The first venue id of an event's venue meta (its saved order first), or 0.
     *
     * @param array $fields The event's meta with the multi-row keys as value lists (SourceDatabase::postMetaAll()).
     */
    public static function firstVenueId(array $fields): int
    {
        $order = json_decode((string) ($fields['evge_venue_order'][0] ?? ''), true);
        $ids   = is_array($order) && $order !== [] ? $order : ($fields['evge_venue'] ?? []);
        return (int) ($ids[0] ?? 0);
    }


    /**
     * @throws MigrationRecordException When the event does not exist, has no readable date, or a read fails.
     */
    public function read(string $source_event_id): SourceEventRecord
    {
        $ids   = $this->scope->occurrenceIdsOf($source_event_id);
        $posts = $this->db->posts($ids);
        $meta  = $this->db->postMetaAll($ids, self::META_KEYS);
        $owner = EventGeniusScope::templateId($source_event_id);
        if (! isset($posts[ $owner ])) {
            $owner = (int) ($ids[0] ?? 0);
        }
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        if (! isset($posts[ $owner ])) {
            throw $this->failure(esc_html__('The source event no longer exists.', 'migrate-events-to-event-espresso'), $source_event_id);
        }
        $fields    = $meta[ $owner ] ?? [];
        $zone      = SourceZone::normalize((string) ($fields['evge_timezone'][0] ?? ''));
        $datetimes = $this->datetimes($ids, $meta, $zone);
        if ($datetimes === []) {
            throw $this->failure(esc_html__('The source event has no readable date.', 'migrate-events-to-event-espresso'), $source_event_id);
        }
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        $post = $posts[ $owner ];
        return new SourceEventRecord(
            $source_event_id,
            (string) $post['post_title'],
            [
                'description'       => (string) $post['post_content'],
                'excerpt'           => (string) ($fields['evge_summary'][0] ?? ''),
                'status'            => strtolower((string) $post['post_status']),
                'author_wp_user_id' => (int) $post['post_author'],
                'featured_image_id' => (int) ($fields['_thumbnail_id'][0] ?? 0),
                'categories'        => $this->terms($ids, EventGeniusSchema::TAXONOMY_CATEGORY),
                'venue'             => $this->venue($fields),
                'datetimes'         => $datetimes,
                'tickets'           => $this->tickets($source_event_id, $ids, $fields, $datetimes),
                'unmapped'          => $this->unmapped($ids, $fields),
                'series_id'         => EventGeniusScope::isSeries($source_event_id) ? (string) $owner : null,
                'timezone'          => $zone,
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
     * @return SourceDatetimeRecord[] Occurrences with a readable start
     */
    private function datetimes(array $ids, array $meta, ?string $zone): array
    {
        $records = [];
        foreach ($ids as $id) {
            $fields   = $meta[ $id ] ?? [];
            $all_day  = ($fields['evge_all_day'][0] ?? '') === 'enabled';
            $start    = $this->moment($fields, 'start', $zone, $all_day);
            if ($start === null) {
                continue;
            }
            $end      = $this->moment($fields, 'end', $zone, $all_day) ?? $start;
            $limited  = ($fields['evge_allow_registration'][0] ?? '') === 'enabled' && ($fields['evge_unlimited_capacity'][0] ?? '') !== 'enabled';
            $capacity = (int) ($fields['evge_capacity'][0] ?? 0);
            $records[] = new SourceDatetimeRecord((string) $id, $start, $end, ['capacity' => $limited && $capacity > 0 ? $capacity : null]);
        }
        return $records;
    }


    /**
     * An edge of an occurrence: the UTC value, or the local value in the event's zone for an all-day event (whose
     * local day is what matters).
     */
    private function moment(array $fields, string $edge, ?string $zone, bool $all_day): ?SourceDateTime
    {
        $utc   = trim((string) ($fields[ 'evge_' . $edge . '_date_utc' ][0] ?? ''));
        $local = trim((string) ($fields[ 'evge_' . $edge . '_date' ][0] ?? ''));
        if ($all_day && $local !== '' && $zone !== null) {
            return new SourceDateTime($local, TimezoneModel::PER_EVENT, $zone, true);
        }
        if ($utc !== '') {
            return new SourceDateTime($utc, TimezoneModel::UTC, null, $all_day);
        }
        return $local !== '' ? ($zone !== null
            ? new SourceDateTime($local, TimezoneModel::PER_EVENT, $zone, $all_day)
            : new SourceDateTime($local, TimezoneModel::SITE_LOCAL, null, $all_day)) : null;
    }


    /**
     * The implicit ticket of an event that takes registrations: its cost (empty is free), maximum guests per
     * registration and registration window. A capacity is on the dates.
     *
     * @param string                 $source_event_id The migrated event id.
     * @param int[]                  $ids             The occurrence post ids.
     * @param array                  $fields          The owner's meta.
     * @param SourceDatetimeRecord[] $datetimes       The event's dates.
     * @return SourceTicketRecord[]
     */
    private function tickets(string $source_event_id, array $ids, array $fields, array $datetimes): array
    {
        if (($fields['evge_allow_registration'][0] ?? '') !== 'enabled') {
            return [];
        }
        $cost = trim((string) ($fields['evge_cost_amount'][0] ?? ''));
        $max  = (int) ($fields['evge_capacity_per_registration'][0] ?? 0);
        return [
            new SourceTicketRecord(
                EventGeniusSchema::TICKET_PREFIX . $source_event_id,
                '',
                [
                    'implicit'            => true,
                    'price'               => is_numeric($cost) ? (float) $cost : 0.0,
                    'raw_price'           => $cost !== '' ? $cost : '0',
                    'max'                 => $max > 0 ? $max : null,
                    'sale_start'          => $this->window($fields, 'open', $datetimes[0]->start()),
                    'sale_end'            => $this->window($fields, 'close', $datetimes[0]->start()),
                    'datetime_source_ids' => array_map(static fn(SourceDatetimeRecord $datetime): string => $datetime->sourceId(), $datetimes),
                ]
            ),
        ];
    }


    /**
     * When registration opens or closes: a fixed UTC date, or the event's start less a relative offset; null for
     * "immediately" (open) and "when the event starts" (close).
     *
     * @param array          $fields The event meta.
     * @param string         $which  Either "open" or "close".
     * @param SourceDateTime $start  The event's first start.
     */
    private function window(array $fields, string $which, SourceDateTime $start): ?SourceDateTime
    {
        $type = (string) ($fields[ 'evge_' . $which . '_type' ][0] ?? '');
        if ($type === 'date') {
            $key   = $which === 'open' ? 'evge_open_date_utc' : 'evge_close_utc';
            $value = trim((string) ($fields[ $key ][0] ?? ''));
            return $value !== '' ? new SourceDateTime($value, TimezoneModel::UTC) : null;
        }
        $amount = (int) ($fields[ 'evge_relative_' . $which . '_offset' ][0] ?? 0);
        $unit   = strtolower(trim((string) ($fields[ 'evge_relative_' . $which . '_offset_type' ][0] ?? 'days')));
        $spec   = ['minutes' => 'PT%dM', 'hours' => 'PT%dH', 'days' => 'P%dD', 'weeks' => 'P%dW'][ $unit ] ?? '';
        if ($type !== 'relative' || $amount <= 0 || $spec === '' || $start->model() !== TimezoneModel::UTC) {
            return null;
        }
        $moment = (new DateTimeImmutable($start->raw()))->sub(new DateInterval(sprintf($spec, $amount)));
        return new SourceDateTime($moment->format('Y-m-d H:i:s'), TimezoneModel::UTC);
    }


    /**
     * The first venue (the saved order first), or the join link of a virtual event, as a venue.
     *
     * @throws MigrationRecordException
     */
    private function venue(array $fields): ?SourceVenueRecord
    {
        $venue_id = self::firstVenueId($fields);
        $venue    = $venue_id > 0 ? ($this->db->posts([$venue_id])[ $venue_id ] ?? null) : null;
        if ($venue !== null) {
            $meta = $this->db->postMeta([$venue_id], [
                'evge_address_1', 'evge_address_2', 'evge_city', 'evge_state', 'evge_postal_code', 'evge_country',
                'evge_phone', 'evge_website',
            ])[ $venue_id ] ?? [];
            return new SourceVenueRecord(
                (string) $venue_id,
                (string) $venue['post_title'],
                [
                    'description' => (string) $venue['post_content'],
                    'address'     => (string) ($meta['evge_address_1'] ?? ''),
                    'address2'    => (string) ($meta['evge_address_2'] ?? ''),
                    'city'        => (string) ($meta['evge_city'] ?? ''),
                    'state'       => (string) ($meta['evge_state'] ?? ''),
                    'zip'         => (string) ($meta['evge_postal_code'] ?? ''),
                    'country'     => (string) ($meta['evge_country'] ?? ''),
                    'phone'       => (string) ($meta['evge_phone'] ?? ''),
                    'url'         => (string) ($meta['evge_website'] ?? ''),
                ]
            );
        }
        $link = trim((string) ($fields['evge_virtual_join_url'][0] ?? ''));
        return $link !== '' ? new SourceVenueRecord('url:' . md5($link), $link, ['virtual_url' => $link]) : null;
    }


    /**
     * @param int[]  $ids      The occurrence post ids.
     * @param string $taxonomy A taxonomy.
     * @return string[] The term names of all the occurrences, each once
     * @throws MigrationRecordException
     */
    private function terms(array $ids, string $taxonomy): array
    {
        $names = [];
        foreach ($this->db->postTerms($ids, $taxonomy) as $terms) {
            $names = array_merge($names, $terms);
        }
        return array_values(array_unique($names));
    }


    /**
     * Tags, the venues after the first and the organizers.
     *
     * @param int[] $ids    The occurrence post ids.
     * @param array $fields The owner's meta.
     * @return UnmappedConcept[]
     * @throws MigrationRecordException
     */
    private function unmapped(array $ids, array $fields): array
    {
        $concepts = [];
        $tags     = $this->terms($ids, EventGeniusSchema::TAXONOMY_TAG);
        if ($tags !== []) {
            $concepts[] = new UnmappedConcept('tags', __('Tags', 'migrate-events-to-event-espresso'), $tags);
        }
        $first  = self::firstVenueId($fields);
        $others = array_values(array_diff(array_map('intval', $fields['evge_venue'] ?? []), [$first]));
        $titles = array_column($this->db->posts($others), 'post_title');
        if ($titles !== []) {
            $concepts[] = new UnmappedConcept('venues', __('Additional venues', 'migrate-events-to-event-espresso'), array_values($titles));
        }
        $organizers = array_column($this->db->posts(array_map('intval', $fields['evge_organizer'] ?? [])), 'post_title');
        if ($organizers !== []) {
            $concepts[] = new UnmappedConcept('organizer', __('Organizers', 'migrate-events-to-event-espresso'), array_values($organizers));
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
// End of file EventGeniusEventReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\event_genius/EventGeniusEventReader.php
