<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventin;

use EventEspresso\EventMigrator\application\services\migration\records\SourceAnswerRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceCustomFieldRecord;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventinCustomFields
 *
 * Eventin's extra attendee fields (research profile §3.10): a schema of
 * `{id, label, field_type, required, options}` rows in the event's
 * `attendee_extra_fields` meta (or the global settings), and one attendee
 * meta `etn_attendee_extra_field_<key>` per answer, where the key is the
 * label slug plus `_<id>`. File fields hold attachment ids and are left out.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventinCustomFields
{
    private SourceDatabase $db;

    private EventinSettings $settings;


    public function __construct(SourceDatabase $db, EventinSettings $settings)
    {
        $this->db       = $db;
        $this->settings = $settings;
    }


    /**
     * The meta-key suffix of a schema row: the label slug, plus `_<id>` when the row has an id.
     */
    public static function key(array $row): string
    {
        $slug = mb_strtolower(trim((string) ($row['label'] ?? '')));
        $slug = preg_replace('/[^a-z0-9 _]/', '', (string) preg_replace('/\p{Z}+/u', ' ', $slug));
        $slug = trim((string) preg_replace('/[ _]+/', '_', (string) $slug), '_');
        $id   = trim((string) ($row['id'] ?? ''));
        return $id !== '' ? $slug . '_' . $id : $slug;
    }


    /**
     * The meta keys of the file fields in a schema.
     *
     * @param array[] $schema Schema rows.
     * @return string[]
     */
    public static function fileKeys(array $schema): array
    {
        $keys = [];
        foreach ($schema as $row) {
            if (is_array($row) && ($row['field_type'] ?? '') === 'file') {
                $keys[] = self::key($row);
            }
        }
        return $keys;
    }


    /**
     * The definitions of every field in the global settings and in any event's schema, file fields left out.
     *
     * @return SourceCustomFieldRecord[]
     * @throws MigrationRecordException When a read fails.
     */
    public function definitions(): array
    {
        $schemas = [$this->settings->extraFields()];
        $rows    = $this->db->rows(
            'SELECT m.meta_value FROM ' . $this->db->table('postmeta') . ' m JOIN ' . $this->db->table('posts')
            . " p ON p.ID = m.post_id WHERE m.meta_key = 'attendee_extra_fields' AND p.post_type = %s"
            . ' AND p.post_status NOT IN (' . SourceDatabase::placeholders(EventinSchema::EXCLUDED_POST_STATUSES, '%s') . ')'
            . ' ORDER BY p.ID',
            array_merge([EventinSchema::POST_TYPE_EVENT], EventinSchema::EXCLUDED_POST_STATUSES)
        );
        foreach ($rows as $row) {
            $schema = SourceDatabase::safeUnserialize((string) $row['meta_value']);
            if (is_array($schema)) {
                $schemas[] = $schema;
            }
        }
        $fields = [];
        foreach ($schemas as $schema) {
            foreach ($schema as $row) {
                $key = is_array($row) ? self::key($row) : '';
                if ($key === '' || isset($fields[ $key ]) || ($row['field_type'] ?? '') === 'file') {
                    continue;
                }
                $fields[ $key ] = new SourceCustomFieldRecord(
                    $key,
                    trim(wp_strip_all_tags((string) ($row['label'] ?? ''))),
                    (string) ($row['field_type'] ?? 'text'),
                    ['required' => ! empty($row['required']), 'options' => $this->options((array) ($row['options'] ?? []))]
                );
            }
        }
        return array_values($fields);
    }


    /**
     * The answers in an attendee's meta (key => value), in key order, blank answers and the given keys skipped.
     *
     * @param array    $meta      Attendee meta, meta key => value.
     * @param string[] $skip_keys Field keys (suffixes) to leave out.
     * @return SourceAnswerRecord[]
     */
    public static function answers(array $meta, array $skip_keys): array
    {
        $answers = [];
        foreach ($meta as $meta_key => $raw) {
            $key = substr((string) $meta_key, strlen(EventinSchema::ANSWER_META_PREFIX));
            if (strpos((string) $meta_key, EventinSchema::ANSWER_META_PREFIX) !== 0 || in_array($key, $skip_keys, true)) {
                continue;
            }
            $values = [];
            foreach (is_array($raw) ? $raw : [$raw] as $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $values[] = trim((string) $value);
                }
            }
            if ($values !== []) {
                $answers[] = new SourceAnswerRecord($key, $values);
            }
        }
        return $answers;
    }


    /**
     * @param array $options A schema row's options: strings, or rows with a label.
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
// End of file EventinCustomFields.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventin/EventinCustomFields.php
