<?php

namespace EventEspresso\EventMigrator\domain\services\migration\ui;

use EEH_URL;
use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\domain\exceptions\MigrationLaunchException;
use EventEspresso\EventMigrator\domain\services\batch\JobHandlers\MigrationBatchJob;
use EventEspresso\EventMigrator\domain\services\batch\JobHandlers\MigrationJobBase;
use EventEspresso\EventMigrator\domain\services\batch\JobHandlers\MigrationRollbackBatchJob;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfigStore;
use EventEspresso\EventMigrator\domain\services\migration\MigrationLock;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunRecord;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunStore;
use Throwable;

/**
 * Class MigrationLauncher
 *
 * Starts a run for the wizard and the rollback route (REQ-002, REQ-021):
 * acquires the single migration lock, records the run in the wizard config
 * (mode, run id, lock time, "in progress") with a compare-and-set save, and
 * builds the EE batch-runner URL that hands the run token to the batch job.
 * Any failure after the lock was taken releases it again. Also clears a
 * stale lock on request.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationLauncher
{
    private MigrationLock $lock;

    private MigrationConfigStore $store;

    private MigrationRunStore $run_store;


    public function __construct(MigrationLock $lock, MigrationConfigStore $store, MigrationRunStore $run_store)
    {
        $this->lock      = $lock;
        $this->store     = $store;
        $this->run_store = $run_store;
    }


    /**
     * Starts a migration, dry run or rollback and returns the run token (which is the run id).
     *
     * @param string $mode               A MigrationConfig::MODE_* value.
     * @param string $submitted_revision The staging revision the confirming page carried (migrate and dry run).
     * @param string $rollback_run       The run id to roll back (rollback only).
     * @throws MigrationLaunchException When the run cannot start; the lock is released again.
     * @throws Throwable Any unexpected failure after the lock was taken; the lock is released again.
     */
    public function launch(string $mode, string $submitted_revision = '', string $rollback_run = ''): string
    {
        if (! MigrationConfig::isValidMode($mode)) {
            throw new MigrationLaunchException(esc_html__('Unknown migration mode.', 'migrate-events-to-event-espresso'));
        }
        if ($mode === MigrationConfig::MODE_ROLLBACK) {
            $this->assertRollbackable($rollback_run);
        }
        $acquired = $this->lock->acquire();
        if ($acquired === null) {
            $holder = $this->lock->describeHolder();
            // describeHolder() is already escaped.
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new MigrationLaunchException(
                $holder !== ''
                    ? $holder . ' ' . esc_html__('Wait for it to finish, then try again.', 'migrate-events-to-event-espresso')
                    : esc_html__('Another migration or import is in progress.', 'migrate-events-to-event-espresso')
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        try {
            $raw = $this->store->load();
            $this->prepareConfig($mode, $submitted_revision, $rollback_run, $acquired);
            if (! $this->store->saveIfUnchanged($raw)) {
                throw new MigrationLaunchException(
                    esc_html__(
                        'The migration could not be started because its settings changed while this page was open. Please start again from the first step.',
                        'migrate-events-to-event-espresso'
                    )
                );
            }
        } catch (Throwable $throwable) {
            $this->lock->release($acquired['ts'], $acquired['token']);
            throw $throwable;
        }
        return $acquired['token'];
    }


    /**
     * Releases a lock that has been held past the stale threshold, ends the run it belonged to, and clears the
     * wizard's run state. A running CSV import is never touched. True when a stale lock was cleared.
     */
    public function clearStaleLock(): bool
    {
        $holder = $this->lock->holder();
        if ($holder === null || $holder['kind'] === MigrationLock::HOLDER_CSV || ! $holder['stale']) {
            return false;
        }
        $parsed = $this->lock->parse();
        if (! $this->lock->release((string) $parsed['ts'], $parsed['token'], $parsed['job_id'])) {
            return false;
        }
        $raw    = $this->store->load();
        $config = $this->store->config();
        if (MigrationRunStore::isValidRunId($config->getRunId())) {
            $run = $this->run_store->load($config->getRunId());
            if ($run instanceof MigrationRunRecord && $run->status() === MigrationRunRecord::STATUS_RUNNING) {
                $this->run_store->markStatus($run->runId(), MigrationRunRecord::STATUS_FAILED);
            }
        }
        $config->clearRun();
        $this->store->saveIfUnchanged($raw);
        return true;
    }


    /**
     * The EE batch-runner URL that starts the job for a mode, handing it the run token and the page to return to.
     */
    public static function batchUrl(string $mode, string $token, string $return_url): string
    {
        $job_handler = $mode === MigrationConfig::MODE_ROLLBACK
            ? MigrationRollbackBatchJob::class
            : MigrationBatchJob::class;
        return EEH_URL::add_query_args_and_nonce(
            [
                'page'                          => 'espresso_batch',
                'batch'                         => 'job',
                'job_handler'                   => urlencode($job_handler),
                MigrationJobBase::RUN_TOKEN_PARAM => $token,
                'return_url'                    => urlencode($return_url),
            ]
        );
    }


    /**
     * The wizard's report step for a run.
     */
    public static function reportUrl(string $admin_url, string $run_id): string
    {
        return add_query_arg(
            [
                'action'       => 'show_migration_step',
                'ee-form-step' => 'report',
                'run_id'       => $run_id,
            ],
            $admin_url
        );
    }


    /**
     * @param string                           $mode               A MigrationConfig::MODE_* value.
     * @param string                           $submitted_revision The staging revision the confirming page carried.
     * @param string                           $rollback_run       The run id to roll back (rollback only).
     * @param array{ts: string, token: string} $acquired           The lock that was just acquired.
     * @throws MigrationLaunchException
     */
    private function prepareConfig(string $mode, string $submitted_revision, string $rollback_run, array $acquired): void
    {
        $config = $this->store->config();
        if ($mode === MigrationConfig::MODE_ROLLBACK) {
            $config->setRollbackTargetRun($rollback_run);
        } else {
            if ($submitted_revision === '' || $submitted_revision !== $config->getStagingRevision()) {
                throw new MigrationLaunchException(
                    esc_html__(
                        'The migration settings have changed since this page was loaded. Please start again from the first step.',
                        'migrate-events-to-event-espresso'
                    )
                );
            }
            $options             = $config->getOptions();
            $options['dry_run'] = $mode === MigrationConfig::MODE_DRY_RUN;
            try {
                MigrationOptions::fromArray($options);
            } catch (Throwable $throwable) {
                throw new MigrationLaunchException(
                    esc_html__('The migration options are incomplete. Please start again from the first step.', 'migrate-events-to-event-espresso')
                );
            }
            $config->setOptions($options);
            $config->setRollbackTargetRun('');
        }
        $config->setMode($mode);
        $config->setRunId($acquired['token']);
        $config->setLockAcquiredAt($acquired['ts']);
        $config->setImportInProgress(true);
        // Rotating the revision makes any wizard page still open fail its revision check instead of re-launching.
        $config->setStagingRevision(MigrationConfig::generateStagingRevision());
    }


    /**
     * @throws MigrationLaunchException When the run does not exist or its status does not allow a rollback.
     */
    private function assertRollbackable(string $run_id): void
    {
        $run = MigrationRunStore::isValidRunId($run_id) ? $this->run_store->load($run_id) : null;
        if (! $run instanceof MigrationRunRecord || ! in_array($run->status(), MigrationRollbackBatchJob::ROLLBACK_FROM, true)) {
            throw new MigrationLaunchException(esc_html__('This migration run cannot be rolled back.', 'migrate-events-to-event-espresso'));
        }
    }
}
// End of file MigrationLauncher.php
// Location: EventEspresso\EventMigrator\domain\services\migration\ui/MigrationLauncher.php
