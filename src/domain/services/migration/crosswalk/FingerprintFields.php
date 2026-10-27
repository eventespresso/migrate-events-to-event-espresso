<?php

namespace EventEspresso\EventMigrator\domain\services\migration\crosswalk;

/**
 * Class FingerprintFields
 *
 * The per-model list of field names the migration writes and therefore
 * fingerprints. Crosswalk::fingerprint() hashes exactly these fields when a
 * record is created, and RollbackPreflight recomputes the same hash from the
 * stored record, so the two sides can never drift apart (REQ-010).
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class FingerprintFields
{
    /**
     * @var array<string, string[]>
     */
    private const FIELDS = [
        CrosswalkKeys::MODEL_EVENT          => [
            'EVT_name',
            'EVT_desc',
            'EVT_short_desc',
            'status',
            'EVT_wp_user',
            'EVT_default_registration_status',
            'VNU_ID',
        ],
        CrosswalkKeys::MODEL_DATETIME       => [
            'DTT_name',
            'DTT_description',
            'DTT_EVT_start',
            'DTT_EVT_end',
            'DTT_reg_limit',
        ],
        CrosswalkKeys::MODEL_TICKET         => [
            'TKT_name',
            'TKT_description',
            'TKT_price',
            'TKT_qty',
            'TKT_min',
            'TKT_max',
            'TKT_start_date',
            'TKT_end_date',
            'TKT_required',
        ],
        CrosswalkKeys::MODEL_PRICE          => [
            'PRT_ID',
            'PRC_name',
            'PRC_amount',
        ],
        CrosswalkKeys::MODEL_VENUE          => [
            'VNU_name',
            'VNU_desc',
            'VNU_short_desc',
            'VNU_address',
            'VNU_address2',
            'VNU_city',
            'VNU_zip',
            'VNU_phone',
            'VNU_url',
            'VNU_virtual_url',
            'VNU_google_map_link',
            'STA_ID',
            'CNT_ISO',
        ],
        CrosswalkKeys::MODEL_ATTENDEE       => [
            'ATT_fname',
            'ATT_lname',
            'ATT_email',
            'ATT_phone',
            'ATT_address',
            'ATT_address2',
            'ATT_city',
            'ATT_zip',
            'STA_ID',
            'CNT_ISO',
        ],
        CrosswalkKeys::MODEL_TRANSACTION    => [
            'TXN_timestamp',
            'TXN_total',
            'TXN_paid',
            'STS_ID',
        ],
        CrosswalkKeys::MODEL_REGISTRATION   => [
            'REG_code',
            'REG_date',
            'REG_final_price',
            'REG_count',
            'REG_group_size',
            'STS_ID',
            'TKT_ID',
            'ATT_ID',
        ],
        CrosswalkKeys::MODEL_PAYMENT        => [
            'PAY_amount',
            'PAY_timestamp',
            'STS_ID',
            'PAY_source',
            'PAY_gateway_response',
            'PAY_txn_id_chq_nmbr',
            'PMD_ID',
        ],
        CrosswalkKeys::MODEL_ANSWER         => [
            'QST_ID',
            'ANS_value',
        ],
        CrosswalkKeys::MODEL_CHECKIN        => [
            'DTT_ID',
            'CHK_in',
            'CHK_timestamp',
        ],
        CrosswalkKeys::MODEL_TERM_TAXONOMY  => [
            'taxonomy',
            'description',
            'term_id',
        ],
        CrosswalkKeys::MODEL_QUESTION       => [
            'QST_display_text',
            'QST_admin_label',
            'QST_type',
            'QST_required',
        ],
        CrosswalkKeys::MODEL_QUESTION_GROUP => [
            'QSG_name',
            'QSG_identifier',
        ],
    ];


    /**
     * The event's featured image: the attachment id in `_thumbnail_id` post meta (0 when none).
     */
    public const RELATION_FEATURED_IMAGE = '_thumbnail_id';

    /**
     * The event's categories: the sorted term-taxonomy ids related through Term_Relationship.
     */
    public const RELATION_CATEGORIES = 'categories';

    /**
     * Values the migration writes through a relation or post meta after the row itself; they are
     * hashed together with the columns above so a later change to them is also "modified" (REQ-010).
     *
     * @var array<string, string[]>
     */
    private const RELATIONS = [
        CrosswalkKeys::MODEL_EVENT => [
            self::RELATION_FEATURED_IMAGE,
            self::RELATION_CATEGORIES,
        ],
    ];


    /**
     * The fingerprinted field names for a model, or an empty list for a model
     * the migration does not fingerprint.
     *
     * @return string[]
     */
    public static function fieldsFor(string $model): array
    {
        return self::FIELDS[ $model ] ?? [];
    }


    /**
     * The relation-backed value names fingerprinted for a model (Crosswalk::fingerprintOf() reads them
     * from the saved record), or an empty list.
     *
     * @return string[]
     */
    public static function relationsFor(string $model): array
    {
        return self::RELATIONS[ $model ] ?? [];
    }


    /**
     * @return string[] the fingerprinted model names
     */
    public static function models(): array
    {
        return array_keys(self::FIELDS);
    }
}
// End of file FingerprintFields.php
// Location: EventEspresso\EventMigrator\domain\services\migration\crosswalk/FingerprintFields.php
