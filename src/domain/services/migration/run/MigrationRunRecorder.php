<?php

namespace EventEspresso\EventMigrator\domain\services\migration\run;

use DomainException;
use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\SourceAdapter;
use EventEspresso\EventMigrator\application\services\migration\SourceCapabilities;
use EventEspresso\EventMigrator\application\services\migration\SourceExclusions;
use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\report\RunReportBuilder;

/**
 * Class MigrationRunRecorder
 *
 * Starts and finishes a migration run's record: the event-id snapshot and
 * step estimate the batch job needs, the "running" record with its log, and
 * at the end the final status, the reconciliation (with the crosswalk counts
 * of a real run) and the closing log line.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationRunRecorder
{
    /**
     * Event ids one run snapshots; a larger source is refused with a request to narrow the horizon.
     */
    public const MAX_EVENTS = 10000;

    private Crosswalk $crosswalk;

    private MigrationRunStore $run_store;

    private MigrationLog $log;

    private RunReportBuilder $report_builder;


    public function __construct(
        Crosswalk $crosswalk,
        MigrationRunStore $run_store,
        MigrationLog $log,
        RunReportBuilder $report_builder
    ) {
        $this->crosswalk      = $crosswalk;
        $this->run_store      = $run_store;
        $this->log            = $log;
        $this->report_builder = $report_builder;
    }


    /**
     * The source's event ids for the run's options, in the adapter's order.
     *
     * @return string[]
     * @throws DomainException When the source has more events than one run can track.
     */
    public function eventIds(SourceAdapter $adapter, MigrationOptions $options): array
    {
        $event_ids = [];
        foreach ($adapter->eventIds($options) as $event_id) {
            if (count($event_ids) === self::MAX_EVENTS) {
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new DomainException(
                    sprintf(
                        /* translators: %d: maximum number of events */
                        esc_html__(
                            'The source has more than %d events in range. Choose a narrower date range and run the migration again.',
                            'migrate-events-to-event-espresso'
                        ),
                        self::MAX_EVENTS
                    )
                );
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $event_ids[] = (string) $event_id;
        }
        return $event_ids;
    }


    /**
     * Batch requests the run is expected to take: one per event, plus, when bookings are migrated, one per
     * full chunk and one closing chunk per event. The job corrects its size as the run goes.
     *
     * @param int                $events    Events in the run.
     * @param MigrationOptions   $options   The run's options.
     * @param array<string, int> $inventory SourceInventory::counts().
     */
    public static function estimateSteps(int $events, MigrationOptions $options, array $inventory): int
    {
        if (! $options->includesBookings()) {
            return max(1, $events);
        }
        $bookings = (int) ($inventory[ SourceInventory::COUNT_BOOKINGS ] ?? 0);
        return max(1, 2 * $events + (int) ceil($bookings / MigrationRunner::BOOKING_CHUNK));
    }


    /**
     * Saves and returns a "running" record with a new log, positioned at the first event. A source that has
     * bookings the options leave out gets one domain-excluded warning, a horizon that leaves events out gets one
     * horizon-excluded warning with the count, and an adapter that reports exclusions (SourceExclusions) gets one
     * warning for each.
     *
     * @param string           $run_id           The 16-hex run id.
     * @param SourceAdapter    $adapter          The run's source.
     * @param MigrationOptions $options          The run's options.
     * @param string           $mode             MigrationConfig::MODE_MIGRATE or MODE_DRY_RUN.
     * @param string[]         $event_ids        The run's event ids, from eventIds().
     * @param int              $horizon_excluded Source events the time horizon leaves out (HorizonExclusion).
     */
    public function start(
        string $run_id,
        SourceAdapter $adapter,
        MigrationOptions $options,
        string $mode,
        array $event_ids,
        int $horizon_excluded = 0
    ): MigrationRunRecord {
        $record = new MigrationRunRecord(
            $run_id,
            $adapter->slug(),
            $mode,
            $options->toArray(),
            time(),
            MigrationLog::generateSecret()
        );
        $this->log->append($record->logSecret(), 'started');
        if (
            ! $options->includesBookings()
            && $adapter->capabilities()->provides(SourceCapabilities::DOMAIN_BOOKINGS)
        ) {
            $warning = new MigrationWarning(WarningRule::DOMAIN_EXCLUDED, CrosswalkKeys::TYPE_BOOKING, '');
            $record->addWarning($warning);
            $this->log->append($record->logSecret(), $warning->rule(), $warning->sourceType());
        }
        if ($horizon_excluded > 0) {
            // One warning carries the count, so the report states how many events the horizon left out (REQ-004).
            $warning = new MigrationWarning(
                WarningRule::HORIZON_EXCLUDED,
                CrosswalkKeys::TYPE_EVENT,
                '',
                [(string) $horizon_excluded]
            );
            $record->addWarning($warning);
            $this->log->append($record->logSecret(), $warning->rule(), $warning->sourceType());
        }
        $record->setProgress(['cursor' => (new MigrationCursor($event_ids))->toArray(), 'lookup' => [], 'next_synthetic_id' => -1]);
        if ($adapter instanceof SourceExclusions) {
            foreach ($adapter->exclusions($options) as $warning) {
                $record->addWarning($warning);
                $this->log->append($record->logSecret(), $warning->rule(), $warning->sourceType(), $warning->sourceId());
            }
        }
        $this->run_store->save($record);
        return $record;
    }


    /**
     * Sets the final status of a running record (a run stopped before its last event is "failed"), the
     * reconciliation and the finish time, drops the resume state and saves it.
     *
     * @param MigrationRunRecord $record    The run's record.
     * @param array<string, int> $inventory SourceInventory::counts() taken when the run started.
     */
    public function finish(MigrationRunRecord $record, array $inventory): void
    {
        $dry_run  = $record->mode() === MigrationConfig::MODE_DRY_RUN;
        $finished = MigrationCursor::fromArray((array) ($record->progress()['cursor'] ?? []))->isFinished();
        if ($record->status() === MigrationRunRecord::STATUS_RUNNING) {
            $record->setStatus(
                ! $finished
                    ? MigrationRunRecord::STATUS_FAILED
                    : ($dry_run ? MigrationRunRecord::STATUS_DRY_RUN : MigrationRunRecord::STATUS_COMPLETED)
            );
        }
        $crosswalk_counts = [];
        $actuals          = null;
        if (! $dry_run) {
            foreach (RunReportBuilder::ENTITY_MODELS as $model) {
                $crosswalk_counts[ $model ] = $this->crosswalk->countForRun($record->runId(), $model);
            }
            $actuals = [
                'registrations'     => $this->crosswalk->registrationsPerEventForRun($record->runId()),
                'approved_payments' => $this->crosswalk->approvedPaymentsForRun($record->runId()),
            ];
        }
        $this->report_builder->build($record, $inventory, $crosswalk_counts, $actuals);
        $record->setProgress([]);
        $record->setFinishedAt(time());
        $this->run_store->save($record);
        $this->log->append($record->logSecret(), $record->status());
    }
}
// End of file MigrationRunRecorder.php
// Location: EventEspresso\EventMigrator\domain\services\migration\run/MigrationRunRecorder.php
