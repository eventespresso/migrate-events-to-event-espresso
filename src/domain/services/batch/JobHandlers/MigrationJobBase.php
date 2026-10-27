<?php

namespace EventEspresso\EventMigrator\domain\services\batch\JobHandlers;

use EventEspresso\core\libraries\batch\Helpers\JobParameters;
use EventEspresso\core\libraries\batch\Helpers\JobStepResponse;
use EventEspresso\core\libraries\batch\JobHandlerBaseClasses\JobHandler;
use EventEspresso\core\services\options\JsonWpOptionManager;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig;
use EventEspresso\EventMigrator\domain\services\migration\MigrationLock;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunStore;
use Throwable;
use EventEspresso\EventMigrator\domain\services\migration\run\ThrowableLogger;

/**
 * Class MigrationJobBase
 *
 * What the migration and rollback batch jobs share: binding exactly one job
 * to the lock the wizard acquired (the run token travels in the batch URL as
 * ee_migration_run_token and is a 16-hex run id), checking capability and
 * lock ownership on every step (EED_Batch's AJAX endpoints only require the
 * `read` capability), releasing the lock by its exact value, and clearing the
 * wizard's run state.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
abstract class MigrationJobBase extends JobHandler
{
    /**
     * Request parameter that carries the run token from the wizard's batch URL to create_job().
     */
    public const RUN_TOKEN_PARAM = 'ee_migration_run_token';

    public const CAPABILITY = 'ee_migrate_events';

    protected MigrationConfig $config;

    protected JsonWpOptionManager $option_manager;

    protected MigrationLock $lock;


    public function __construct(MigrationConfig $config, JsonWpOptionManager $option_manager, MigrationLock $lock)
    {
        $this->config         = $config;
        $this->option_manager = $option_manager;
        $this->lock           = $lock;
    }


    /**
     * Claims the lock for this job: the wizard stored the lock's timestamp in the config and passed its token
     * in the URL. True when this job now owns it; the run token is stored in extra_data either way.
     */
    protected function claimLock(JobParameters $job_parameters): bool
    {
        $this->option_manager->populateFromDb($this->config);
        $token = (string) $job_parameters->request_datum(self::RUN_TOKEN_PARAM, '');
        $ts    = $this->config->getLockAcquiredAt();
        $job_parameters->add_extra_data('run_token', $token);
        $job_parameters->add_extra_data('lock_ts', $ts);
        if (
            ! MigrationRunStore::isValidRunId($token)
            || ! $this->lock->claim($ts, $token, $job_parameters->job_id())
        ) {
            return false;
        }
        $job_parameters->add_extra_data('lock_claimed', true);
        return true;
    }


    protected function ownsLock(JobParameters $job_parameters): bool
    {
        return $job_parameters->extra_datum('lock_claimed', false) === true
            && $this->lock->isOwnedBy(
                (string) $job_parameters->extra_datum('lock_ts', ''),
                (string) $job_parameters->extra_datum('run_token', ''),
                $job_parameters->job_id()
            );
    }


    /**
     * Releases the lock if this job still holds it, and only then clears the wizard's run state, so a late
     * cleanup can never wipe the state of a run started after this one.
     */
    protected function releaseLock(JobParameters $job_parameters): void
    {
        if ($job_parameters->extra_datum('lock_claimed', false) !== true) {
            return;
        }
        $job_parameters->add_extra_data('lock_claimed', false);
        $released = $this->lock->release(
            (string) $job_parameters->extra_datum('lock_ts', ''),
            (string) $job_parameters->extra_datum('run_token', ''),
            $job_parameters->job_id()
        );
        if (! $released) {
            return;
        }
        $this->option_manager->populateFromDb($this->config);
        $this->config->clearRun();
        $this->option_manager->saveToDb($this->config);
    }


    protected function runId(JobParameters $job_parameters): string
    {
        return (string) $job_parameters->extra_datum('run_token', '');
    }


    /**
     * Whether the current user may run migrations. Separate so tests can substitute it.
     */
    protected function canMigrate(): bool
    {
        return current_user_can(self::CAPABILITY);
    }


    /**
     * Ends the job with an error status and a message.
     */
    protected function fail(JobParameters $job_parameters, string $message): JobStepResponse
    {
        $job_parameters->set_status(JobParameters::status_error);
        return new JobStepResponse($job_parameters, $message);
    }


    /**
     * The checks every step repeats: capability (releasing the lock when it fails) and lock ownership. Null
     * when the step may proceed.
     */
    protected function refuse(JobParameters $job_parameters): ?JobStepResponse
    {
        if (! $this->canMigrate()) {
            $this->releaseLock($job_parameters);
            return $this->fail(
                $job_parameters,
                esc_html__('You do not have permission to migrate events.', 'migrate-events-to-event-espresso')
            );
        }
        if (! $this->ownsLock($job_parameters)) {
            return $this->fail(
                $job_parameters,
                esc_html__(
                    'The migration lock is no longer held by this job. The run may have been cancelled or superseded.',
                    'migrate-events-to-event-espresso'
                )
            );
        }
        return null;
    }


    protected function logThrowable(Throwable $throwable): void
    {
        ThrowableLogger::log($throwable);
    }
}
// End of file MigrationJobBase.php
// Location: EventEspresso\EventMigrator\domain\services\batch\JobHandlers/MigrationJobBase.php
