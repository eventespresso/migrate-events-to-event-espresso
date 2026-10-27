<?php

namespace EventEspresso\EventMigrator\application\services\migration\records;

/**
 * Class SourceEventRecord
 *
 * A source event with everything its adapter could read for it: dates,
 * venue, categories, tickets, concepts with no EE counterpart, and the series
 * it belongs to. Mapping to EE fields happens in Phase 2; this is data only.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceEventRecord
{
    private string $source_id;

    private string $title;

    private string $description;

    private string $excerpt;

    private string $status;

    private ?int $author_wp_user_id;

    private ?int $featured_image_id;

    private string $featured_image_url;

    private array $categories;

    private ?SourceVenueRecord $venue;

    private array $datetimes;

    private array $tickets;

    private array $unmapped;

    private ?string $series_id;

    private ?string $timezone;

    private bool $trashed;

    private ?SourceDateTime $created_at;

    private string $slug;


    /**
     * @param string $source_id Source identifier of the event.
     * @param string $title     Event title.
     * @param array  $extra     Optional: description, excerpt, status (raw), featured_image_url, slug (strings),
     *                          author_wp_user_id, featured_image_id (?int), categories (string[]),
     *                          venue (SourceVenueRecord), datetimes (SourceDatetimeRecord[]),
     *                          tickets (SourceTicketRecord[]), unmapped (UnmappedConcept[]),
     *                          series_id, timezone (?string), trashed (bool), created_at (SourceDateTime).
     */
    public function __construct(string $source_id, string $title, array $extra = [])
    {
        $this->source_id          = $source_id;
        $this->title              = $title;
        $this->description        = (string) ($extra['description'] ?? '');
        $this->excerpt            = (string) ($extra['excerpt'] ?? '');
        $this->status             = (string) ($extra['status'] ?? '');
        $this->author_wp_user_id  = isset($extra['author_wp_user_id']) && (int) $extra['author_wp_user_id'] > 0
            ? (int) $extra['author_wp_user_id']
            : null;
        $this->featured_image_id  = isset($extra['featured_image_id']) && (int) $extra['featured_image_id'] > 0
            ? (int) $extra['featured_image_id']
            : null;
        $this->featured_image_url = (string) ($extra['featured_image_url'] ?? '');
        $this->categories         = array_map('strval', array_values((array) ($extra['categories'] ?? [])));
        $this->venue              = ($extra['venue'] ?? null) instanceof SourceVenueRecord ? $extra['venue'] : null;
        $this->datetimes          = $this->onlyInstancesOf(SourceDatetimeRecord::class, $extra['datetimes'] ?? []);
        $this->tickets            = $this->onlyInstancesOf(SourceTicketRecord::class, $extra['tickets'] ?? []);
        $this->unmapped           = $this->onlyInstancesOf(UnmappedConcept::class, $extra['unmapped'] ?? []);
        $this->series_id          = isset($extra['series_id']) ? (string) $extra['series_id'] : null;
        $this->timezone           = isset($extra['timezone']) ? (string) $extra['timezone'] : null;
        $this->trashed            = (bool) ($extra['trashed'] ?? false);
        $this->created_at         = ($extra['created_at'] ?? null) instanceof SourceDateTime
            ? $extra['created_at']
            : null;
        $this->slug               = (string) ($extra['slug'] ?? '');
    }


    private function onlyInstancesOf(string $class, $items): array
    {
        $kept = [];
        foreach ((array) $items as $item) {
            if ($item instanceof $class) {
                $kept[] = $item;
            }
        }
        return $kept;
    }


    /**
     * A copy of this record with a different list of occurrences, used when a
     * source series is collapsed into one event.
     *
     * @param SourceDatetimeRecord[] $datetimes The occurrences of the copy.
     */
    public function withDatetimes(array $datetimes): SourceEventRecord
    {
        $copy            = clone $this;
        $copy->datetimes = $this->onlyInstancesOf(SourceDatetimeRecord::class, $datetimes);
        return $copy;
    }


    /**
     * A copy with other tickets, for an adapter that takes the event from one reader and its tickets from
     * another.
     *
     * @param SourceTicketRecord[] $tickets The tickets of the copy.
     */
    public function withTickets(array $tickets): SourceEventRecord
    {
        $copy          = clone $this;
        $copy->tickets = $this->onlyInstancesOf(SourceTicketRecord::class, $tickets);
        return $copy;
    }


    /**
     * A copy with other unmapped concepts, for an adapter that adds to what another reader found.
     *
     * @param UnmappedConcept[] $unmapped The concepts of the copy.
     */
    public function withUnmapped(array $unmapped): SourceEventRecord
    {
        $copy           = clone $this;
        $copy->unmapped = $this->onlyInstancesOf(UnmappedConcept::class, $unmapped);
        return $copy;
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    public function title(): string
    {
        return $this->title;
    }


    public function description(): string
    {
        return $this->description;
    }


    public function excerpt(): string
    {
        return $this->excerpt;
    }


    /**
     * The raw source status; mapped through the adapter's StatusTable in Phase 2.
     */
    public function status(): string
    {
        return $this->status;
    }


    public function authorWpUserId(): ?int
    {
        return $this->author_wp_user_id;
    }


    public function featuredImageId(): ?int
    {
        return $this->featured_image_id;
    }


    public function featuredImageUrl(): string
    {
        return $this->featured_image_url;
    }


    /**
     * @return string[] category names
     */
    public function categories(): array
    {
        return $this->categories;
    }


    public function venue(): ?SourceVenueRecord
    {
        return $this->venue;
    }


    /**
     * @return SourceDatetimeRecord[]
     */
    public function datetimes(): array
    {
        return $this->datetimes;
    }


    /**
     * @return SourceTicketRecord[]
     */
    public function tickets(): array
    {
        return $this->tickets;
    }


    /**
     * @return UnmappedConcept[]
     */
    public function unmapped(): array
    {
        return $this->unmapped;
    }


    /**
     * Identifier shared by every occurrence of a recurring series, or null for a single event.
     */
    public function seriesId(): ?string
    {
        return $this->series_id;
    }


    public function timezone(): ?string
    {
        return $this->timezone;
    }


    public function isTrashed(): bool
    {
        return $this->trashed;
    }


    public function createdAt(): ?SourceDateTime
    {
        return $this->created_at;
    }


    public function slug(): string
    {
        return $this->slug;
    }
}
// End of file SourceEventRecord.php
// Location: EventEspresso\EventMigrator\application\services\migration\records/SourceEventRecord.php
