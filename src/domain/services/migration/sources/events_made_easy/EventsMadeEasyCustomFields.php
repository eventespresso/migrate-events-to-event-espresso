<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy;

use EventEspresso\EventMigrator\application\services\migration\records\SourceAnswerRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceCustomFieldRecord;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventsMadeEasyCustomFields
 *
 * EME's form fields and answers (research profile §3). Definitions are the
 * `eme_formfields` rows meant for the booking form (purpose rsvp, people or
 * generic) whose type has a text answer; answers are `eme_answers` rows of
 * type "booking" (the booking) or "person" (the booker), with multiple
 * values joined by `||`. Per-seat dynamic answers (eme_grouping above zero)
 * belong to attendees Event Espresso has no record for and are reported as an
 * unmapped concept by the booking reader.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventsMadeEasyCustomFields
{
    /**
     * EME field type => the question type the engine understands; any other type has no text answer.
     */
    private const TYPES = [
        'text'               => 'text',
        'textarea'           => 'textarea',
        'dropdown'           => 'select',
        'dropdown_multi'     => 'select',
        'datalist'           => 'select',
        'radiobox'           => 'radio',
        'radiobox_vertical'  => 'radio',
        'checkbox'           => 'checkbox',
        'checkbox_vertical'  => 'checkbox',
        'date_js'            => 'date',
    ];

    private SourceDatabase $db;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * @return SourceCustomFieldRecord[]
     * @throws MigrationRecordException When a read fails.
     */
    public function definitions(): array
    {
        $rows   = $this->db->rows(
            'SELECT * FROM ' . $this->db->table(EventsMadeEasySchema::FORMFIELDS) . ' WHERE field_purpose IN ('
            . SourceDatabase::placeholders(EventsMadeEasySchema::BOOKING_PURPOSES, '%s') . ') ORDER BY field_id',
            EventsMadeEasySchema::BOOKING_PURPOSES
        );
        $fields = [];
        foreach ($rows as $row) {
            $type = self::TYPES[ (string) $row['field_type'] ] ?? '';
            if ($type === '') {
                continue;
            }
            $fields[] = new SourceCustomFieldRecord(
                (string) $row['field_id'],
                trim(wp_strip_all_tags((string) $row['field_name'])),
                $type,
                [
                    'required' => (int) $row['field_required'] === 1,
                    'options'  => in_array($type, ['select', 'radio', 'checkbox'], true)
                        ? EventsMadeEasyProps::slots($row['field_values'])
                        : [],
                ]
            );
        }
        return $fields;
    }


    /**
     * A field's answer as a source answer; null when it is blank.
     */
    public static function answer(string $field_id, string $stored): ?SourceAnswerRecord
    {
        $values = array_values(array_filter(EventsMadeEasyProps::slots($stored), static fn(string $value): bool => $value !== ''));
        return $values === [] ? null : new SourceAnswerRecord($field_id, $values);
    }
}
// End of file EventsMadeEasyCustomFields.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy/EventsMadeEasyCustomFields.php
