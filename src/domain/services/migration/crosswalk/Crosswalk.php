<?php

namespace EventEspresso\EventMigrator\domain\services\migration\crosswalk;

use DateTimeInterface;
use DateTimeZone;
use EE_Base_Class;
use EE_CPT_Base;
use EE_Datetime_Field;
use EE_Default_Where_Conditions;
use EE_Error;
use EE_Registry;
use EEM_Base;
use EEM_CPT_Base;
use EEM_Extra_Meta;
use EEM_Payment;
use EEM_Registration;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use ReflectionException;
use RuntimeException;

/**
 * Class Crosswalk
 *
 * Writes and reads the extra-meta keys that link every migrated EE record to
 * its source record and run (REQ-008). Lookups go through the run's
 * in-memory map first: prefetch() answers a whole chunk of source keys with
 * one EXM_value scan per model, because esp_extra_meta is indexed on
 * (EXM_type, OBJ_ID, EXM_key) and not on EXM_value.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class Crosswalk
{
    /**
     * "<source>:<type>:<source_id>" - the EXM_value stored under CrosswalkKeys::SRC.
     */
    public static function sourceKey(string $source, string $type, string $id): string
    {
        return $source . ':' . $type . ':' . $id;
    }


    /**
     * sha1 of the canonical JSON of the fingerprinted fields. Values are
     * normalised first (dates to UTC "Y-m-d H:i:s", numbers to a fixed
     * decimal form, booleans to 1/0) so a hash computed from the values the
     * persister wrote equals one recomputed from the values the model reads back.
     *
     * @param array  $fields Field name => value.
     * @param string $model  When given, only FingerprintFields::fieldsFor($model) are hashed.
     */
    public static function fingerprint(array $fields, string $model = ''): string
    {
        if ($model !== '') {
            $fields = array_intersect_key($fields, array_flip(FingerprintFields::fieldsFor($model)));
        }
        $normalised = [];
        foreach ($fields as $name => $value) {
            $normalised[ (string) $name ] = self::normaliseValue($value);
        }
        ksort($normalised, SORT_STRING);
        return sha1((string) wp_json_encode($normalised));
    }


    /**
     * The fingerprint of a saved record, read the same way when the run writes it and when rollback checks
     * it: date fields as their UTC DateTime, every other field as its raw value (never the site-formatted
     * get() output, which changes with the date-format settings).
     */
    public static function fingerprintOf(EE_Base_Class $object): string
    {
        $model  = $object->get_model();
        $fields = [];
        foreach (FingerprintFields::fieldsFor($model->get_this_model_name()) as $field_name) {
            if (! $model->has_field($field_name)) {
                continue;
            }
            $fields[ $field_name ] = $model->field_settings_for($field_name) instanceof EE_Datetime_Field
                ? $object->get_DateTime_object($field_name)
                : $object->get_raw($field_name);
        }
        // Relation-backed values are read from the database (never from the object's relation cache) so the
        // run and the rollback pre-flight see the same thing.
        foreach (FingerprintFields::relationsFor($model->get_this_model_name()) as $relation) {
            if ($relation === FingerprintFields::RELATION_FEATURED_IMAGE) {
                $fields[ $relation ] = self::featuredImageOf($object);
            } elseif ($relation === FingerprintFields::RELATION_CATEGORIES && $object instanceof EE_CPT_Base) {
                $fields[ $relation ] = self::categoryIdsOf($object);
            }
        }
        return self::fingerprint($fields);
    }


    /**
     * The attachment id in `_thumbnail_id`, 0 when the record has no featured image.
     */
    private static function featuredImageOf(EE_Base_Class $object): int
    {
        return (int) get_post_meta((int) $object->ID(), '_thumbnail_id', true);
    }


    /**
     * The sorted term-taxonomy ids of the record's event categories. Query parameters make
     * get_many_related() read the database instead of the object's cache.
     *
     * @return int[]
     * @throws EE_Error
     * @throws ReflectionException
     */
    private static function categoryIdsOf(EE_CPT_Base $object): array
    {
        $ids = array_map(
            'intval',
            array_keys($object->get_many_related('Term_Taxonomy', [['taxonomy' => EEM_CPT_Base::EVENT_CATEGORY_TAXONOMY]]))
        );
        sort($ids);
        return $ids;
    }


    /**
     * Canonical scalar or array form of one fingerprinted value.
     */
    private static function normaliseValue($value)
    {
        if ($value instanceof DateTimeInterface) {
            return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if (is_array($value)) {
            $normalised = [];
            foreach ($value as $key => $item) {
                $normalised[ (string) $key ] = self::normaliseValue($item);
            }
            ksort($normalised, SORT_STRING);
            return $normalised;
        }
        if ($value === null) {
            return '';
        }
        // Booleans take the numeric form so a bool written by the persister equals the "1"/"0" the model reads back.
        if (is_bool($value) || is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return number_format((float) $value, 6, '.', '');
        }
        return (string) $value;
    }


    /**
     * Writes the crosswalk keys on a record the run just created and proves
     * the SRC key persisted with one count query, because add_extra_meta()
     * returns true after any insert attempt.
     *
     * @throws RuntimeException When the SRC key cannot be read back.
     */
    public function record(
        EE_Base_Class $object,
        string $source,
        string $type,
        string $id,
        string $run_id,
        string $fingerprint,
        MigrationContext $context,
        ?int $wp_user = null
    ): void {
        $src_key = self::sourceKey($source, $type, $id);
        $model   = $object->get_model()->get_this_model_name();
        $object->add_extra_meta(CrosswalkKeys::SRC, $src_key, true);
        $object->add_extra_meta(CrosswalkKeys::RUN, $run_id, true);
        $object->add_extra_meta(CrosswalkKeys::FP, $fingerprint, true);
        if ($wp_user !== null && $wp_user > 0) {
            $object->add_extra_meta(CrosswalkKeys::WP_USER, $wp_user, true);
        }
        $persisted = EEM_Extra_Meta::instance()->count([
            [
                'EXM_key'   => CrosswalkKeys::SRC,
                'EXM_value' => $src_key,
                'OBJ_ID'    => $object->ID(),
                'EXM_type'  => $model,
            ],
        ]) > 0;
        if (! $persisted) {
            throw new RuntimeException(
                sprintf(
                    /* translators: %1$s: EE model name, %2$s: source key */
                    esc_html__('The migration crosswalk for %1$s "%2$s" could not be saved.', 'migrate-events-to-event-espresso'),
                    esc_html($model),
                    esc_html($src_key)
                )
            );
        }
        $context->remember($src_key, $model, (int) $object->ID());
    }


    /**
     * Links one more source key to a record this run created (another occurrence of a collapsed series, or
     * the series itself), so findTarget() and prefetch() resolve it to the same record.
     */
    public function recordAlias(
        EE_Base_Class $object,
        string $source,
        string $type,
        string $id,
        MigrationContext $context
    ): void {
        $src_key = self::sourceKey($source, $type, $id);
        $object->add_extra_meta(CrosswalkKeys::ALIAS, $src_key);
        $context->remember($src_key, $object->get_model()->get_this_model_name(), (int) $object->ID());
    }


    /**
     * Notes that a pre-existing record was reused for a source record. Nothing
     * is written on the record (so rollback never deletes it); the run map
     * remembers it so later lookups in this run resolve without a query.
     */
    public function recordReuse(
        EE_Base_Class $object,
        string $source,
        string $type,
        string $id,
        MigrationContext $context
    ): void {
        $this->recordReuseById(
            $object->get_model()->get_this_model_name(),
            (int) $object->ID(),
            $source,
            $type,
            $id,
            $context
        );
    }


    /**
     * The same as recordReuse() for callers that hold only the EE record's id.
     */
    public function recordReuseById(
        string $model,
        int $object_id,
        string $source,
        string $type,
        string $id,
        MigrationContext $context
    ): void {
        $src_key = self::sourceKey($source, $type, $id);
        $context->noteReuse($model, $object_id, $src_key);
        $context->remember($src_key, $model, $object_id);
    }


    /**
     * Loads the crosswalk entries for a chunk of source ids into the run map
     * with one query, marking the ids with no entry as known-absent.
     *
     * @param string           $source     The source adapter slug.
     * @param string           $type       The source entity type.
     * @param string[]         $ids        The source ids of the chunk.
     * @param string           $model_name The EE model name the records were created as.
     * @param MigrationContext $context    The run whose map receives the results.
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function prefetch(
        string $source,
        string $type,
        array $ids,
        string $model_name,
        MigrationContext $context
    ): void {
        $keys = [];
        foreach ($ids as $id) {
            $src_key = self::sourceKey($source, $type, (string) $id);
            if (! $context->hasLookup($src_key, $model_name)) {
                $keys[] = $src_key;
            }
        }
        if ($keys === []) {
            return;
        }
        $rows = EEM_Extra_Meta::instance()->get_all([
            [
                'EXM_key'   => ['IN', [CrosswalkKeys::SRC, CrosswalkKeys::ALIAS]],
                'EXM_type'  => $model_name,
                'EXM_value' => ['IN', $keys],
            ],
        ]);
        foreach ($rows as $row) {
            if ($row instanceof EE_Base_Class) {
                $context->remember((string) $row->get('EXM_value'), $model_name, (int) $row->get('OBJ_ID'));
            }
        }
        foreach ($keys as $src_key) {
            $context->rememberAbsent($src_key, $model_name);
        }
    }


    /**
     * The EE record a source record was migrated to, or null when it was never
     * migrated. When the crosswalk entry exists but the EE record is gone (or a post is in the trash), the
     * source key is noted as target-deleted on the context, a target-deleted
     * warning is added, and null is returned (PRD OQ-10: never re-created).
     *
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function findTarget(
        string $source,
        string $type,
        string $id,
        string $model_name,
        MigrationContext $context
    ): ?EE_Base_Class {
        $src_key = self::sourceKey($source, $type, $id);
        if (! $context->hasLookup($src_key, $model_name)) {
            $row = EEM_Extra_Meta::instance()->get_one([
                [
                    'EXM_key'   => ['IN', [CrosswalkKeys::SRC, CrosswalkKeys::ALIAS]],
                    'EXM_type'  => $model_name,
                    'EXM_value' => $src_key,
                ],
            ]);
            if ($row instanceof EE_Base_Class) {
                $context->remember($src_key, $model_name, (int) $row->get('OBJ_ID'));
            } else {
                $context->rememberAbsent($src_key, $model_name);
            }
        }
        $object_id = $context->lookup($src_key, $model_name);
        if ($object_id === null) {
            return null;
        }
        $target = $this->model($model_name)->get_one_by_ID($object_id);
        // A trashed post (event, venue, contact) counts as deleted: it is reported, never reused or re-created.
        if ($target instanceof EE_Base_Class && ! ($target instanceof EE_CPT_Base && $target->status() === 'trash')) {
            return $target;
        }
        if (! $context->isTargetDeleted($src_key)) {
            $context->noteTargetDeleted($src_key);
            $context->addWarning(new MigrationWarning(WarningRule::TARGET_DELETED, $type, $id));
        }
        return null;
    }


    /**
     * The id of the EE record a source record was migrated to, or null. A negative id remembered on the
     * context is a dry run's synthetic id and is returned as-is, since there is no row to load.
     *
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function targetId(
        string $source,
        string $type,
        string $id,
        string $model_name,
        MigrationContext $context
    ): ?int {
        $remembered = $context->lookup(self::sourceKey($source, $type, $id), $model_name);
        if ($remembered !== null && $remembered < 0) {
            return $remembered;
        }
        $target = $this->findTarget($source, $type, $id, $model_name, $context);
        return $target instanceof EE_Base_Class ? (int) $target->ID() : null;
    }


    /**
     * Whether an earlier run migrated the source record but its Event Espresso record is gone (deleted or
     * trashed). Answered from the run context, so it is only meaningful after targetId() or findTarget() looked
     * the key up; such a record is reported and never re-created (PRD OQ-10).
     */
    public function isTargetDeleted(string $source, string $type, string $id, MigrationContext $context): bool
    {
        return $context->isTargetDeleted(self::sourceKey($source, $type, $id));
    }


    /**
     * The crosswalk entries a run created for one model, in OBJ_ID order, as
     * [['id' => OBJ_ID, 'src_key' => string, 'fp' => string], ...].
     *
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function entriesForRun(string $run_id, string $model_name, int $offset, int $limit): array
    {
        $run_rows = EEM_Extra_Meta::instance()->get_all([
            [
                'EXM_key'   => CrosswalkKeys::RUN,
                'EXM_type'  => $model_name,
                'EXM_value' => $run_id,
            ],
            'order_by' => ['OBJ_ID' => 'ASC'],
            'limit'    => [$offset, $limit],
        ]);
        $entries  = [];
        foreach ($run_rows as $row) {
            if ($row instanceof EE_Base_Class) {
                $entries[ (int) $row->get('OBJ_ID') ] = ['id' => (int) $row->get('OBJ_ID'), 'src_key' => '', 'fp' => ''];
            }
        }
        if ($entries === []) {
            return [];
        }
        $detail_rows = EEM_Extra_Meta::instance()->get_all([
            [
                'EXM_key'  => ['IN', [CrosswalkKeys::SRC, CrosswalkKeys::FP]],
                'EXM_type' => $model_name,
                'OBJ_ID'   => ['IN', array_keys($entries)],
            ],
        ]);
        foreach ($detail_rows as $row) {
            if (! $row instanceof EE_Base_Class) {
                continue;
            }
            $object_id = (int) $row->get('OBJ_ID');
            $field     = $row->get('EXM_key') === CrosswalkKeys::SRC ? 'src_key' : 'fp';
            if (isset($entries[ $object_id ])) {
                $entries[ $object_id ][ $field ] = (string) $row->get('EXM_value');
            }
        }
        return array_values($entries);
    }


    /**
     * How many records of one model a run created.
     *
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function countForRun(string $run_id, string $model_name): int
    {
        return (int) EEM_Extra_Meta::instance()->count([
            [
                'EXM_key'   => CrosswalkKeys::RUN,
                'EXM_type'  => $model_name,
                'EXM_value' => $run_id,
            ],
        ]);
    }


    /**
     * Registrations a run created, per Event Espresso event: one grouped query.
     *
     * @return array<int, int> event id => registrations
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function registrationsPerEventForRun(string $run_id): array
    {
        $rows   = EEM_Registration::instance()->get_all_wpdb_results(
            [
                [
                    'Extra_Meta.EXM_key'   => CrosswalkKeys::RUN,
                    'Extra_Meta.EXM_value' => $run_id,
                ],
                'group_by'                 => 'EVT_ID',
                'default_where_conditions' => EE_Default_Where_Conditions::NONE,
            ],
            ARRAY_A,
            [
                'event' => ['Registration.EVT_ID', '%d'],
                'count' => ['COUNT(Registration.REG_ID)', '%d'],
            ]
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[ (int) $row['event'] ] = (int) $row['count'];
        }
        return $counts;
    }


    /**
     * The total of the approved payments (refunds negative) a run created: one SUM query.
     *
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function approvedPaymentsForRun(string $run_id): float
    {
        return (float) EEM_Payment::instance()->sum(
            [
                [
                    'Extra_Meta.EXM_key'   => CrosswalkKeys::RUN,
                    'Extra_Meta.EXM_value' => $run_id,
                    'STS_ID'               => EEM_Payment::status_id_approved,
                ],
                'default_where_conditions' => EE_Default_Where_Conditions::NONE,
            ],
            'PAY_amount'
        );
    }


    /**
     * Deletes the crosswalk keys from a record. Only needed when a persist is
     * compensated before the record is hard-deleted; hard deletes cascade.
     *
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function remove(EE_Base_Class $object): void
    {
        foreach (CrosswalkKeys::all() as $key) {
            $object->delete_extra_meta($key);
        }
    }


    /**
     * @throws RuntimeException When the model name is unknown.
     */
    private function model(string $model_name): EEM_Base
    {
        $model = EE_Registry::instance()->load_model($model_name);
        if (! $model instanceof EEM_Base) {
            throw new RuntimeException(
                sprintf(
                    /* translators: %s: EE model name */
                    esc_html__('Unknown Event Espresso model "%s" in migration crosswalk.', 'migrate-events-to-event-espresso'),
                    esc_html($model_name)
                )
            );
        }
        return $model;
    }
}
// End of file Crosswalk.php
// Location: EventEspresso\EventMigrator\domain\services\migration\crosswalk/Crosswalk.php
