<?php

namespace EventEspresso\EventMigrator\domain\services\migration\mapping;

use EEM_Event;
use EventEspresso\EventMigrator\domain\services\migration\normalize\ResolvedVenue;

/**
 * Class MappedEventGraph
 *
 * Everything a persister writes for one EE event: the event fields, the venue
 * decision, category names, datetimes, tickets, and the source keys the
 * crosswalk links to the event. Pure data; built by SourceEventMapper.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class MappedEventGraph
{
    private string $source_id;

    private array $event_fields;

    private array $datetimes;

    private array $tickets;

    private ?ResolvedVenue $venue;

    private array $categories;

    private array $occurrence_ids;

    private ?string $series_id;

    private string $source_status;

    private ?int $featured_image_id;

    private string $featured_image_url;


    /**
     * @param string           $source_id    The source id the event is crosswalked under (the first occurrence).
     * @param array            $event_fields EE_Event field name => value; VNU_ID is set by the persister.
     * @param MappedDatetime[] $datetimes    At least one.
     * @param MappedTicket[]   $tickets      At least one.
     * @param array            $extra        Optional: venue (ResolvedVenue), categories (string[]),
     *                                       occurrence_ids (string[], every merged source event id),
     *                                       series_id (?string), source_status (string, the mapped source
     *                                       status even when not applied), featured_image_id (?int),
     *                                       featured_image_url (string).
     */
    public function __construct(string $source_id, array $event_fields, array $datetimes, array $tickets, array $extra = [])
    {
        $this->source_id          = $source_id;
        $this->event_fields       = $event_fields;
        $this->datetimes          = array_values(
            array_filter($datetimes, static fn($datetime): bool => $datetime instanceof MappedDatetime)
        );
        $this->tickets            = array_values(
            array_filter($tickets, static fn($ticket): bool => $ticket instanceof MappedTicket)
        );
        $this->venue              = ($extra['venue'] ?? null) instanceof ResolvedVenue ? $extra['venue'] : null;
        $this->categories         = array_values(array_map('strval', (array) ($extra['categories'] ?? [])));
        $this->occurrence_ids     = array_values(
            array_map('strval', (array) ($extra['occurrence_ids'] ?? [$source_id]))
        );
        $this->series_id          = isset($extra['series_id']) && $extra['series_id'] !== ''
            ? (string) $extra['series_id']
            : null;
        $this->source_status      = (string) ($extra['source_status'] ?? '');
        $this->featured_image_id  = isset($extra['featured_image_id']) ? (int) $extra['featured_image_id'] : null;
        $this->featured_image_url = (string) ($extra['featured_image_url'] ?? '');
    }


    /**
     * The source's cancelled or postponed state when the event is written with another status (a draft run),
     * so it can be kept on the event; '' otherwise.
     *
     * @param string $written_status The status the event is written with.
     */
    public function heldSourceStatus(string $written_status): string
    {
        return in_array($this->source_status, [EEM_Event::cancelled, EEM_Event::postponed], true)
            && $written_status !== $this->source_status
            ? $this->source_status
            : '';
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    public function eventFields(): array
    {
        return $this->event_fields;
    }


    /**
     * @return MappedDatetime[]
     */
    public function datetimes(): array
    {
        return $this->datetimes;
    }


    /**
     * @return MappedTicket[]
     */
    public function tickets(): array
    {
        return $this->tickets;
    }


    public function venue(): ?ResolvedVenue
    {
        return $this->venue;
    }


    /**
     * @return string[] category names
     */
    public function categories(): array
    {
        return $this->categories;
    }


    /**
     * Every source event id the EE event stands for; the first is sourceId().
     *
     * @return string[]
     */
    public function occurrenceIds(): array
    {
        return $this->occurrence_ids;
    }


    /**
     * The source ids other than sourceId(), crosswalked as aliases of the event.
     *
     * @return string[]
     */
    public function aliasIds(): array
    {
        return array_values(array_diff($this->occurrence_ids, [$this->source_id]));
    }


    public function seriesId(): ?string
    {
        return $this->series_id;
    }


    /**
     * The source status after mapping, kept for the report even when the event is written as a draft.
     */
    public function sourceStatus(): string
    {
        return $this->source_status;
    }


    public function featuredImageId(): ?int
    {
        return $this->featured_image_id;
    }


    public function featuredImageUrl(): string
    {
        return $this->featured_image_url;
    }
}
// End of file MappedEventGraph.php
// Location: EventEspresso\EventMigrator\domain\services\migration\mapping/MappedEventGraph.php
