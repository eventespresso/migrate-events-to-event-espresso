<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

/**
 * Class PersistedEvent
 *
 * The ids of what one persistEvent() call created, recorded the moment each
 * save succeeds. It is both the result handed to the booking persister (which
 * looks tickets and datetimes up by source id) and the input of compensation
 * (EventGraphRemover), so a failure part-way through can remove exactly what
 * was created. Ids from a dry run are synthetic negatives.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class PersistedEvent
{
    private string $source_id;

    private int $event_id = 0;

    private ?int $venue_id = null;

    private ?int $created_venue_id = null;

    /**
     * @var array<string, int> source datetime id => EE datetime id
     */
    private array $datetime_ids = [];

    /**
     * @var array<string, int> source ticket id => EE ticket id
     */
    private array $ticket_ids = [];

    /**
     * @var array<string, int> source ticket id => EE base price id
     */
    private array $price_ids = [];

    /**
     * @var int[] term_taxonomy ids of categories this call created (not pre-existing ones it linked)
     */
    private array $created_term_taxonomy_ids = [];


    public function __construct(string $source_id)
    {
        $this->source_id = $source_id;
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    public function setEventId(int $event_id): void
    {
        $this->event_id = $event_id;
    }


    /**
     * The EE event id, or 0 when the event was never saved.
     */
    public function eventId(): int
    {
        return $this->event_id;
    }


    /**
     * The venue linked to the event, whether created by this call or reused.
     */
    public function setVenueId(int $venue_id, bool $created): void
    {
        $this->venue_id = $venue_id;
        if ($created) {
            $this->created_venue_id = $venue_id;
        }
    }


    public function venueId(): ?int
    {
        return $this->venue_id;
    }


    /**
     * The venue this call created, which compensation removes when nothing else uses it.
     */
    public function createdVenueId(): ?int
    {
        return $this->created_venue_id;
    }


    public function addDatetime(string $source_id, int $datetime_id): void
    {
        $this->datetime_ids[ $source_id ] = $datetime_id;
    }


    /**
     * @return array<string, int> source datetime id => EE datetime id
     */
    public function datetimeIds(): array
    {
        return $this->datetime_ids;
    }


    public function datetimeIdFor(string $source_id): ?int
    {
        return $this->datetime_ids[ $source_id ] ?? null;
    }


    public function addTicket(string $source_id, int $ticket_id): void
    {
        $this->ticket_ids[ $source_id ] = $ticket_id;
    }


    /**
     * @return array<string, int> source ticket id => EE ticket id
     */
    public function ticketIds(): array
    {
        return $this->ticket_ids;
    }


    public function ticketIdFor(string $source_id): ?int
    {
        return $this->ticket_ids[ $source_id ] ?? null;
    }


    public function addPrice(string $ticket_source_id, int $price_id): void
    {
        $this->price_ids[ $ticket_source_id ] = $price_id;
    }


    /**
     * @return array<string, int> source ticket id => EE base price id
     */
    public function priceIds(): array
    {
        return $this->price_ids;
    }


    public function addCreatedTermTaxonomy(int $term_taxonomy_id): void
    {
        $this->created_term_taxonomy_ids[] = $term_taxonomy_id;
    }


    /**
     * @return int[]
     */
    public function createdTermTaxonomyIds(): array
    {
        return $this->created_term_taxonomy_ids;
    }


    /**
     * Whether anything was created, i.e. whether compensation has work to do.
     */
    public function hasCreated(): bool
    {
        return $this->event_id !== 0
            || $this->created_venue_id !== null
            || $this->datetime_ids !== []
            || $this->ticket_ids !== []
            || $this->price_ids !== []
            || $this->created_term_taxonomy_ids !== [];
    }
}
// End of file PersistedEvent.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/PersistedEvent.php
