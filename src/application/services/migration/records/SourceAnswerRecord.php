<?php

namespace EventEspresso\EventMigrator\application\services\migration\records;

/**
 * Class SourceAnswerRecord
 *
 * One custom-field answer on a source booking. Multi-value answers are
 * carried as a list; single values as a one-element list.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceAnswerRecord
{
    private string $field_source_id;

    private array $values;


    /**
     * @param string $field_source_id Source identifier (slug or id) of the custom field answered.
     * @param array  $values          The answer value(s) as strings.
     */
    public function __construct(string $field_source_id, array $values)
    {
        $this->field_source_id = $field_source_id;
        $this->values          = array_map('strval', array_values($values));
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
     * The single answer value, or the values joined with a comma when there are several.
     */
    public function value(): string
    {
        return implode(', ', $this->values);
    }
}
// End of file SourceAnswerRecord.php
// Location: EventEspresso\EventMigrator\application\services\migration\records/SourceAnswerRecord.php
