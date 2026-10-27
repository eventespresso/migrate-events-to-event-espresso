<?php

namespace EventEspresso\EventMigrator\domain\services\migration\run;

/**
 * Class MigrationCursor
 *
 * Where a migration batch job is: the ordered source event ids, the index of
 * the event being worked on, whether its graph or its bookings are next, the
 * booking offset, and the ids of the current event's persisted graph (needed
 * by every later booking chunk, which runs in a separate request). Stored in
 * the job's extra_data as toArray().
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class MigrationCursor
{
    public const PHASE_EVENT    = 'event';

    public const PHASE_BOOKINGS = 'bookings';

    /**
     * @var string[]
     */
    private array $event_ids;

    private int $event_index;

    private string $phase;

    private int $booking_offset;

    /**
     * @var array{source_id: string, event_id: int, datetime_ids: array, ticket_ids: array, ticket_prices: array}|null
     */
    private ?array $current;


    /**
     * @param string[] $event_ids The adapter's ordered source event ids.
     */
    public function __construct(
        array $event_ids,
        int $event_index = 0,
        string $phase = self::PHASE_EVENT,
        int $booking_offset = 0,
        ?array $current = null
    ) {
        $this->event_ids      = array_values(array_map('strval', $event_ids));
        $this->event_index    = max(0, $event_index);
        $this->phase          = $phase === self::PHASE_BOOKINGS && $current !== null ? $phase : self::PHASE_EVENT;
        $this->booking_offset = max(0, $booking_offset);
        $this->current        = $this->phase === self::PHASE_BOOKINGS ? $current : null;
    }


    public static function fromArray(array $data): MigrationCursor
    {
        return new MigrationCursor(
            (array) ($data['event_ids'] ?? []),
            (int) ($data['event_index'] ?? 0),
            (string) ($data['phase'] ?? self::PHASE_EVENT),
            (int) ($data['booking_offset'] ?? 0),
            is_array($data['current'] ?? null) ? $data['current'] : null
        );
    }


    public function toArray(): array
    {
        return [
            'event_ids'      => $this->event_ids,
            'event_index'    => $this->event_index,
            'phase'          => $this->phase,
            'booking_offset' => $this->booking_offset,
            'current'        => $this->current,
        ];
    }


    /**
     * @return string[]
     */
    public function eventIds(): array
    {
        return $this->event_ids;
    }


    public function eventIndex(): int
    {
        return $this->event_index;
    }


    public function phase(): string
    {
        return $this->phase;
    }


    public function bookingOffset(): int
    {
        return $this->booking_offset;
    }


    public function current(): ?array
    {
        return $this->current;
    }


    /**
     * The source id of the event the next step works on, or null when every event is done.
     */
    public function currentEventId(): ?string
    {
        return $this->event_ids[ $this->event_index ] ?? null;
    }


    public function isFinished(): bool
    {
        return $this->phase === self::PHASE_EVENT && $this->currentEventId() === null;
    }


    /**
     * The event's graph exists (now or from an earlier run): its bookings are next.
     *
     * @param array $current The event's ids: {source_id, event_id, datetime_ids, ticket_ids, ticket_prices}.
     */
    public function startBookings(array $current): void
    {
        $this->phase          = self::PHASE_BOOKINGS;
        $this->booking_offset = 0;
        $this->current        = $current;
    }


    public function advanceBookings(int $count): void
    {
        $this->booking_offset += max(0, $count);
    }


    /**
     * The current event is finished (or skipped): move to the next one.
     */
    public function nextEvent(): void
    {
        $this->event_index++;
        $this->phase          = self::PHASE_EVENT;
        $this->booking_offset = 0;
        $this->current        = null;
    }
}
// End of file MigrationCursor.php
// Location: EventEspresso\EventMigrator\domain\services\migration\run/MigrationCursor.php
