<?php

namespace EventEspresso\EventMigrator\domain\services\migration\rollback;

use EE_Error;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use ReflectionException;

/**
 * Class RunEntityIndex
 *
 * What a run created, read from the `ee_migration_run` extra meta: a count
 * per model in deletion order (CrosswalkKeys::models()), and pages of a
 * model's ids and stored fingerprints. Hard deletes cascade the extra meta,
 * so after a partial rollback the index lists exactly what is left.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class RunEntityIndex
{
    private Crosswalk $crosswalk;


    public function __construct(Crosswalk $crosswalk)
    {
        $this->crosswalk = $crosswalk;
    }


    /**
     * @return array<string, int> model => records the run created, in deletion order, empty models omitted
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function forRun(string $run_id): array
    {
        $index = [];
        foreach (CrosswalkKeys::models() as $model) {
            $count = $this->crosswalk->countForRun($run_id, $model);
            if ($count > 0) {
                $index[ $model ] = $count;
            }
        }
        return $index;
    }


    /**
     * @return array<int, array{id: int, src_key: string, fp: string}>
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function entries(string $run_id, string $model, int $offset, int $limit): array
    {
        return $this->crosswalk->entriesForRun($run_id, $model, $offset, $limit);
    }


    /**
     * @return int[]
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function ids(string $run_id, string $model, int $offset, int $limit): array
    {
        return array_map(
            static fn(array $entry): int => (int) $entry['id'],
            $this->entries($run_id, $model, $offset, $limit)
        );
    }
}
// End of file RunEntityIndex.php
// Location: EventEspresso\EventMigrator\domain\services\migration\rollback/RunEntityIndex.php
