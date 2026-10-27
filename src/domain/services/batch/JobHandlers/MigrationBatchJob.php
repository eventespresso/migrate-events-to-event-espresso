<?php

namespace EventEspresso\EventMigrator\domain\services\batch\JobHandlers;

use DomainException;
use EE_Config;
use EE_Currency_Config;
use EEH_DTT_Helper;
use EventEspresso\core\libraries\batch\Helpers\JobParameters;
use EventEspresso\core\libraries\batch\Helpers\JobStepResponse;
use EventEspresso\core\services\options\JsonWpOptionManager;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\SourceAdapter;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\MigrationLock;
use EventEspresso\EventMigrator\domain\services\migration\persist\BookingAnswersPersister;
use EventEspresso\EventMigrator\domain\services\migration\persist\DryRunPersister;
use EventEspresso\EventMigrator\domain\services\migration\persist\ModelPersister;
use EventEspresso\EventMigrator\domain\services\migration\persist\Persister;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationCursor;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunner;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunRecord;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunRecorder;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunStore;
use EventEspresso\EventMigrator\domain\services\migration\sources\HorizonExclusion;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceAdapterRegistry;
use RuntimeException;
use Throwable;

/**
 * Class MigrationBatchJob
 *
 * Runs a migration or a dry run through the EE batch framework (REQ-021).
 * create_job() claims the lock, snapshots the wizard's choices into
 * extra_data and saves a "running" run record positioned at the first of
 * the source's events. Each continue_job() does one MigrationRunner step
 * (one event graph, or one chunk of its bookings) and saves the moved cursor
 * with the record in one write; a dry run also carries its remembered venue,
 * contact and category keys and its next synthetic id, so counts match a
 * real run across requests (REQ-007). cleanup_job() writes
 * the reconciliation and final status and releases the lock.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationBatchJob extends MigrationJobBase
{
    /**
     * Models whose remembered keys a dry run carries between batch requests.
     */
    public const DRY_RUN_LOOKUP_MODELS = [
        CrosswalkKeys::MODEL_VENUE,
        CrosswalkKeys::MODEL_ATTENDEE,
        CrosswalkKeys::MODEL_TERM_TAXONOMY,
    ];

    /**
     * Transient naming the run that finished last, for the wizard's report step.
     */
    public const LAST_RUN_TRANSIENT = 'ee_event_migrator_last_run';

    private SourceAdapterRegistry $registry;

    private MigrationRunStore $run_store;

    private MigrationRunRecorder $recorder;

    private MigrationRunner $runner;

    private ModelPersister $model_persister;

    private DryRunPersister $dry_run_persister;

    private BookingAnswersPersister $answers;


    public function __construct(
        MigrationConfig $config,
        JsonWpOptionManager $option_manager,
        MigrationLock $lock,
        SourceAdapterRegistry $registry,
        MigrationRunStore $run_store,
        MigrationRunRecorder $recorder,
        MigrationRunner $runner,
        ModelPersister $model_persister,
        DryRunPersister $dry_run_persister,
        BookingAnswersPersister $answers
    ) {
        parent::__construct($config, $option_manager, $lock);
        $this->registry          = $registry;
        $this->run_store         = $run_store;
        $this->recorder          = $recorder;
        $this->runner            = $runner;
        $this->model_persister   = $model_persister;
        $this->dry_run_persister = $dry_run_persister;
        $this->answers           = $answers;
    }


    /**
     * {@inheritDoc}
     *
     * Checks the capability, claims the migration lock and snapshots the run (options, event ids, cursor).
     *
     * @since 0.0.1.000
     * @throws DomainException Internally, when no migration was confirmed; caught and reported as a failed job.
     */
    public function create_job(JobParameters $job_parameters): JobStepResponse
    {
        if (! $this->canMigrate()) {
            return $this->fail(
                $job_parameters,
                esc_html__('You do not have permission to migrate events.', 'migrate-events-to-event-espresso')
            );
        }
        if (! $this->claimLock($job_parameters)) {
            return $this->fail(
                $job_parameters,
                esc_html__(
                    'The migration could not be started. Another migration may be running, or this link was already used.',
                    'migrate-events-to-event-espresso'
                )
            );
        }
        try {
            $mode = $this->config->getMode();
            if ($mode !== MigrationConfig::MODE_MIGRATE && $mode !== MigrationConfig::MODE_DRY_RUN) {
                throw new DomainException(esc_html__('No migration was confirmed.', 'migrate-events-to-event-espresso'));
            }
            $options   = MigrationOptions::fromArray($this->config->getOptions());
            $adapter   = $this->registry->get($this->config->getSourceSlug());
            $event_ids = $this->recorder->eventIds($adapter, $options);
            $source    = $adapter->inventory($options);
            $inventory = $source->counts();
            $this->recorder->start(
                $this->runId($job_parameters),
                $adapter,
                $options,
                $mode,
                $event_ids,
                HorizonExclusion::count($adapter, $options, $source)
            );
            $job_parameters->add_extra_data('run_started', true);
            $job_parameters->add_extra_data('source', $adapter->slug());
            $job_parameters->add_extra_data('options', $options->toArray());
            $job_parameters->add_extra_data('dry_run', $mode === MigrationConfig::MODE_DRY_RUN);
            $job_parameters->add_extra_data('inventory', $inventory);
            $job_parameters->set_job_size(MigrationRunRecorder::estimateSteps(count($event_ids), $options, $inventory));
            return new JobStepResponse(
                $job_parameters,
                sprintf(
                    /* translators: %d: number of source events */
                    esc_html__('Migrating %d event(s)...', 'migrate-events-to-event-espresso'),
                    count($event_ids)
                )
            );
        } catch (Throwable $throwable) {
            $this->logThrowable($throwable);
            $this->run_store->markStatus($this->runId($job_parameters), MigrationRunRecord::STATUS_FAILED);
            $this->releaseLock($job_parameters);
            return $this->fail(
                $job_parameters,
                sprintf(
                    /* translators: %s: error message */
                    esc_html__('The migration could not be started: %s', 'migrate-events-to-event-espresso'),
                    esc_html($throwable->getMessage())
                )
            );
        }
    }


    /**
     * {@inheritDoc}
     *
     * Migrates the next event graph or the next chunk of its bookings, after re-checking the capability and
     * the lock ownership.
     *
     * @since 0.0.1.000
     * @throws RuntimeException Internally, when the run record is missing; caught and reported as a failed job.
     */
    public function continue_job(JobParameters $job_parameters, int $batch_size = 50): JobStepResponse
    {
        $refusal = $this->refuse($job_parameters);
        if ($refusal instanceof JobStepResponse) {
            return $refusal;
        }
        $run_id = $this->runId($job_parameters);
        try {
            $record = $this->run_store->load($run_id);
            if (! $record instanceof MigrationRunRecord) {
                throw new RuntimeException(esc_html__('The migration run record is missing.', 'migrate-events-to-event-espresso'));
            }
            $adapter = $this->registry->get((string) $job_parameters->extra_datum('source', ''));
            $options = MigrationOptions::fromArray((array) $job_parameters->extra_datum('options', []));
            $dry_run = $job_parameters->extra_datum('dry_run', false) === true;
            $context  = $this->context($run_id, $adapter->slug(), $options, $dry_run);
            $progress = $record->progress();
            $cursor   = MigrationCursor::fromArray((array) ($progress['cursor'] ?? []));
            $this->runner->step(
                $adapter,
                $this->persister($progress, $adapter, $context, $cursor),
                $context,
                $cursor,
                $record
            );
            $progress['cursor'] = $cursor->toArray();
            if ($dry_run) {
                $progress['lookup']            = $context->exportLookup(self::DRY_RUN_LOOKUP_MODELS);
                $progress['next_synthetic_id'] = $this->dry_run_persister->nextSyntheticId();
            }
            // The cursor is saved with the counts it produced, so a replayed request can never count twice.
            $record->setProgress($progress);
            $this->run_store->save($record);
            $job_parameters->mark_processed(1);
            return $this->progress($job_parameters, $cursor);
        } catch (Throwable $throwable) {
            $this->logThrowable($throwable);
            $this->run_store->markStatus($run_id, MigrationRunRecord::STATUS_FAILED);
            $this->releaseLock($job_parameters);
            return $this->fail(
                $job_parameters,
                sprintf(
                    /* translators: %s: error message */
                    esc_html__('The migration stopped: %s', 'migrate-events-to-event-espresso'),
                    esc_html($throwable->getMessage())
                )
            );
        }
    }


    /**
     * {@inheritDoc}
     *
     * Finishes the run record (failed when it did not complete) and releases the lock.
     *
     * @since 0.0.1.000
     */
    public function cleanup_job(JobParameters $job_parameters): JobStepResponse
    {
        // run_started is set only by the job that won the lock claim and saved the run record.
        if ($job_parameters->extra_datum('run_started', false) !== true) {
            return new JobStepResponse($job_parameters, esc_html__('Nothing to clean up.', 'migrate-events-to-event-espresso'));
        }
        $run_id = $this->runId($job_parameters);
        try {
            $record = $this->run_store->load($run_id);
            if ($record instanceof MigrationRunRecord) {
                $this->recorder->finish($record, (array) $job_parameters->extra_datum('inventory', []));
            }
            set_transient(self::LAST_RUN_TRANSIENT, $run_id, HOUR_IN_SECONDS);
        } catch (Throwable $throwable) {
            $this->logThrowable($throwable);
        }
        $this->releaseLock($job_parameters);
        return new JobStepResponse($job_parameters, esc_html__('Migration finished.', 'migrate-events-to-event-espresso'));
    }


    /**
     * The run context. Separate so tests can supply fixed site settings.
     */
    protected function context(string $run_id, string $source, MigrationOptions $options, bool $dry_run): MigrationContext
    {
        $currency = EE_Config::instance()->currency;
        return new MigrationContext(
            $run_id,
            $source,
            $options,
            EEH_DTT_Helper::get_valid_timezone_string(),
            get_current_user_id(),
            $dry_run,
            $currency instanceof EE_Currency_Config ? (string) $currency->code : ''
        );
    }


    /**
     * The persister for this request, primed with what earlier requests of the run left in its progress.
     */
    private function persister(
        array $progress,
        SourceAdapter $adapter,
        MigrationContext $context,
        MigrationCursor $cursor
    ): Persister {
        if ($context->isDryRun()) {
            $context->importLookup((array) ($progress['lookup'] ?? []));
            $this->dry_run_persister->resumeSyntheticIds((int) ($progress['next_synthetic_id'] ?? -1));
            return $this->dry_run_persister;
        }
        if ($cursor->phase() === MigrationCursor::PHASE_BOOKINGS) {
            $this->answers->useFieldDefinitions($adapter->customFieldDefinitions());
        }
        return $this->model_persister;
    }


    private function progress(JobParameters $job_parameters, MigrationCursor $cursor): JobStepResponse
    {
        if ($cursor->isFinished()) {
            $job_parameters->set_job_size($job_parameters->units_processed());
            $job_parameters->set_status(JobParameters::status_complete);
            return new JobStepResponse($job_parameters, esc_html__('Migration complete.', 'migrate-events-to-event-espresso'));
        }
        if ($job_parameters->units_processed() >= $job_parameters->job_size()) {
            $job_parameters->set_job_size($job_parameters->units_processed() + 1);
        }
        return new JobStepResponse(
            $job_parameters,
            sprintf(
                /* translators: 1: current event number, 2: total number of events */
                esc_html__('Migrating event %1$d of %2$d...', 'migrate-events-to-event-espresso'),
                min($cursor->eventIndex() + 1, count($cursor->eventIds())),
                count($cursor->eventIds())
            )
        );
    }
}
// End of file MigrationBatchJob.php
// Location: EventEspresso\EventMigrator\domain\services\batch\JobHandlers/MigrationBatchJob.php
