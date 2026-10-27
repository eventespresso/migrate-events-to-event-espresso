<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\event_genius;

use EventEspresso\EventMigrator\application\services\migration\records\SourceCustomFieldRecord;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventGeniusCustomFields
 *
 * Event Genius' registration form fields (research profile §3): the
 * definitions saved by the form builder in the `evge_all_fields` option
 * (id, type, slug, label), of which first, last and email are the attendee's
 * own details. An installation that never saved the form has no option, and the
 * answers are then migrated as text questions labelled with their slug.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventGeniusCustomFields
{
    /**
     * Event Genius field type => the question type the engine understands; any other type has no text answer.
     */
    private const TYPES = [
        'text'     => 'text',
        'phone'    => 'text',
        'textarea' => 'textarea',
        'select'   => 'select',
        'dropdown' => 'select',
        'radio'    => 'radio',
        'checkbox' => 'checkbox',
        'email'    => 'email',
        'number'   => 'number',
        'date'     => 'date',
        'url'      => 'url',
    ];

    private SourceDatabase $db;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * @return SourceCustomFieldRecord[]
     * @throws MigrationRecordException When the read fails.
     */
    public function definitions(): array
    {
        $stored = SourceDatabase::safeUnserialize((string) $this->db->option(EventGeniusSchema::FIELDS_OPTION));
        $fields = [];
        foreach (is_array($stored) ? $stored : [] as $field) {
            $slug = is_array($field) ? trim((string) ($field['slug'] ?? '')) : '';
            $type = is_array($field) ? (self::TYPES[ strtolower((string) ($field['type'] ?? '')) ] ?? '') : '';
            if ($slug === '' || $type === '' || in_array($slug, EventGeniusSchema::SYSTEM_META, true) || isset($fields[ $slug ])) {
                continue;
            }
            $fields[ $slug ] = new SourceCustomFieldRecord(
                $slug,
                trim(wp_strip_all_tags((string) ($field['label'] ?? $slug))),
                $type,
                ['required' => ! empty($field['required']), 'options' => $this->options((array) ($field['options'] ?? []))]
            );
        }
        return array_values($fields);
    }


    /**
     * @param array $options A field's options: strings, or rows with a label.
     * @return string[]
     */
    private function options(array $options): array
    {
        $labels = [];
        foreach ($options as $option) {
            $label = is_array($option) ? (string) ($option['label'] ?? $option['value'] ?? '') : (string) $option;
            if (trim($label) !== '') {
                $labels[] = trim($label);
            }
        }
        return $labels;
    }
}
// End of file EventGeniusCustomFields.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\event_genius/EventGeniusCustomFields.php
