<?php

namespace EventEspresso\EventMigrator\domain\services\migration\mapping;

/**
 * Class MappedAnswer
 *
 * One custom-field answer to write on a booking's registrations. The question
 * is found by the source field id the BookingAnswersPersister crosswalked
 * when it created the per-source questions.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class MappedAnswer
{
    private string $field_source_id;

    private array $values;


    /**
     * @param string   $field_source_id The source custom field id or slug.
     * @param string[] $values          The answer value(s).
     */
    public function __construct(string $field_source_id, array $values)
    {
        $this->field_source_id = $field_source_id;
        $this->values          = array_values(array_map('strval', $values));
    }


    public function fieldSourceId(): string
    {
        return $this->field_source_id;
    }


    /**
     * @return string[]
     */
    public function values(): array
    {
        return $this->values;
    }


    /**
     * ANS_value: the single value, or the values joined with commas.
     */
    public function value(): string
    {
        return implode(', ', $this->values);
    }
}
// End of file MappedAnswer.php
// Location: EventEspresso\EventMigrator\domain\services\migration\mapping/MappedAnswer.php
