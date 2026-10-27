<?php

namespace EventEspresso\EventMigrator\application\services\migration\records;

/**
 * Class SourceDatetimeRecord
 *
 * One occurrence (date) of a source event, in the source's own timezone model.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceDatetimeRecord
{
    private string $source_id;

    private SourceDateTime $start;

    private SourceDateTime $end;

    private string $name;

    private string $description;

    private ?int $capacity;


    /**
     * @param string         $source_id Source identifier of the occurrence (or of the event when it has one date).
     * @param SourceDateTime $start     Start of the occurrence.
     * @param SourceDateTime $end       End of the occurrence.
     * @param array          $extra     Optional: name (string), description (string), capacity (?int, null = unlimited).
     */
    public function __construct(string $source_id, SourceDateTime $start, SourceDateTime $end, array $extra = [])
    {
        $this->source_id   = $source_id;
        $this->start       = $start;
        $this->end         = $end;
        $this->name        = (string) ($extra['name'] ?? '');
        $this->description = (string) ($extra['description'] ?? '');
        $this->capacity    = isset($extra['capacity']) ? (int) $extra['capacity'] : null;
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    public function start(): SourceDateTime
    {
        return $this->start;
    }


    public function end(): SourceDateTime
    {
        return $this->end;
    }


    public function name(): string
    {
        return $this->name;
    }


    public function description(): string
    {
        return $this->description;
    }


    /**
     * The occurrence's own capacity, or null when the source does not limit it.
     */
    public function capacity(): ?int
    {
        return $this->capacity;
    }
}
// End of file SourceDatetimeRecord.php
// Location: EventEspresso\EventMigrator\application\services\migration\records/SourceDatetimeRecord.php
