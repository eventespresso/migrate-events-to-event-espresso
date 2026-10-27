<?php

namespace EventEspresso\EventMigrator\domain\services\migration\mapping;

use EventEspresso\core\domain\services\registration\RegStatus;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\records\SourceEventRecord;
use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\normalize\AuthorResolver;
use EventEspresso\EventMigrator\domain\services\migration\normalize\EventGroup;
use EventEspresso\EventMigrator\domain\services\migration\normalize\ResolvedVenue;
use EventEspresso\EventMigrator\domain\services\migration\normalize\StatusMapper;
use EventEspresso\EventMigrator\domain\services\migration\normalize\TimezoneNormalizer;
use EventEspresso\EventMigrator\domain\services\migration\normalize\UnmappedConceptWriter;
use EventEspresso\EventMigrator\domain\services\migration\normalize\VenueResolver;

/**
 * Class SourceEventMapper
 *
 * Turns one EventGroup (from RecurrenceStrategy) into the MappedEventGraph a
 * persister writes. Validates everything that can fail before anything with a
 * side effect on the run context (venue reuse notes, author and concept
 * warnings) happens, so a record that fails leaves no reuse note behind. Never
 * writes: the venue is resolved here so dry runs report de-duplication, but
 * creation is the persister's job.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class SourceEventMapper
{
    /**
     * Statuses that make an event visible, applied only with "preserve source status" and ee_publish_events.
     */
    public const VISIBLE_STATUSES = ['publish', 'private', 'cancelled', 'postponed'];

    private StatusMapper $status_mapper;

    private VenueResolver $venue_resolver;

    private AuthorResolver $author_resolver;

    private SourceTicketMapper $ticket_mapper;

    private UnmappedConceptWriter $concept_writer;

    private ?TimezoneNormalizer $normalizer = null;


    public function __construct(
        StatusMapper $status_mapper,
        VenueResolver $venue_resolver,
        AuthorResolver $author_resolver,
        SourceTicketMapper $ticket_mapper,
        UnmappedConceptWriter $concept_writer
    ) {
        $this->status_mapper      = $status_mapper;
        $this->venue_resolver     = $venue_resolver;
        $this->author_resolver    = $author_resolver;
        $this->ticket_mapper      = $ticket_mapper;
        $this->concept_writer     = $concept_writer;
    }


    /**
     * The graph to persist, or null when the source event is trashed (reported as record-trashed).
     *
     * @throws MigrationRecordException When the event has no usable dates, a date range is reversed, or its
     *                                  status is unmapped (UnmappedStatusException).
     */
    public function map(EventGroup $group, StatusTable $status_table, MigrationContext $context): ?MappedEventGraph
    {
        $event = $group->event();
        $id    = $event->sourceId();
        if ($event->isTrashed()) {
            $context->addWarning(new MigrationWarning(WarningRule::RECORD_TRASHED, CrosswalkKeys::TYPE_EVENT, $id));
            return null;
        }
        $normalizer                 = $this->normalizer($context);
        $datetimes                  = $this->datetimes($event, $normalizer);
        [$status, $source_status]   = $this->status($event, $status_table, $context);
        $tickets                    = $this->ticket_mapper->map($event, $group, $datetimes, $normalizer, $context);

        $author = $this->author_resolver->resolve($event->authorWpUserId(), CrosswalkKeys::TYPE_EVENT, $id, $context);
        $venue  = $this->venue($event, $context);
        foreach ($this->concept_writer->warnings($event->unmapped(), CrosswalkKeys::TYPE_EVENT, $id) as $warning) {
            $context->addWarning($warning);
        }
        $first_start = $event->datetimes()[0]->start();
        $tz_warning  = $normalizer->warningFor($first_start, $event->timezone(), CrosswalkKeys::TYPE_EVENT, $id);
        if ($tz_warning instanceof MigrationWarning) {
            $context->addWarning($tz_warning);
        }

        $fields = [
            'EVT_name'                        => trim($event->title()),
            'EVT_desc'                        => $this->concept_writer->append(
                wp_kses_post($event->description()),
                $event->unmapped()
            ),
            'EVT_short_desc'                  => wp_kses_post($event->excerpt()),
            'status'                          => $status,
            'EVT_wp_user'                     => $author,
            'EVT_default_registration_status' => RegStatus::PENDING_PAYMENT,
        ];
        if ($venue instanceof ResolvedVenue && $venue->existingId() !== null) {
            $fields['VNU_ID'] = $venue->existingId();
        }
        return new MappedEventGraph(
            $id,
            $fields,
            $datetimes,
            $tickets,
            [
                'venue'              => $venue,
                'categories'         => $this->categories($event),
                'occurrence_ids'     => $group->occurrenceIds(),
                'series_id'          => $group->seriesId(),
                'source_status'      => $source_status,
                'featured_image_id'  => $event->featuredImageId(),
                'featured_image_url' => $event->featuredImageUrl(),
            ]
        );
    }


    /**
     * Whether the running user may publish events. Separate so tests can substitute it.
     */
    protected function canPublish(): bool
    {
        return current_user_can('ee_publish_events');
    }


    private function normalizer(MigrationContext $context): TimezoneNormalizer
    {
        if (
            ! $this->normalizer instanceof TimezoneNormalizer
            || $this->normalizer->siteTimezone() !== $context->siteTimezone()
        ) {
            $this->normalizer = new TimezoneNormalizer($context->siteTimezone());
        }
        return $this->normalizer;
    }


    /**
     * @return MappedDatetime[]
     * @throws MigrationRecordException When there are no dates or a range ends before it starts.
     */
    private function datetimes(SourceEventRecord $event, TimezoneNormalizer $normalizer): array
    {
        $mapped = [];
        foreach ($event->datetimes() as $datetime) {
            [$start, $end] = $normalizer->normalizeRange($datetime->start(), $datetime->end(), $event->timezone());
            if ($end < $start) {
                // The message is a translated constant; the type and id are data carried for the report.
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new MigrationRecordException(
                    esc_html__('The source date ends before it starts.', 'migrate-events-to-event-espresso'),
                    WarningRule::RECORD_FAILED,
                    CrosswalkKeys::TYPE_DATETIME,
                    $datetime->sourceId()
                );
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $capacity = $datetime->capacity();
            $mapped[] = new MappedDatetime(
                $datetime->sourceId(),
                $start,
                $end,
                trim($datetime->name()),
                wp_kses_post($datetime->description()),
                $capacity === null || $capacity < 0 ? (float) EE_INF : (float) $capacity
            );
        }
        if ($mapped === []) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new MigrationRecordException(
                esc_html__('The source event has no dates.', 'migrate-events-to-event-espresso'),
                WarningRule::RECORD_FAILED,
                CrosswalkKeys::TYPE_EVENT,
                $event->sourceId()
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $mapped;
    }


    /**
     * The status to write and the mapped source status (REQ-011). Visible statuses apply only with "preserve
     * source status" and ee_publish_events; otherwise the event is a draft and the status is reported.
     *
     * @return string[] The status to write at index 0, the mapped source status at index 1.
     */
    private function status(SourceEventRecord $event, StatusTable $table, MigrationContext $context): array
    {
        $raw = trim($event->status());
        if ($raw === '') {
            return ['draft', ''];
        }
        $mapped = $this->status_mapper->event($table, $raw, CrosswalkKeys::TYPE_EVENT, $event->sourceId());
        if (! in_array($mapped, self::VISIBLE_STATUSES, true)) {
            return ['draft', $mapped];
        }
        if ($context->options()->preserveStatus() && $this->canPublish()) {
            return [$mapped, $mapped];
        }
        $context->addWarning(
            new MigrationWarning(
                WarningRule::STATUS_NOT_APPLIED,
                CrosswalkKeys::TYPE_EVENT,
                $event->sourceId(),
                [$mapped]
            )
        );
        return ['draft', $mapped];
    }


    /**
     * The venue decision, or null when the event has none. A venue that cannot be resolved (no name) drops
     * the venue with a record-failed warning instead of failing the whole event.
     */
    private function venue(SourceEventRecord $event, MigrationContext $context): ?ResolvedVenue
    {
        $venue = $event->venue();
        if ($venue === null) {
            return null;
        }
        try {
            return $this->venue_resolver->resolve($venue, $context);
        } catch (MigrationRecordException $exception) {
            if ($exception->rule() === WarningRule::TARGET_DELETED) {
                // Already reported as target-deleted; the event is migrated without the deleted venue.
                return null;
            }
            $context->addWarning(
                new MigrationWarning(
                    WarningRule::RECORD_FAILED,
                    CrosswalkKeys::TYPE_VENUE,
                    $venue->sourceId(),
                    [html_entity_decode($exception->getMessage(), ENT_QUOTES, 'UTF-8')]
                )
            );
            return null;
        }
    }


    /**
     * @return string[] Unique, trimmed, non-empty category names in source order.
     */
    private function categories(SourceEventRecord $event): array
    {
        $names = [];
        foreach ($event->categories() as $category) {
            $name = trim($category);
            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return $names;
    }
}
// End of file SourceEventMapper.php
// Location: EventEspresso\EventMigrator\domain\services\migration\mapping/SourceEventMapper.php
