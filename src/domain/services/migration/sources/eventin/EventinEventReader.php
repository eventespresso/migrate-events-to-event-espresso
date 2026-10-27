<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventin;

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
 * Class EventinEventReader
 *
 * Reads one Eventin event into a SourceEventRecord (research profile §8): the
 * post's title, content, excerpt, author, status and categories; one datetime
 * per occurrence (the event, or every occurrence of a recurring series), read
 * in the event's own timezone; the address embedded in the event as a venue;
 * the ticket variations (EventinTicketReader); and the concepts Event
 * Espresso has no counterpart for (tags, organizers, speakers, schedule,
 * online-meeting link).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventinEventReader
{
    private SourceDatabase $db;

    private EventinScope $scope;

    private EventinTicketReader $tickets;


    public function __construct(SourceDatabase $db, EventinScope $scope, EventinTicketReader $tickets)
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
        $meta  = $this->db->postMeta($ids, EventinSchema::eventMetaKeys());
        $owner = EventinScope::parentId($source_event_id);
        if (! isset($posts[ $owner ])) {
            $owner = (int) ($ids[0] ?? 0);
        }
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        if (! isset($posts[ $owner ])) {
            throw $this->failure(esc_html__('The source event no longer exists.', 'migrate-events-to-event-espresso'), $source_event_id);
        }
        $fields    = $meta[ $owner ] ?? [];
        $zone      = EventinDates::zone((string) ($fields['event_timezone'] ?? ''));
        $datetimes = $this->datetimes($ids, $meta, $zone);
        if ($datetimes === []) {
            throw $this->failure(esc_html__('The source event has no readable date.', 'migrate-events-to-event-espresso'), $source_event_id);
        }
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        $occurrences = array_map(static fn(int $id): array => ['id' => $id, 'meta' => $meta[ $id ] ?? []], $ids);
        $tickets     = $this->tickets->read($owner, $occurrences, $zone);
        $post        = $posts[ $owner ];
        return new SourceEventRecord(
            $source_event_id,
            (string) $post['post_title'],
            [
                'description'       => (string) $post['post_content'],
                'excerpt'           => (string) $post['post_excerpt'],
                'status'            => strtolower((string) $post['post_status']),
                'author_wp_user_id' => (int) $post['post_author'],
                'featured_image_id' => (int) ($fields['_thumbnail_id'] ?? 0),
                'categories'        => $this->terms($ids, EventinSchema::TAXONOMY_CATEGORY),
                'venue'             => $this->venue($fields),
                'datetimes'         => $datetimes,
                'tickets'           => $tickets['records'],
                'unmapped'          => array_merge($tickets['unmapped'], $this->unmapped($ids, $fields)),
                'series_id'         => EventinScope::isSeries($source_event_id) ? (string) $owner : null,
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
     * @return SourceDatetimeRecord[] Occurrences with a readable start, earliest first
     */
    private function datetimes(array $ids, array $meta, ?string $zone): array
    {
        $records = [];
        foreach ($ids as $id) {
            $fields = $meta[ $id ] ?? [];
            $start  = EventinDates::local((string) ($fields['etn_start_date'] ?? ''), (string) ($fields['etn_start_time'] ?? ''), $zone);
            if ($start === null) {
                continue;
            }
            $end_date = trim((string) ($fields['etn_end_date'] ?? '')) !== '' ? $fields['etn_end_date'] : $fields['etn_start_date'];
            $end_time = trim((string) ($fields['etn_end_time'] ?? '')) !== '' ? $fields['etn_end_time'] : ($fields['etn_start_time'] ?? '');
            $capacity = $this->truthy((string) ($fields['etn_enable_global_stock'] ?? '')) ? (int) ($fields['etn_global_stock'] ?? 0) : 0;
            $records[] = new SourceDatetimeRecord(
                (string) $id,
                $start,
                EventinDates::local((string) $end_date, (string) $end_time, $zone) ?? $start,
                ['capacity' => $capacity > 0 ? $capacity : null]
            );
        }
        usort($records, static fn($a, $b): int => strcmp($a->start()->raw(), $b->start()->raw()));
        return $records;
    }


    /**
     * The address embedded in the event, or its online-meeting link, as a venue; null when it has neither.
     */
    private function venue(array $fields): ?SourceVenueRecord
    {
        $location = $fields['etn_event_location'] ?? $fields['location'] ?? [];
        $location = is_array($location) ? $location : [];
        $address  = trim((string) ($location['address'] ?? ''));
        if ($address !== '') {
            $name = trim((string) strtok($address, ','));
            $lat  = $location['latitude'] ?? null;
            $lng  = $location['longitude'] ?? null;
            return new SourceVenueRecord(
                'addr:' . md5(strtolower($address)),
                $name !== '' ? $name : $address,
                [
                    'address'   => $address,
                    'latitude'  => is_numeric($lat) && (float) $lat !== 0.0 ? (float) $lat : null,
                    'longitude' => is_numeric($lng) && (float) $lng !== 0.0 ? (float) $lng : null,
                ]
            );
        }
        $link = trim((string) ($fields['meeting_link'] ?? ''));
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
     * @param int[] $ids    The occurrence post ids.
     * @param array $fields The owner's meta.
     * @return UnmappedConcept[]
     * @throws MigrationRecordException
     */
    private function unmapped(array $ids, array $fields): array
    {
        $concepts = [];
        $tags     = $this->terms($ids, EventinSchema::TAXONOMY_TAG);
        if ($tags !== []) {
            $concepts[] = new UnmappedConcept('tags', __('Tags', 'migrate-events-to-event-espresso'), $tags);
        }
        foreach (['organizer' => ['etn_event_organizer', __('Organizers', 'migrate-events-to-event-espresso')], 'speakers' => ['etn_event_speaker', __('Speakers', 'migrate-events-to-event-espresso')]] as $concept => [$key, $label]) {
            $names = $this->userNames((array) ($fields[ $key ] ?? []));
            if ($names !== []) {
                $concepts[] = new UnmappedConcept($concept, $label, $names);
            }
        }
        $schedule = array_column($this->db->posts(array_map('intval', (array) ($fields['etn_event_schedule'] ?? []))), 'post_title');
        if ($schedule !== []) {
            $concepts[] = new UnmappedConcept('schedule', __('Schedule', 'migrate-events-to-event-espresso'), array_values($schedule));
        }
        $venue = $this->venue($fields);
        foreach (['meeting_link' => 'online-meeting', 'external_link' => 'external-link'] as $key => $concept) {
            if (
                trim((string) ($fields[ $key ] ?? '')) !== ''
                && ($key === 'external_link' || ($venue !== null && $venue->virtualUrl() === ''))
            ) {
                $concepts[] = new UnmappedConcept($concept, __('Link', 'migrate-events-to-event-espresso'), [trim((string) $fields[ $key ])]);
            }
        }
        return $concepts;
    }


    /**
     * @param array $user_ids A user id list from event meta.
     * @return string[] Display names
     * @throws MigrationRecordException
     */
    private function userNames(array $user_ids): array
    {
        $users = $this->db->users(array_map('intval', $user_ids));
        return array_values(array_filter(array_column($users, 'display_name')));
    }


    private function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true'], true);
    }


    private function failure(string $message, string $source_event_id): MigrationRecordException
    {
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        return new MigrationRecordException($message, WarningRule::RECORD_FAILED, CrosswalkKeys::TYPE_EVENT, $source_event_id);
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }
}
// End of file EventinEventReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventin/EventinEventReader.php
