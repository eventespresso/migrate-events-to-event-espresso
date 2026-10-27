<?php

namespace EventEspresso\EventMigrator\domain\services\migration\normalize;

use EventEspresso\EventMigrator\application\services\migration\records\SourceEventRecord;

/**
 * Class EventGroup
 *
 * One EE event to create: the source event record to map (for a collapsed
 * series, the first occurrence carrying every occurrence's dates), the source
 * ids of the occurrences it stands for, and the series they belong to.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventGroup
{
    private SourceEventRecord $event;

    private array $occurrence_ids;

    private ?string $series_id;


    /**
     * @param SourceEventRecord $event          The event record to map.
     * @param string[]          $occurrence_ids The source ids of every occurrence the event stands for.
     * @param string|null       $series_id      The source series identifier, kept for the crosswalk.
     */
    public function __construct(SourceEventRecord $event, array $occurrence_ids, ?string $series_id)
    {
        $this->event          = $event;
        $this->occurrence_ids = array_map('strval', array_values($occurrence_ids));
        $this->series_id      = $series_id;
    }


    public function event(): SourceEventRecord
    {
        return $this->event;
    }


    /**
     * @return string[]
     */
    public function occurrenceIds(): array
    {
        return $this->occurrence_ids;
    }


    public function seriesId(): ?string
    {
        return $this->series_id;
    }


    public function isSeries(): bool
    {
        return count($this->occurrence_ids) > 1;
    }
}
// End of file EventGroup.php
// Location: EventEspresso\EventMigrator\domain\services\migration\normalize/EventGroup.php
