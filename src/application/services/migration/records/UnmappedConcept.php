<?php

namespace EventEspresso\EventMigrator\application\services\migration\records;

/**
 * Class UnmappedConcept
 *
 * A source concept with no Event Espresso counterpart (organizer, tag, seat
 * map, coupon, ...). Preserved as labelled text or reported, never dropped.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class UnmappedConcept
{
    private string $concept;

    private string $label;

    private array $values;


    /**
     * @param string $concept Stable concept id, e.g. "organizer", "tag", "coupon".
     * @param string $label   Untranslated source-facing label for the concept.
     * @param array  $values  The raw values the source held, as scalars or scalar arrays.
     */
    public function __construct(string $concept, string $label, array $values)
    {
        $this->concept = $concept;
        $this->label   = $label;
        $this->values  = $values;
    }


    public function concept(): string
    {
        return $this->concept;
    }


    public function label(): string
    {
        return $this->label;
    }


    public function values(): array
    {
        return $this->values;
    }
}
// End of file UnmappedConcept.php
// Location: EventEspresso\EventMigrator\application\services\migration\records/UnmappedConcept.php
