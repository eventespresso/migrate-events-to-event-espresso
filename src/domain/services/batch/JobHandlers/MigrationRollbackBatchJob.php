<?php

namespace EventEspresso\EventMigrator\domain\services\batch\JobHandlers;

use DomainException;
use EventEspresso\core\libraries\batch\Helpers\JobParameters;
use EventEspresso\core\libraries\batch\Helpers\JobStepResponse;
use EventEspresso\core\services\options\JsonWpOptionManager;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig;
use EventEspresso\EventMigrator\domain\services\migration\MigrationLock;
use EventEspresso\EventMigrator\domain\services\migration\rollback\RollbackPreflight;
use EventEspresso\EventMigrator\domain\services\migration\rollback\RunEntityIndex;
use EventEspresso\EventMigrator\domain\services\migration\rollback\RunEntityRemover;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationLog;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunRecord;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunStore;
use Throwable;

/**
 * Class MigrationRollbackBatchJob
 *
 * Rolls a completed run back (REQ-010) in two phases of chunked requests.
 * Pre-flight: every record the run created is checked by RollbackPreflight;
 * any refusal ends the job with the run marked rollback_refused and nothing
 * deleted. Delete: each model's records go through RunEntityRemover in
 * deletion order; ids that survive their recipe leave the run
 * rollback_partial, and rolling back again resumes on what is left. A
 * finished rollback marks the run rolled_back; the record is kept.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationRollbackBatchJob extends MigrationJobBase
{
    public const PHASE_PREFLIGHT = 'preflight';

    public const PHASE_DELETE = 'delete';

    public const DELETE_CHUNK = 100;

    /**
     * Refusals and failures kept in extra_data and on the run record; every one is also written to the log.
     */
    public const MAX_TRACKED = 500;

    /**
     * Refusals quoted in the job's closing message.
     */
    public const MAX_QUOTED = 20;

    /**
     * Run statuses a rollback may start from.
     */
    public const ROLLBACK_FROM = [
        MigrationRunRecord::STATUS_COMPLETED,
        MigrationRunRecord::STATUS_FAILED,
        MigrationRunRecord::STATUS_ROLLBACK_REFUSED,
        MigrationRunRecord::STATUS_ROLLBACK_PARTIAL,
    ];

    private MigrationRunStore $run_store;

    private MigrationLog $log;

    private RunEntityIndex $index;

    private RollbackPreflight $preflight;

    private RunEntityRemover $remover;


    public function __construct(
        MigrationConfig $config,
        JsonWpOptionManager $option_manager,
        MigrationLock $lock,
        MigrationRunStore $run_store,
        MigrationLog $log,
        RunEntityIndex $index,
        RollbackPreflight $preflight,
        RunEntityRemover $remover
    ) {
        parent::__construct($config, $option_manager, $lock);
        $this->run_store = $run_store;
        $this->log       = $log;
        $this->index     = $index;
        $this->preflight = $preflight;
        $this->remover   = $remover;
    }


    /**
     * {@inheritDoc}
     *
     * Checks the capability, claims the lock and indexes what the target run created.
     *
     * @since 0.0.1.000
     * @throws DomainException Internally, when no rollback was confirmed; caught and reported as a failed job.
     */
    public function create_job(JobParameters $job_parameters): JobStepResponse
    {
        if (! $this->canMigrate()) {
            return $this->fail(
                $job_parameters,
                esc_html__('You do not have permission to roll back migrations.', 'migrate-events-to-event-espresso')
            );
        }
        if (! $this->claimLock($job_parameters)) {
            return $this->fail(
                $job_parameters,
                esc_html__(
                    'The rollback could not be started. A migration may be running, or this link was already used.',
                    'migrate-events-to-event-espresso'
                )
            );
        }
        try {
            $record = $this->targetRecord($this->config->getRollbackTargetRun());
            if ($this->config->getMode() !== MigrationConfig::MODE_ROLLBACK) {
                throw new DomainException(esc_html__('No rollback was confirmed.', 'migrate-events-to-event-espresso'));
            }
            $index = $this->index->forRun($record->runId());
            $job_parameters->add_extra_data('target_run', $record->runId());
            $job_parameters->add_extra_data('index', $index);
            $job_parameters->add_extra_data('phase', self::PHASE_PREFLIGHT);
            $job_parameters->add_extra_data('model_index', 0);
            $job_parameters->add_extra_data('offset', 0);
            $job_parameters->add_extra_data('refusals', []);
            $job_parameters->add_extra_data('refusals_total', 0);
            $job_parameters->add_extra_data('failed', []);
            $steps = 1;
            foreach ($index as $count) {
                $steps += (int) ceil($count / RollbackPreflight::CHUNK) + (int) ceil($count / self::DELETE_CHUNK);
            }
            $job_parameters->set_job_size($steps);
            $this->log->append($record->logSecret(), 'rollback-started');
            return new JobStepResponse(
                $job_parameters,
                esc_html__('Checking the migrated records before anything is deleted...', 'migrate-events-to-event-espresso')
            );
        } catch (Throwable $throwable) {
            $this->logThrowable($throwable);
            $this->releaseLock($job_parameters);
            return $this->fail($job_parameters, esc_html($throwable->getMessage()));
        }
    }


    /**
     * {@inheritDoc}
     *
     * Runs the next pre-flight chunk or the next delete chunk, after re-checking the capability and the lock.
     *
     * @since 0.0.1.000
     */
    public function continue_job(JobParameters $job_parameters, int $batch_size = 50): JobStepResponse
    {
        $refusal = $this->refuse($job_parameters);
        if ($refusal instanceof JobStepResponse) {
            return $refusal;
        }
        try {
            $record = $this->targetRecord((string) $job_parameters->extra_datum('target_run', ''));
            $job_parameters->mark_processed(1);
            if ($job_parameters->units_processed() >= $job_parameters->job_size()) {
                $job_parameters->set_job_size($job_parameters->units_processed() + 1);
            }
            return $job_parameters->extra_datum('phase', '') === self::PHASE_DELETE
                ? $this->deleteStep($job_parameters, $record)
                : $this->preflightStep($job_parameters, $record);
        } catch (Throwable $throwable) {
            $this->logThrowable($throwable);
            $this->markInterruptedDelete($job_parameters);
            $this->releaseLock($job_parameters);
            return $this->fail(
                $job_parameters,
                sprintf(
                    /* translators: %s: error message */
                    esc_html__('The rollback stopped: %s', 'migrate-events-to-event-espresso'),
                    esc_html($throwable->getMessage())
                )
            );
        }
    }


    /**
     * {@inheritDoc}
     *
     * Marks an interrupted delete phase rollback_partial (see markInterruptedDelete()) and releases the lock.
     *
     * @since 0.0.1.000
     */
    public function cleanup_job(JobParameters $job_parameters): JobStepResponse
    {
        if ($job_parameters->extra_datum('lock_claimed', false) !== true) {
            return new JobStepResponse($job_parameters, esc_html__('Nothing to clean up.', 'migrate-events-to-event-espresso'));
        }
        $this->markInterruptedDelete($job_parameters);
        $this->releaseLock($job_parameters);
        return new JobStepResponse($job_parameters, esc_html__('Rollback stopped.', 'migrate-events-to-event-espresso'));
    }


    /**
     * A rollback that stops inside the delete phase, whether by an error or by cleanup, has removed some of
     * the run's records: the run is marked rollback_partial so the report says so and a later rollback
     * resumes on what is left. Before the delete phase nothing was deleted and the status is left alone.
     * Called before releaseLock(), which cleanup_job()'s lock guard would otherwise turn into a no-op.
     */
    private function markInterruptedDelete(JobParameters $job_parameters): void
    {
        if ($job_parameters->extra_datum('phase', '') !== self::PHASE_DELETE) {
            return;
        }
        $run_id = (string) $job_parameters->extra_datum('target_run', '');
        $this->run_store->markStatus($run_id, MigrationRunRecord::STATUS_ROLLBACK_PARTIAL);
        $record = $this->run_store->load($run_id);
        if ($record instanceof MigrationRunRecord) {
            $this->log->append($record->logSecret(), MigrationRunRecord::STATUS_ROLLBACK_PARTIAL);
        }
    }


    /**
     * @throws DomainException When the run does not exist or cannot be rolled back.
     */
    private function targetRecord(string $run_id): MigrationRunRecord
    {
        $record = $this->run_store->load($run_id);
        if (! $record instanceof MigrationRunRecord || ! in_array($record->status(), self::ROLLBACK_FROM, true)) {
            throw new DomainException(esc_html__('This migration run cannot be rolled back.', 'migrate-events-to-event-espresso'));
        }
        return $record;
    }


    private function preflightStep(JobParameters $job_parameters, MigrationRunRecord $record): JobStepResponse
    {
        $model = $this->currentModel($job_parameters);
        if ($model !== null) {
            $offset = (int) $job_parameters->extra_datum('offset', 0);
            $result = $this->preflight->checkChunk($record->runId(), $model, $offset);
            $this->trackRefusals($job_parameters, $record, $result['refusals']);
            $this->advance($job_parameters, $offset + RollbackPreflight::CHUNK, $result['checked'] < RollbackPreflight::CHUNK);
            return new JobStepResponse($job_parameters, esc_html__('Checking migrated records...', 'migrate-events-to-event-espresso'));
        }
        $refusals = (array) $job_parameters->extra_datum('refusals', []);
        if ($refusals !== []) {
            $record->setRefusals($refusals);
            $record->setStatus(MigrationRunRecord::STATUS_ROLLBACK_REFUSED);
            $this->run_store->save($record);
            $this->releaseLock($job_parameters);
            return $this->fail($job_parameters, $this->refusalMessage($job_parameters, $refusals));
        }
        $job_parameters->add_extra_data('phase', self::PHASE_DELETE);
        $job_parameters->add_extra_data('model_index', 0);
        $job_parameters->add_extra_data('offset', 0);
        return new JobStepResponse($job_parameters, esc_html__('Deleting migrated records...', 'migrate-events-to-event-espresso'));
    }


    private function deleteStep(JobParameters $job_parameters, MigrationRunRecord $record): JobStepResponse
    {
        $model = $this->currentModel($job_parameters);
        if ($model !== null) {
            $failed_so_far = (array) $job_parameters->extra_datum('failed', []);
            $offset        = (int) ($failed_so_far[ $model ] ?? 0);
            $ids           = $this->index->ids($record->runId(), $model, $offset, self::DELETE_CHUNK);
            $failed        = $this->remover->delete($model, $ids, $record);
            $failed_so_far[ $model ] = $offset + count($failed);
            $job_parameters->add_extra_data('failed', $failed_so_far);
            foreach ($failed as $id) {
                $this->log->append($record->logSecret(), 'rollback-delete-failed', $model, (string) $id);
            }
            $this->run_store->save($record);
            $this->advance($job_parameters, 0, count($ids) < self::DELETE_CHUNK);
            return new JobStepResponse($job_parameters, esc_html__('Deleting migrated records...', 'migrate-events-to-event-espresso'));
        }
        $remaining = array_sum((array) $job_parameters->extra_datum('failed', []));
        $record->setStatus(
            $remaining > 0 ? MigrationRunRecord::STATUS_ROLLBACK_PARTIAL : MigrationRunRecord::STATUS_ROLLED_BACK
        );
        $record->setFinishedAt(time());
        $this->run_store->save($record);
        $this->log->append($record->logSecret(), $record->status());
        $this->releaseLock($job_parameters);
        if ($remaining > 0) {
            return $this->fail(
                $job_parameters,
                sprintf(
                    /* translators: %d: number of records that could not be deleted */
                    esc_html__(
                        '%d migrated record(s) could not be deleted. Their ids are in the run log. Remove what blocks them and roll back again.',
                        'migrate-events-to-event-espresso'
                    ),
                    $remaining
                )
            );
        }
        $job_parameters->set_job_size($job_parameters->units_processed());
        $job_parameters->set_status(JobParameters::status_complete);
        return new JobStepResponse($job_parameters, esc_html__('The migration was rolled back.', 'migrate-events-to-event-espresso'));
    }


    /**
     * The model the cursor points at, or null when the phase has covered every model.
     */
    private function currentModel(JobParameters $job_parameters): ?string
    {
        $models = array_keys((array) $job_parameters->extra_datum('index', []));
        return $models[ (int) $job_parameters->extra_datum('model_index', 0) ] ?? null;
    }


    /**
     * Moves the cursor to the next page of the model, or to the next model when this one is done.
     */
    private function advance(JobParameters $job_parameters, int $next_offset, bool $model_done): void
    {
        $model_index = (int) $job_parameters->extra_datum('model_index', 0);
        $job_parameters->add_extra_data('model_index', $model_done ? $model_index + 1 : $model_index);
        $job_parameters->add_extra_data('offset', $model_done ? 0 : $next_offset);
    }


    /**
     * Adds refusals to extra_data (at most MAX_TRACKED kept, all counted) and writes each to the log.
     */
    private function trackRefusals(JobParameters $job_parameters, MigrationRunRecord $record, array $refusals): void
    {
        $kept = (array) $job_parameters->extra_datum('refusals', []);
        foreach ($refusals as $refusal) {
            $this->log->append($record->logSecret(), 'rollback-refused-' . $refusal['reason'], $refusal['model'], (string) $refusal['id']);
        }
        $job_parameters->add_extra_data('refusals', array_slice(array_merge($kept, $refusals), 0, self::MAX_TRACKED));
        $job_parameters->add_extra_data('refusals_total', (int) $job_parameters->extra_datum('refusals_total', 0) + count($refusals));
    }


    private function refusalMessage(JobParameters $job_parameters, array $refusals): string
    {
        $lines = [];
        foreach (array_slice($refusals, 0, self::MAX_QUOTED) as $refusal) {
            $lines[] = esc_html(sprintf('%s #%d: %s', $refusal['model'], $refusal['id'], $refusal['reason']));
        }
        return sprintf(
            /* translators: 1: number of records, 2: list of refused records */
            esc_html__('Nothing was deleted. %1$d migrated record(s) were changed or are in use since the migration: %2$s', 'migrate-events-to-event-espresso'),
            (int) $job_parameters->extra_datum('refusals_total', count($refusals)),
            implode('; ', $lines)
        );
    }
}
// End of file MigrationRollbackBatchJob.php
// Location: EventEspresso\EventMigrator\domain\services\batch\JobHandlers/MigrationRollbackBatchJob.php
