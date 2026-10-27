<?php

namespace EventEspresso\EventMigrator\domain\services\migration\rollback;

use EE_Error;
use ReflectionException;

/**
 * Class RollbackPreflight
 *
 * Checks one chunk of a run's records before anything is deleted (REQ-010):
 * a record whose canonical fingerprint differs from the stored
 * `ee_migration_fp` was modified after the run, and a record that something
 * outside the run points at (a registration on a run event or ticket or
 * contact, a payment on a run transaction, an event at a run venue) has
 * dependents. Either refuses the whole rollback. A record that no longer
 * exists is not a refusal; there is nothing left to delete.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class RollbackPreflight
{
    public const CHUNK = 200;

    public const REASON_MODIFIED = 'modified';

    public const REASON_DEPENDENTS = 'dependents';

    private RunEntityIndex $index;

    private RunQueries $queries;


    public function __construct(RunEntityIndex $index, RunQueries $queries)
    {
        $this->index   = $index;
        $this->queries = $queries;
    }


    /**
     * @return array{checked: int, refusals: array<int, array{model: string, id: int, reason: string}>}
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function checkChunk(string $run_id, string $model, int $offset, int $limit = self::CHUNK): array
    {
        $entries = $this->index->entries($run_id, $model, $offset, $limit);
        $ids     = array_map(static fn(array $entry): int => (int) $entry['id'], $entries);
        $current = $this->queries->currentFingerprints($model, $ids);
        $foreign = $this->queries->foreignDependents($model, array_keys($current), $run_id);
        $refused = [];
        foreach ($entries as $entry) {
            $id = (int) $entry['id'];
            if (! isset($current[ $id ])) {
                continue;
            }
            if ($entry['fp'] === '' || ! hash_equals((string) $entry['fp'], $current[ $id ])) {
                $refused[] = ['model' => $model, 'id' => $id, 'reason' => self::REASON_MODIFIED];
            } elseif (isset($foreign[ $id ])) {
                $refused[] = [
                    'model'  => $model,
                    'id'     => $id,
                    'reason' => self::REASON_DEPENDENTS . ':' . $foreign[ $id ],
                ];
            }
        }
        return ['checked' => count($entries), 'refusals' => $refused];
    }
}
// End of file RollbackPreflight.php
// Location: EventEspresso\EventMigrator\domain\services\migration\rollback/RollbackPreflight.php
