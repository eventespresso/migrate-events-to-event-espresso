<?php

namespace EventEspresso\EventMigrator\domain\services\migration\run;

/**
 * Class MigrationRunStore
 *
 * Persists run records as one non-autoloaded WP option per run plus an index
 * option listing every run. Records are retained, also after a rollback
 * (REQ-022); there is no pruning and no change to the CSV importer's
 * diagnostics janitor.
 *
 * OPTION_PREFIX, INDEX_OPTION and the run id format are persisted in the options table: never change them.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationRunStore
{
    public const OPTION_PREFIX = 'ee_event_migrator_run_';

    public const INDEX_OPTION = 'ee_event_migrator_runs';

    public const RUN_ID_PATTERN = '/^[a-f0-9]{16}$/D';

    private MigrationLog $log;


    public function __construct(MigrationLog $log)
    {
        $this->log = $log;
    }


    /**
     * Unguessability, not cryptographic strength, is all a run id needs.
     */
    public static function generateRunId(): string
    {
        return substr(md5(uniqid((string) wp_rand(), true)), 0, 16);
    }


    /**
     * Whether a value is a run id: 16 lower-case hex characters (the only form accepted from a request).
     *
     * @since 0.0.1.000
     */
    public static function isValidRunId(string $run_id): bool
    {
        return preg_match(self::RUN_ID_PATTERN, $run_id) === 1;
    }


    /**
     * Writes the record and its index entry. False when the run id is malformed.
     */
    public function save(MigrationRunRecord $record): bool
    {
        $run_id = $record->runId();
        if (! self::isValidRunId($run_id)) {
            return false;
        }
        update_option(self::OPTION_PREFIX . $run_id, (string) wp_json_encode($record->toArray()), false);
        $index            = $this->index();
        $index[ $run_id ] = [
            'run_id'      => $run_id,
            'source'      => $record->source(),
            'mode'        => $record->mode(),
            'status'      => $record->status(),
            'started_at'  => $record->startedAt(),
            'finished_at' => $record->finishedAt(),
        ];
        return update_option(self::INDEX_OPTION, (string) wp_json_encode($index), false) || true;
    }


    /**
     * The stored run record, or null for a malformed id or an unknown run.
     *
     * @since 0.0.1.000
     */
    public function load(string $run_id): ?MigrationRunRecord
    {
        if (! self::isValidRunId($run_id)) {
            return null;
        }
        $raw = get_option(self::OPTION_PREFIX . $run_id, null);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? MigrationRunRecord::fromArray($data) : null;
    }


    /**
     * Index entries for every retained run, newest first.
     *
     * @return array<int, array{run_id: string, source: string, mode: string, status: string, started_at: int, finished_at: int}>
     */
    public function list(): array
    {
        $entries = array_values($this->index());
        usort(
            $entries,
            static fn(array $a, array $b): int => ($b['started_at'] <=> $a['started_at'])
                ?: strcmp((string) $b['run_id'], (string) $a['run_id'])
        );
        return $entries;
    }


    /**
     * Updates a run's status on both the record and the index.
     */
    public function markStatus(string $run_id, string $status): bool
    {
        $record = $this->load($run_id);
        if (! $record instanceof MigrationRunRecord) {
            return false;
        }
        $record->setStatus($status);
        if ($record->status() !== $status) {
            return false;
        }
        return $this->save($record);
    }


    /**
     * Removes the record, its index entry and its log file. Not called today: a rollback keeps its run record
     * (status rolled_back) and no run is pruned; reserved for a future "delete run" action.
     */
    public function delete(string $run_id): bool
    {
        if (! self::isValidRunId($run_id)) {
            return false;
        }
        $record = $this->load($run_id);
        if ($record instanceof MigrationRunRecord && $record->logSecret() !== '') {
            $this->log->delete($record->logSecret());
        }
        delete_option(self::OPTION_PREFIX . $run_id);
        $index = $this->index();
        unset($index[ $run_id ]);
        update_option(self::INDEX_OPTION, (string) wp_json_encode($index), false);
        return get_option(self::OPTION_PREFIX . $run_id, null) === null;
    }


    /**
     * @return array<string, array> run id => index entry
     */
    private function index(): array
    {
        $raw   = get_option(self::INDEX_OPTION, '');
        $index = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (! is_array($index)) {
            return [];
        }
        $clean = [];
        foreach ($index as $run_id => $entry) {
            $run_id = (string) $run_id;
            if (! self::isValidRunId($run_id) || ! is_array($entry)) {
                continue;
            }
            $clean[ $run_id ] = [
                'run_id'      => $run_id,
                'source'      => (string) ($entry['source'] ?? ''),
                'mode'        => (string) ($entry['mode'] ?? ''),
                'status'      => (string) ($entry['status'] ?? ''),
                'started_at'  => (int) ($entry['started_at'] ?? 0),
                'finished_at' => (int) ($entry['finished_at'] ?? 0),
            ];
        }
        return $clean;
    }
}
// End of file MigrationRunStore.php
// Location: EventEspresso\EventMigrator\domain\services\migration\run/MigrationRunStore.php
