<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventon_lite;

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
 * Class EventonEventReader
 *
 * Reads one EventON event into a SourceEventRecord (research profile §8): the
 * post's title, content, subtitle, author and status (a cancelled or
 * postponed `_status` replaces the post status); one datetime per date, read
 * as wall-clock times in the event's zone (EventON stores the digits of a local
 * time as if they were UTC); the first location term, whose address is in the
 * `evo_tax_meta` option; the event types as categories; and the concepts Event
 * Espresso has no counterpart for. EventON Lite has no tickets, so the engine
 * adds a free one.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventonEventReader
{
    private SourceDatabase $db;

    private EventonSettings $settings;

    private EventonScope $scope;


    public function __construct(SourceDatabase $db, EventonSettings $settings, EventonScope $scope)
    {
        $this->db       = $db;
        $this->settings = $settings;
        $this->scope    = $scope;
    }


    /**
     * @param string $source_event_id The post id, or "<post id>:<index>" for one date of a repeating event.
     * @throws MigrationRecordException When the event does not exist, has no date, or a read fails.
     */
    public function read(string $source_event_id): SourceEventRecord
    {
        [$post_id, $index] = array_pad(explode(':', $source_event_id, 2), 2, null);
        $post_id           = (int) $post_id;
        $posts             = $this->db->posts([$post_id]);
        $fields            = $this->db->postMeta([$post_id], EventonSchema::eventMetaKeys())[ $post_id ] ?? [];
        $intervals         = EventonScope::intervals($fields);
        if ($index !== null) {
            $intervals = isset($intervals[ (int) $index ]) ? [(int) $index => $intervals[ (int) $index ]] : [];
        }
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        if (! isset($posts[ $post_id ]) || $posts[ $post_id ]['post_type'] !== EventonSchema::POST_TYPE_EVENT) {
            throw $this->failure(esc_html__('The source event no longer exists.', 'migrate-events-to-event-espresso'), $source_event_id);
        }
        if ($intervals === []) {
            throw $this->failure(esc_html__('The source event has no readable date.', 'migrate-events-to-event-espresso'), $source_event_id);
        }
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        $post      = $posts[ $post_id ];
        $zone      = $this->scope->zone($fields);
        $repeating = count(EventonScope::intervals($fields)) > 1;
        return new SourceEventRecord(
            $source_event_id,
            (string) $post['post_title'],
            [
                'description'       => (string) $post['post_content'],
                'excerpt'           => trim(wp_strip_all_tags((string) ($fields['evcal_subtitle'] ?? ''))),
                'status'            => $this->status($post, $fields),
                'author_wp_user_id' => (int) $post['post_author'],
                'featured_image_id' => (int) ($fields['_thumbnail_id'] ?? 0),
                'categories'        => $this->categories($post_id),
                'venue'             => $this->venue($post_id, $fields),
                'datetimes'         => $this->datetimes($post_id, $intervals, $fields, $repeating, $zone),
                'unmapped'          => $this->unmapped($post_id, $fields),
                'series_id'         => $repeating ? (string) $post_id : null,
                'timezone'          => $zone,
                'created_at'        => (string) $post['post_date'] !== ''
                    ? new SourceDateTime((string) $post['post_date'], TimezoneModel::SITE_LOCAL)
                    : null,
                'slug'              => (string) $post['post_name'],
            ]
        );
    }


    /**
     * @param int     $post_id   The event post id.
     * @param int[][] $intervals Index => [start, end] wall-clock integers.
     * @param array   $fields    The event's meta.
     * @param bool    $repeating Whether the event has more than one date.
     * @param ?string $zone      The event's zone.
     * @return SourceDatetimeRecord[]
     */
    private function datetimes(int $post_id, array $intervals, array $fields, bool $repeating, ?string $zone): array
    {
        $all_day = ($fields['evcal_allday'] ?? '') === 'yes' || ($fields['_time_ext_type'] ?? '') === 'dl';
        $records = [];
        foreach ($intervals as $index => [$start, $end]) {
            $records[] = new SourceDatetimeRecord(
                $repeating ? $post_id . ':' . $index : (string) $post_id,
                new SourceDateTime((string) $start, TimezoneModel::WALL_CLOCK_AS_UTC, $zone, $all_day),
                new SourceDateTime((string) $end, TimezoneModel::WALL_CLOCK_AS_UTC, $zone, $all_day)
            );
        }
        return $records;
    }


    /**
     * The raw status: "cancelled" or "postponed" when EventON says so (the legacy `_cancel` flag also means
     * cancelled), otherwise the post's.
     */
    private function status(array $post, array $fields): string
    {
        $event_status = strtolower(trim((string) ($fields['_status'] ?? '')));
        if ($event_status === EventonSchema::STATUS_CANCELLED || ($fields['_cancel'] ?? '') === 'yes') {
            return EventonSchema::STATUS_CANCELLED;
        }
        return $event_status === EventonSchema::STATUS_POSTPONED ? $event_status : strtolower((string) $post['post_status']);
    }


    /**
     * @return string[] The names of the event's types (all five type taxonomies), each once
     * @throws MigrationRecordException
     */
    private function categories(int $post_id): array
    {
        $names = [];
        foreach (EventonSchema::TYPE_TAXONOMIES as $taxonomy) {
            $names = array_merge($names, array_column($this->terms($post_id, $taxonomy), 'name'));
        }
        return array_values(array_unique(array_filter(array_map('trim', $names))));
    }


    /**
     * The first location term with its address, or the virtual event's link as a venue; null for neither.
     *
     * @throws MigrationRecordException
     */
    private function venue(int $post_id, array $fields): ?SourceVenueRecord
    {
        $location = $this->terms($post_id, EventonSchema::TAXONOMY_LOCATION)[0] ?? null;
        if ($location !== null) {
            $meta = $this->settings->termMeta(EventonSchema::TAXONOMY_LOCATION, (int) $location['term_id']);
            $lat  = $meta['location_lat'] ?? '';
            $lon  = $meta['location_lon'] ?? '';
            return new SourceVenueRecord(
                (string) $location['term_id'],
                (string) $location['name'],
                [
                    'description' => (string) $location['description'],
                    'address'     => $meta['location_address'] ?? '',
                    'city'        => $meta['location_city'] ?? '',
                    'state'       => $meta['location_state'] ?? '',
                    'country'     => $meta['location_country'] ?? '',
                    'phone'       => $meta['loc_phone'] ?? '',
                    'latitude'    => is_numeric($lat) && (float) $lat !== 0.0 ? (float) $lat : null,
                    'longitude'   => is_numeric($lon) && (float) $lon !== 0.0 ? (float) $lon : null,
                ]
            );
        }
        $link = trim((string) ($fields['_vir_url'] ?? ''));
        return ($fields['_virtual'] ?? '') === 'yes' && $link !== ''
            ? new SourceVenueRecord('url:' . md5($link), $link, ['virtual_url' => $link])
            : null;
    }


    /**
     * @return UnmappedConcept[]
     * @throws MigrationRecordException
     */
    private function unmapped(int $post_id, array $fields): array
    {
        $concepts   = [];
        $organizer  = $this->terms($post_id, EventonSchema::TAXONOMY_ORGANIZER)[0] ?? null;
        if ($organizer !== null) {
            $meta   = $this->settings->termMeta(EventonSchema::TAXONOMY_ORGANIZER, (int) $organizer['term_id']);
            $values = array_values(array_filter([
                (string) $organizer['name'],
                $meta['evcal_org_contact_e'] ?? '',
                $meta['evcal_org_contact_phone'] ?? '',
                $meta['evcal_org_exlink'] ?? '',
            ]));
            $concepts[] = new UnmappedConcept('organizer', __('Organizer', 'migrate-events-to-event-espresso'), $values);
        }
        $tags = array_column($this->terms($post_id, EventonSchema::TAXONOMY_TAG), 'name');
        if ($tags !== []) {
            $concepts[] = new UnmappedConcept('tags', __('Tags', 'migrate-events-to-event-espresso'), array_values($tags));
        }
        $event_status = strtolower(trim((string) ($fields['_status'] ?? '')));
        if (! in_array($event_status, ['', 'scheduled', EventonSchema::STATUS_CANCELLED, EventonSchema::STATUS_POSTPONED], true)) {
            $concepts[] = new UnmappedConcept('event-status', __('Event status', 'migrate-events-to-event-espresso'), [$event_status]);
        }
        $link = trim((string) ($fields['_vir_url'] ?? ''));
        if (($fields['_virtual'] ?? '') === 'yes' && $link !== '' && $this->terms($post_id, EventonSchema::TAXONOMY_LOCATION) !== []) {
            $concepts[] = new UnmappedConcept('online-meeting', __('Online meeting link', 'migrate-events-to-event-espresso'), [$link]);
        }
        $links = array_values(array_filter([trim((string) ($fields['evcal_lmlink'] ?? '')), trim((string) ($fields['evcal_exlink'] ?? ''))]));
        if ($links !== []) {
            $concepts[] = new UnmappedConcept('external-link', __('Links', 'migrate-events-to-event-espresso'), $links);
        }
        $custom = [];
        for ($index = 1; $index <= 10; $index++) {
            $value = trim((string) ($fields[ 'evcal_ec_f' . $index . 'a1' ] ?? ''));
            if ($value !== '') {
                $custom[] = ($this->settings->customFieldLabel($index) ?: 'Field ' . $index) . ': ' . $value;
            }
        }
        if ($custom !== []) {
            $concepts[] = new UnmappedConcept('custom-fields', __('Custom data fields', 'migrate-events-to-event-espresso'), $custom);
        }
        return $concepts;
    }


    /**
     * The terms of a taxonomy on a post: term_id, name and description, in term order.
     *
     * @return array[]
     * @throws MigrationRecordException
     */
    private function terms(int $post_id, string $taxonomy): array
    {
        return $this->db->rows(
            'SELECT t.term_id, t.name, tt.description FROM ' . $this->db->table('term_relationships') . ' tr JOIN '
            . $this->db->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN '
            . $this->db->table('terms') . ' t ON t.term_id = tt.term_id WHERE tr.object_id = %d AND tt.taxonomy = %s'
            . ' ORDER BY tr.term_order, t.term_id',
            [$post_id, $taxonomy]
        );
    }


    private function failure(string $message, string $source_event_id): MigrationRecordException
    {
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        return new MigrationRecordException($message, WarningRule::RECORD_FAILED, CrosswalkKeys::TYPE_EVENT, $source_event_id);
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }
}
// End of file EventonEventReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventon_lite/EventonEventReader.php
