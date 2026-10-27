<?php

namespace EventEspresso\EventMigrator\domain\services\migration\ui;

use EventEspresso\EventMigrator\domain\services\batch\JobHandlers\MigrationRollbackBatchJob;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\rollback\RollbackPreflight;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunRecord;

/**
 * Class RunPresenter
 *
 * Plain-text labels and decisions for the run report, the run list and the
 * rollback screens. Nothing here is escaped: the templates escape every value
 * at output. All strings are translated at call time, never cached or built in
 * a constructor.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class RunPresenter
{
    public static function statusLabel(string $status): string
    {
        $labels = [
            MigrationRunRecord::STATUS_RUNNING          => __('Running', 'migrate-events-to-event-espresso'),
            MigrationRunRecord::STATUS_COMPLETED        => __('Completed', 'migrate-events-to-event-espresso'),
            MigrationRunRecord::STATUS_FAILED           => __('Stopped with an error', 'migrate-events-to-event-espresso'),
            MigrationRunRecord::STATUS_DRY_RUN          => __('Dry run (nothing was written)', 'migrate-events-to-event-espresso'),
            MigrationRunRecord::STATUS_ROLLED_BACK      => __('Rolled back', 'migrate-events-to-event-espresso'),
            MigrationRunRecord::STATUS_ROLLBACK_REFUSED => __('Rollback refused', 'migrate-events-to-event-espresso'),
            MigrationRunRecord::STATUS_ROLLBACK_PARTIAL => __('Rollback incomplete', 'migrate-events-to-event-espresso'),
        ];
        return $labels[ $status ] ?? $status;
    }


    public static function modeLabel(string $mode): string
    {
        $labels = [
            MigrationConfig::MODE_MIGRATE => __('Migration', 'migrate-events-to-event-espresso'),
            MigrationConfig::MODE_DRY_RUN => __('Dry run', 'migrate-events-to-event-espresso'),
        ];
        return $labels[ $mode ] ?? $mode;
    }


    /**
     * The plural heading for an entity key used in the run counts and the reconciliation.
     */
    public static function entityLabel(string $entity): string
    {
        $labels = [
            'event'        => __('Events', 'migrate-events-to-event-espresso'),
            'datetime'     => __('Datetimes', 'migrate-events-to-event-espresso'),
            'ticket'       => __('Tickets', 'migrate-events-to-event-espresso'),
            'venue'        => __('Venues', 'migrate-events-to-event-espresso'),
            'category'     => __('Categories', 'migrate-events-to-event-espresso'),
            'attendee'     => __('Contacts', 'migrate-events-to-event-espresso'),
            'booking'      => __('Bookings (transactions)', 'migrate-events-to-event-espresso'),
            'registration' => __('Registrations', 'migrate-events-to-event-espresso'),
            'payment'      => __('Payments', 'migrate-events-to-event-espresso'),
        ];
        return $labels[ $entity ] ?? $entity;
    }


    /**
     * Why a record blocked a rollback: "modified" or "dependents:<model>".
     */
    public static function reasonLabel(string $reason): string
    {
        if ($reason === RollbackPreflight::REASON_MODIFIED) {
            return __('It was changed after the migration.', 'migrate-events-to-event-espresso');
        }
        $prefix = RollbackPreflight::REASON_DEPENDENTS . ':';
        if (strpos($reason, $prefix) === 0) {
            return sprintf(
                /* translators: %s: the kind of record that uses it, e.g. Registration */
                __('Records outside this migration depend on it (%s).', 'migrate-events-to-event-espresso'),
                substr($reason, strlen($prefix))
            );
        }
        return $reason;
    }


    /**
     * The admin screen for a record, or '' when Event Espresso has no single-record screen for the model.
     */
    public static function recordUrl(string $model, int $id): string
    {
        $query = [
            CrosswalkKeys::MODEL_EVENT        => ['page' => 'espresso_events', 'action' => 'edit', 'post' => $id],
            CrosswalkKeys::MODEL_VENUE        => ['page' => 'espresso_venues', 'action' => 'edit', 'post' => $id],
            CrosswalkKeys::MODEL_TRANSACTION  => ['page' => 'espresso_transactions', 'action' => 'view_transaction', 'TXN_ID' => $id],
            CrosswalkKeys::MODEL_REGISTRATION => ['page' => 'espresso_registrations', 'action' => 'view_registration', '_REG_ID' => $id],
        ];
        return $id > 0 && isset($query[ $model ]) ? add_query_arg($query[ $model ], admin_url('admin.php')) : '';
    }


    /**
     * What the run list and the report offer for a run.
     *
     * @return array{rollback: bool, crosswalk: bool, log: bool}
     */
    public static function actionsFor(string $mode, string $status): array
    {
        $migrated = $mode === MigrationConfig::MODE_MIGRATE;
        return [
            'rollback'  => $migrated && in_array($status, MigrationRollbackBatchJob::ROLLBACK_FROM, true),
            'crosswalk' => $migrated && in_array(
                $status,
                [
                    MigrationRunRecord::STATUS_COMPLETED,
                    MigrationRunRecord::STATUS_FAILED,
                    MigrationRunRecord::STATUS_ROLLBACK_REFUSED,
                    MigrationRunRecord::STATUS_ROLLBACK_PARTIAL,
                ],
                true
            ),
            'log'       => $status !== MigrationRunRecord::STATUS_RUNNING,
        ];
    }


    /**
     * Run-list rows from MigrationRunStore::list().
     *
     * @param array<int, array{run_id: string, source: string, mode: string, status: string, started_at: int, finished_at: int}> $entries
     * @param array<string, string>                                                                                              $source_names Display names keyed by source slug.
     * @param string                                                                                                             $admin_url    The migrator admin page URL.
     * @return array<int, array{run_id: string, source: string, mode: string, status: string, started: string, finished: string, actions: array, urls: array}>
     */
    public static function rows(array $entries, array $source_names, string $admin_url): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                'run_id'   => (string) $entry['run_id'],
                'source'   => $source_names[ $entry['source'] ] ?? (string) $entry['source'],
                'mode'     => self::modeLabel((string) $entry['mode']),
                'status'   => self::statusLabel((string) $entry['status']),
                'started'  => self::dateLabel((int) $entry['started_at']),
                'finished' => self::dateLabel((int) $entry['finished_at']),
                'actions'  => self::actionsFor((string) $entry['mode'], (string) $entry['status']),
                'urls'     => [
                    'report'    => MigrationUrls::report($admin_url, (string) $entry['run_id']),
                    'crosswalk' => MigrationUrls::crosswalk($admin_url, (string) $entry['run_id']),
                    'log'       => MigrationUrls::log($admin_url, (string) $entry['run_id']),
                    'rollback'  => MigrationUrls::rollbackConfirm($admin_url, (string) $entry['run_id']),
                ],
            ];
        }
        return $rows;
    }


    /**
     * A site-timezone date and time, or '' for a time that was never set.
     */
    public static function dateLabel(int $timestamp): string
    {
        return $timestamp > 0 ? (string) wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : '';
    }
}
// End of file RunPresenter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\ui/RunPresenter.php
