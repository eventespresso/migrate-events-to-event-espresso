<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventprime;

use EventEspresso\EventMigrator\application\services\migration\records\SourceCustomFieldRecord;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventPrimeCustomFields
 *
 * EventPrime's checkout fields (research profile §3): the definitions in
 * `eventprime_checkout_fields` (text, email, tel, date, number; the paid
 * Advanced Checkout Fields extension adds more), answered in the booking's
 * `em_booking_fields_data` keyed by field id. Fields of a type with no text
 * answer are left out.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventPrimeCustomFields
{
    /**
     * EventPrime field type => the question type the engine understands.
     */
    private const TYPES = [
        'text'     => 'text',
        'tel'      => 'text',
        'email'    => 'email',
        'date'     => 'date',
        'number'   => 'number',
        'textarea' => 'textarea',
        'select'   => 'select',
        'dropdown' => 'select',
        'radio'    => 'radio',
        'checkbox' => 'checkbox',
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
        $fields = [];
        $rows   = $this->db->rows('SELECT * FROM ' . $this->db->table(EventPrimeSchema::CHECKOUT_FIELDS) . ' WHERE status = 1 ORDER BY priority, id');
        foreach ($rows as $row) {
            $type = self::TYPES[ strtolower((string) $row['type']) ] ?? '';
            if ($type === '') {
                continue;
            }
            $stored   = SourceDatabase::safeUnserialize((string) $row['option_data']);
            $options  = [];
            foreach (is_array($stored) ? ($stored['options'] ?? $stored) : [] as $option) {
                if (is_scalar($option) && trim((string) $option) !== '') {
                    $options[] = trim((string) $option);
                }
            }
            $fields[] = new SourceCustomFieldRecord(
                (string) $row['id'],
                trim(wp_strip_all_tags((string) $row['label'])),
                $type,
                ['options' => in_array($type, ['select', 'radio', 'checkbox'], true) ? $options : []]
            );
        }
        return $fields;
    }
}
// End of file EventPrimeCustomFields.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventprime/EventPrimeCustomFields.php
