<?php

namespace EventEspresso\EventMigrator\domain\services\migration\run;

use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig;

/**
 * Class MigrationRunRecord
 *
 * Everything the run report shows for one run (REQ-022): options, status,
 * per-entity counts, sold-count adjustments, a bounded warning sample with
 * per-rule totals, reconciliation totals, rollback refusals and the log
 * secret. Serialised to one WP option by MigrationRunStore.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationRunRecord
{
    public const STATUS_RUNNING          = 'running';

    public const STATUS_COMPLETED        = 'completed';

    public const STATUS_FAILED           = 'failed';

    public const STATUS_DRY_RUN          = 'dry_run';

    public const STATUS_ROLLED_BACK      = 'rolled_back';

    public const STATUS_ROLLBACK_REFUSED = 'rollback_refused';

    public const STATUS_ROLLBACK_PARTIAL = 'rollback_partial';

    public const COUNT_CREATED = 'created';

    public const COUNT_REUSED  = 'reused';

    public const COUNT_SKIPPED = 'skipped';

    public const COUNT_FAILED  = 'failed';

    /**
     * Warnings kept verbatim on the record; the full list lives in the log.
     */
    public const MAX_WARNING_SAMPLE = 200;

    /**
     * Reuse notes kept verbatim on the record; totals are in the "reused" counts.
     */
    public const MAX_REUSE_SAMPLE = 200;

    private string $run_id;

    private string $source;

    private string $mode;

    private array $options;

    private int $started_at;

    private int $finished_at = 0;

    private string $status = self::STATUS_RUNNING;

    /**
     * @var array<string, array<string, int>> entity => dimension => count
     */
    private array $counts = [];

    /**
     * @var array<int, int> ticket id => net sold-count change made by the run
     */
    private array $sold_adjustments = [];

    /**
     * @var array<int, array> MigrationWarning::toArray() shapes, at most MAX_WARNING_SAMPLE
     */
    private array $warnings_sample = [];

    /**
     * @var array<string, int> rule id => total occurrences
     */
    private array $warnings_by_rule = [];

    /**
     * @var array<int, array{model: string, id: int, src_key: string}> at most MAX_REUSE_SAMPLE
     */
    private array $reuse_sample = [];

    private array $reconciliation = [];

    /**
     * @var array<int, array{model: string, id: int, reason: string}>
     */
    private array $refusals = [];

    private string $log_secret;

    /**
     * @var array the batch job's resume state while the run is going (cursor, dry-run lookups), saved in the
     *            same option write as the counts it produced; emptied when the run finishes
     */
    private array $progress = [];

    private ReconciliationTally $tally;


    /**
     * @param string $run_id     The 16-hex run id.
     * @param string $source     Source adapter slug.
     * @param string $mode       A MigrationConfig::MODE_* value.
     * @param array  $options    The MigrationOptions::toArray() shape.
     * @param int    $started_at Unix timestamp.
     * @param string $log_secret The run's log filename secret, '' when no log was created.
     */
    public function __construct(
        string $run_id,
        string $source,
        string $mode,
        array $options,
        int $started_at,
        string $log_secret = ''
    ) {
        $this->run_id     = $run_id;
        $this->source     = $source;
        $this->mode       = MigrationConfig::isValidMode($mode) ? $mode : '';
        $this->options    = $options;
        $this->started_at = $started_at;
        $this->log_secret = $log_secret;
        $this->tally      = new ReconciliationTally();
    }


    /**
     * @return string[]
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_RUNNING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_DRY_RUN,
            self::STATUS_ROLLED_BACK,
            self::STATUS_ROLLBACK_REFUSED,
            self::STATUS_ROLLBACK_PARTIAL,
        ];
    }


    public function runId(): string
    {
        return $this->run_id;
    }


    public function source(): string
    {
        return $this->source;
    }


    public function mode(): string
    {
        return $this->mode;
    }


    public function options(): array
    {
        return $this->options;
    }


    public function startedAt(): int
    {
        return $this->started_at;
    }


    public function finishedAt(): int
    {
        return $this->finished_at;
    }


    public function setFinishedAt(int $finished_at): void
    {
        $this->finished_at = $finished_at;
    }


    public function status(): string
    {
        return $this->status;
    }


    /**
     * Unknown statuses are ignored so a forged value cannot be stored.
     */
    public function setStatus(string $status): void
    {
        if (in_array($status, self::statuses(), true)) {
            $this->status = $status;
        }
    }


    public function isRolledBack(): bool
    {
        return $this->status === self::STATUS_ROLLED_BACK;
    }


    /**
     * @return array<string, array<string, int>>
     */
    public function counts(): array
    {
        return $this->counts;
    }


    public function count(string $entity, string $dimension): int
    {
        return $this->counts[ $entity ][ $dimension ] ?? 0;
    }


    public function increment(string $entity, string $dimension, int $by = 1): void
    {
        $this->counts[ $entity ][ $dimension ] = $this->count($entity, $dimension) + $by;
    }


    /**
     * @return array<int, int>
     */
    public function soldAdjustments(): array
    {
        return $this->sold_adjustments;
    }


    public function addSoldAdjustment(int $ticket_id, int $delta): void
    {
        $this->sold_adjustments[ $ticket_id ] = ($this->sold_adjustments[ $ticket_id ] ?? 0) + $delta;
    }


    public function addWarning(MigrationWarning $warning): void
    {
        $this->warnings_by_rule[ $warning->rule() ] = ($this->warnings_by_rule[ $warning->rule() ] ?? 0) + 1;
        if (count($this->warnings_sample) < self::MAX_WARNING_SAMPLE) {
            $this->warnings_sample[] = $warning->toArray();
        }
    }


    /**
     * @return MigrationWarning[]
     */
    public function warningsSample(): array
    {
        return array_map([MigrationWarning::class, 'fromArray'], $this->warnings_sample);
    }


    /**
     * @return array<string, int>
     */
    public function warningsByRule(): array
    {
        return $this->warnings_by_rule;
    }


    public function warningsTotal(): int
    {
        return array_sum($this->warnings_by_rule);
    }


    /**
     * @param array{model: string, id: int, src_key: string} $note A reuse note from MigrationContext::reuseNotes().
     */
    public function addReuseNote(array $note): void
    {
        if (count($this->reuse_sample) < self::MAX_REUSE_SAMPLE) {
            $this->reuse_sample[] = [
                'model'   => (string) ($note['model'] ?? ''),
                'id'      => (int) ($note['id'] ?? 0),
                'src_key' => (string) ($note['src_key'] ?? ''),
            ];
        }
    }


    /**
     * @return array<int, array{model: string, id: int, src_key: string}>
     */
    public function reuseSample(): array
    {
        return $this->reuse_sample;
    }


    public function reconciliation(): array
    {
        return $this->reconciliation;
    }


    public function setReconciliation(array $reconciliation): void
    {
        $this->reconciliation = $reconciliation;
    }


    /**
     * @return array<int, array{model: string, id: int, reason: string}>
     */
    public function refusals(): array
    {
        return $this->refusals;
    }


    /**
     * @param array<int, array{model: string, id: int, reason: string}> $refusals The rollback pre-flight refusal list.
     */
    public function setRefusals(array $refusals): void
    {
        $this->refusals = array_values($refusals);
    }


    public function logSecret(): string
    {
        return $this->log_secret;
    }


    public function progress(): array
    {
        return $this->progress;
    }


    public function setProgress(array $progress): void
    {
        $this->progress = $progress;
    }


    /**
     * Source and migrated registrations and payments, collected as the bookings are migrated.
     */
    public function tally(): ReconciliationTally
    {
        return $this->tally;
    }


    public function toArray(): array
    {
        return [
            'run_id'           => $this->run_id,
            'source'           => $this->source,
            'mode'             => $this->mode,
            'options'          => $this->options,
            'started_at'       => $this->started_at,
            'finished_at'      => $this->finished_at,
            'status'           => $this->status,
            'counts'           => $this->counts,
            'sold_adjustments' => $this->sold_adjustments,
            'warnings_sample'  => $this->warnings_sample,
            'warnings_by_rule' => $this->warnings_by_rule,
            'reuse_sample'     => $this->reuse_sample,
            'reconciliation'   => $this->reconciliation,
            'refusals'         => $this->refusals,
            'log_secret'       => $this->log_secret,
            'progress'         => $this->progress,
            'tally'            => $this->tally->toArray(),
        ];
    }


    public static function fromArray(array $data): MigrationRunRecord
    {
        $record = new MigrationRunRecord(
            (string) ($data['run_id'] ?? ''),
            (string) ($data['source'] ?? ''),
            (string) ($data['mode'] ?? ''),
            (array) ($data['options'] ?? []),
            (int) ($data['started_at'] ?? 0),
            (string) ($data['log_secret'] ?? '')
        );
        $record->finished_at = (int) ($data['finished_at'] ?? 0);
        $record->setStatus((string) ($data['status'] ?? self::STATUS_RUNNING));
        foreach ((array) ($data['counts'] ?? []) as $entity => $dimensions) {
            foreach ((array) $dimensions as $dimension => $count) {
                $record->counts[ (string) $entity ][ (string) $dimension ] = (int) $count;
            }
        }
        foreach ((array) ($data['sold_adjustments'] ?? []) as $ticket_id => $delta) {
            $record->sold_adjustments[ (int) $ticket_id ] = (int) $delta;
        }
        foreach ((array) ($data['warnings_sample'] ?? []) as $warning) {
            $record->warnings_sample[] = MigrationWarning::fromArray((array) $warning)->toArray();
        }
        foreach ((array) ($data['warnings_by_rule'] ?? []) as $rule => $count) {
            $record->warnings_by_rule[ (string) $rule ] = (int) $count;
        }
        foreach ((array) ($data['reuse_sample'] ?? []) as $note) {
            $record->addReuseNote((array) $note);
        }
        $record->reconciliation = (array) ($data['reconciliation'] ?? []);
        $record->progress       = (array) ($data['progress'] ?? []);
        $record->tally          = ReconciliationTally::fromArray((array) ($data['tally'] ?? []));
        foreach ((array) ($data['refusals'] ?? []) as $refusal) {
            $refusal             = (array) $refusal;
            $record->refusals[] = [
                'model'  => (string) ($refusal['model'] ?? ''),
                'id'     => (int) ($refusal['id'] ?? 0),
                'reason' => (string) ($refusal['reason'] ?? ''),
            ];
        }
        return $record;
    }
}
// End of file MigrationRunRecord.php
// Location: EventEspresso\EventMigrator\domain\services\migration\run/MigrationRunRecord.php
