<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\amelia;

use EventEspresso\EventMigrator\application\services\migration\records\SourceAnswerRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceCustomFieldRecord;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class AmeliaCustomFields
 *
 * Amelia's custom fields (a paid feature, research profile §3): the field
 * definitions in amelia_custom_fields and their options, and the answers,
 * which are JSON on the booking (`{"<field id>": {"label", "value", "type"}}`)
 * or, for fields saved with the customer, on the customer. File and content
 * fields carry no text answer and are left out.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class AmeliaCustomFields
{
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
        if (! $this->db->tableExists(AmeliaSchema::CUSTOM_FIELDS)) {
            return [];
        }
        $options = [];
        if ($this->db->tableExists(AmeliaSchema::CUSTOM_FIELD_OPTIONS)) {
            $rows = $this->db->rows(
                'SELECT customFieldId, label FROM ' . $this->db->table(AmeliaSchema::CUSTOM_FIELD_OPTIONS)
                . ' ORDER BY customFieldId, position, id'
            );
            foreach ($rows as $row) {
                $options[ (int) $row['customFieldId'] ][] = (string) $row['label'];
            }
        }
        $fields = [];
        $rows   = $this->db->rows('SELECT * FROM ' . $this->db->table(AmeliaSchema::CUSTOM_FIELDS) . ' ORDER BY position, id');
        foreach ($rows as $row) {
            if (in_array($row['type'], AmeliaSchema::SKIPPED_FIELD_TYPES, true)) {
                continue;
            }
            $fields[] = new SourceCustomFieldRecord(
                (string) $row['id'],
                trim(wp_strip_all_tags((string) $row['label'])),
                (string) $row['type'],
                ['required' => (int) $row['required'] === 1, 'options' => $options[ (int) $row['id'] ] ?? []]
            );
        }
        return $fields;
    }


    /**
     * The answers in a customFields JSON value, in field order; blank answers and file/content fields skipped.
     *
     * @return SourceAnswerRecord[]
     */
    public static function answers(?string $json): array
    {
        $data    = json_decode((string) $json, true);
        $answers = [];
        foreach (is_array($data) ? $data : [] as $field_id => $field) {
            if (! is_array($field) || in_array($field['type'] ?? '', AmeliaSchema::SKIPPED_FIELD_TYPES, true)) {
                continue;
            }
            $raw    = $field['value'] ?? '';
            $values = [];
            foreach (is_array($raw) ? $raw : [$raw] as $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $values[] = trim((string) $value);
                }
            }
            if ($values !== []) {
                $answers[] = new SourceAnswerRecord((string) $field_id, $values);
            }
        }
        return $answers;
    }
}
// End of file AmeliaCustomFields.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\amelia/AmeliaCustomFields.php
