<?php

namespace EventEspresso\EventMigrator\domain\services\migration\rollback;

use EE_Base_Class;
use EE_Default_Where_Conditions;
use EE_Error;
use EE_Registry;
use EEM_Base;
use EEM_Extra_Meta;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use ReflectionException;
use RuntimeException;

/**
 * Class RunQueries
 *
 * The database reads a rollback pre-flight makes per chunk: the current
 * fingerprints of a chunk's records (one query) and the records outside the
 * run that still point at them (one query for the dependents, one for which
 * of those belong to the run). Kept apart so the pre-flight's rules can be
 * tested without EE tables.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class RunQueries
{
    /**
     * Model => [dependent model, foreign key on the dependent, dependent primary key] for the dependents
     * that must not outlive a rollback (PRD REQ-010).
     */
    public const DEPENDENTS = [
        CrosswalkKeys::MODEL_EVENT       => [CrosswalkKeys::MODEL_REGISTRATION, 'EVT_ID', 'REG_ID'],
        CrosswalkKeys::MODEL_TICKET      => [CrosswalkKeys::MODEL_REGISTRATION, 'TKT_ID', 'REG_ID'],
        CrosswalkKeys::MODEL_ATTENDEE    => [CrosswalkKeys::MODEL_REGISTRATION, 'ATT_ID', 'REG_ID'],
        CrosswalkKeys::MODEL_TRANSACTION => [CrosswalkKeys::MODEL_PAYMENT, 'TXN_ID', 'PAY_ID'],
        CrosswalkKeys::MODEL_VENUE       => [CrosswalkKeys::MODEL_EVENT, 'VNU_ID', 'EVT_ID'],
    ];


    /**
     * The canonical fingerprint of each record that still exists.
     *
     * @param string $model_name The EE model name.
     * @param int[]  $ids        Ids of the run's records.
     * @return array<int, string> id => fingerprint
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function currentFingerprints(string $model_name, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $model        = $this->model($model_name);
        $fingerprints = [];
        if ($model_name === CrosswalkKeys::MODEL_EVENT) {
            // One query for the chunk's `_thumbnail_id` meta instead of one per event in fingerprintOf().
            update_meta_cache('post', $ids);
        }
        $objects      = $model->get_all(
            [
                [$model->primary_key_name() => ['IN', $ids]],
                'default_where_conditions' => EE_Default_Where_Conditions::NONE,
            ]
        );
        foreach ($objects as $object) {
            if ($object instanceof EE_Base_Class) {
                $fingerprints[ (int) $object->ID() ] = Crosswalk::fingerprintOf($object);
            }
        }
        return $fingerprints;
    }


    /**
     * The records among $ids that something outside the run points at.
     *
     * @param string $model_name The EE model name.
     * @param int[]  $ids        Ids of the run's records.
     * @param string $run_id     The run being rolled back.
     * @return array<int, string> id => dependent model name
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function foreignDependents(string $model_name, array $ids, string $run_id): array
    {
        if ($ids === [] || ! isset(self::DEPENDENTS[ $model_name ])) {
            return [];
        }
        [$dependent_name, $foreign_key, $dependent_key] = self::DEPENDENTS[ $model_name ];
        $dependent = $this->model($dependent_name);
        $rows      = $dependent->get_all_wpdb_results(
            [[$foreign_key => ['IN', $ids]]],
            ARRAY_A,
            [
                'parent' => [$dependent->field_settings_for($foreign_key)->get_qualified_column(), '%d'],
                'child'  => [$dependent->field_settings_for($dependent_key)->get_qualified_column(), '%d'],
            ]
        );
        if ($rows === []) {
            return [];
        }
        $in_run  = array_flip($this->runMembers($dependent_name, array_column($rows, 'child'), $run_id));
        $blocked = [];
        foreach ($rows as $row) {
            if (! isset($in_run[ (int) $row['child'] ])) {
                $blocked[ (int) $row['parent'] ] = $dependent_name;
            }
        }
        return $blocked;
    }


    /**
     * @param string            $model_name The EE model name.
     * @param array<int|string> $ids        Candidate ids.
     * @param string            $run_id     The run being rolled back.
     * @return int[] the ids the run created
     * @throws EE_Error
     * @throws ReflectionException
     */
    private function runMembers(string $model_name, array $ids, string $run_id): array
    {
        return array_map(
            'intval',
            EEM_Extra_Meta::instance()->get_col(
                [
                    [
                        'EXM_key'   => CrosswalkKeys::RUN,
                        'EXM_type'  => $model_name,
                        'EXM_value' => $run_id,
                        'OBJ_ID'    => ['IN', array_map('intval', $ids)],
                    ],
                ],
                'OBJ_ID'
            )
        );
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
                    esc_html__('Unknown Event Espresso model "%s" in migration rollback.', 'migrate-events-to-event-espresso'),
                    esc_html($model_name)
                )
            );
        }
        return $model;
    }
}
// End of file RunQueries.php
// Location: EventEspresso\EventMigrator\domain\services\migration\rollback/RunQueries.php
