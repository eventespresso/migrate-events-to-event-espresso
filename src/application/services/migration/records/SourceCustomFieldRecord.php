<?php

namespace EventEspresso\EventMigrator\application\services\migration\records;

/**
 * Class SourceCustomFieldRecord
 *
 * A custom registration field the source defines, which becomes one Event
 * Espresso question in the per-source question group.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceCustomFieldRecord
{
    private string $source_id;

    private string $label;

    private string $type;

    private bool $required;

    private array $options;


    /**
     * @param string $source_id Source identifier (slug or id) of the field.
     * @param string $label     The field's label as the source shows it.
     * @param string $type      The source's field type slug (text, select, checkbox, ...).
     * @param array  $extra     Optional: required (bool), options (string[]).
     */
    public function __construct(string $source_id, string $label, string $type, array $extra = [])
    {
        $this->source_id = $source_id;
        $this->label     = $label;
        $this->type      = $type;
        $this->required  = (bool) ($extra['required'] ?? false);
        $this->options   = array_map('strval', array_values((array) ($extra['options'] ?? [])));
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    public function label(): string
    {
        return $this->label;
    }


    public function type(): string
    {
        return $this->type;
    }


    public function isRequired(): bool
    {
        return $this->required;
    }


    /**
     * @return string[]
     */
    public function options(): array
    {
        return $this->options;
    }
}
// End of file SourceCustomFieldRecord.php
// Location: EventEspresso\EventMigrator\application\services\migration\records/SourceCustomFieldRecord.php
