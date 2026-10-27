<?php

namespace EventEspresso\EventMigrator\domain\services\migration\ui;

use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\services\migration\config\MigrationConfig;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunRecord;

/**
 * Class ReportView
 *
 * Shapes a run record for the report template (REQ-022). Fields are plain text,
 * except the warning "message" fields, which MigrationWarning::message() has
 * already escaped; the template escapes everything else.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class ReportView
{
    /**
     * @param MigrationRunRecord $record    The run.
     * @param string             $admin_url The migrator admin page URL (for the links the report offers).
     * @return array<string, mixed>
     */
    public static function build(MigrationRunRecord $record, string $admin_url): array
    {
        $run_id   = $record->runId();
        $actions  = RunPresenter::actionsFor($record->mode(), $record->status());
        $entities = self::entities($record);
        return [
            'run_id'       => $run_id,
            'source'       => $record->source(),
            'mode'         => RunPresenter::modeLabel($record->mode()),
            'is_dry_run'   => $record->mode() === MigrationConfig::MODE_DRY_RUN,
            'status'       => $record->status(),
            'status_label' => RunPresenter::statusLabel($record->status()),
            'started'      => RunPresenter::dateLabel($record->startedAt()),
            'finished'     => RunPresenter::dateLabel($record->finishedAt()),
            'entities'     => $entities,
            'nothing'      => self::nothingHappened($entities),
            'warnings'     => self::warnings($record),
            'reuse'        => self::reuse($record),
            'sold'         => self::sold($record, $record->mode() === MigrationConfig::MODE_DRY_RUN),
            'per_event'    => self::perEvent($record),
            'payments'     => self::payments($record),
            'refusals'     => self::refusals($record),
            'links'        => [
                'crosswalk' => $actions['crosswalk'] ? MigrationUrls::crosswalk($admin_url, $run_id) : '',
                'log'       => $actions['log'] ? MigrationUrls::log($admin_url, $run_id) : '',
                'rollback'  => $actions['rollback'] ? MigrationUrls::rollbackConfirm($admin_url, $run_id) : '',
                'runs'      => MigrationUrls::runs($admin_url),
            ],
        ];
    }


    /**
     * @return array<int, array{label: string, source: string, created: int, reused: int, skipped: int, failed: int, unaccounted: string}>
     */
    private static function entities(MigrationRunRecord $record): array
    {
        $rows = [];
        foreach ((array) ($record->reconciliation()['entities'] ?? []) as $entity => $row) {
            $row    = (array) $row;
            $rows[] = [
                'label'       => RunPresenter::entityLabel((string) $entity),
                'source'      => isset($row['source']) ? (string) (int) $row['source'] : '',
                'created'     => (int) ($row['created'] ?? 0),
                'reused'      => (int) ($row['reused'] ?? 0),
                'skipped'     => (int) ($row['skipped'] ?? 0),
                'failed'      => (int) ($row['failed'] ?? 0),
                'unaccounted' => isset($row['unaccounted']) ? (string) (int) $row['unaccounted'] : '',
            ];
        }
        return $rows;
    }


    /**
     * Warnings grouped by rule, most frequent first: the rule id (stable, REQ-106), how often it occurred, one
     * example message and a few source ids.
     *
     * @return array<int, array{rule: string, count: int, example_html: string, samples: array<int, string>}>
     */
    private static function warnings(MigrationRunRecord $record): array
    {
        $examples = [];
        $samples  = [];
        foreach ($record->warningsSample() as $warning) {
            $rule = $warning->rule();
            if (! isset($examples[ $rule ])) {
                $examples[ $rule ] = $warning->message();
            }
            if (count($samples[ $rule ] ?? []) < 5 && $warning->sourceId() !== '') {
                $samples[ $rule ][] = $warning->sourceType() . ':' . $warning->sourceId();
            }
        }
        $counts = $record->warningsByRule();
        arsort($counts);
        $rows = [];
        foreach ($counts as $rule => $count) {
            $rows[] = [
                'rule'         => (string) $rule,
                'count'        => (int) $count,
                // MigrationWarning::message() and WarningRule::label() both return escaped text.
                'example_html' => $examples[ $rule ] ?? WarningRule::label((string) $rule),
                'samples'      => $samples[ $rule ] ?? [],
            ];
        }
        return $rows;
    }


    /**
     * @return array<int, array{label: string, id: int, url: string}>
     */
    private static function reuse(MigrationRunRecord $record): array
    {
        $rows = [];
        foreach ($record->reuseSample() as $note) {
            $rows[] = [
                'label' => $note['model'],
                'id'    => $note['id'],
                'url'   => RunPresenter::recordUrl($note['model'], $note['id']),
            ];
        }
        return $rows;
    }


    /**
     * @return array<int, array{ticket: int, delta: int}>
     */
    /**
     * True when no record of any kind was created, reused, skipped or failed: the source had nothing to migrate
     * under the run's options.
     */
    private static function nothingHappened(array $entities): bool
    {
        foreach ($entities as $row) {
            if ($row['created'] + $row['reused'] + $row['skipped'] + $row['failed'] > 0) {
                return false;
            }
        }
        return true;
    }


    /**
     * Registrations per event, source against Event Espresso. A dry run's event ids are placeholders, shown as ''.
     *
     * @return array{rows: array<int, array{source_event_id: string, event_id: string, source: int, ee: int}>,
     *     more: int}
     */
    private static function perEvent(MigrationRunRecord $record): array
    {
        $section = (array) ($record->reconciliation()['registrations_per_event'] ?? []);
        $rows    = [];
        foreach ((array) ($section['rows'] ?? []) as $row) {
            $row      = (array) $row;
            $event_id = (int) ($row['event_id'] ?? 0);
            $rows[]   = [
                'source_event_id' => (string) ($row['source_event_id'] ?? ''),
                'event_id'        => $event_id > 0 ? '#' . $event_id : '',
                'source'          => (int) ($row['source'] ?? 0),
                'ee'              => (int) ($row['ee'] ?? 0),
            ];
        }
        return ['rows' => $rows, 'more' => (int) ($section['more'] ?? 0)];
    }


    /**
     * Approved payments per source currency, source against Event Espresso (stored in the site currency).
     *
     * @return array<int, array{currency: string, source: float, ee: float}>
     */
    private static function payments(MigrationRunRecord $record): array
    {
        $section = (array) ($record->reconciliation()['payments_by_currency'] ?? []);
        $rows    = [];
        foreach ((array) ($section['rows'] ?? []) as $currency => $row) {
            $row    = (array) $row;
            $rows[] = [
                'currency' => (string) $currency !== '' ? (string) $currency : __('Unknown', 'migrate-events-to-event-espresso'),
                'source'   => (float) ($row['source'] ?? 0.0),
                'ee'       => (float) ($row['ee'] ?? 0.0),
            ];
        }
        return $rows;
    }


    /**
     * A dry run's ticket ids are placeholders for tickets that do not exist yet, so they are labelled as new.
     */
    private static function sold(MigrationRunRecord $record, bool $dry_run): array
    {
        $rows = [];
        foreach ($record->soldAdjustments() as $ticket_id => $delta) {
            $rows[] = [
                'ticket' => $dry_run || (int) $ticket_id <= 0
                    ? __('New ticket', 'migrate-events-to-event-espresso')
                    : '#' . (int) $ticket_id,
                'delta'  => (int) $delta,
            ];
        }
        return $rows;
    }


    /**
     * @return array<int, array{label: string, id: int, reason: string, url: string}>
     */
    private static function refusals(MigrationRunRecord $record): array
    {
        $rows = [];
        foreach ($record->refusals() as $refusal) {
            $rows[] = [
                'label'  => $refusal['model'],
                'id'     => $refusal['id'],
                'reason' => RunPresenter::reasonLabel($refusal['reason']),
                'url'    => RunPresenter::recordUrl($refusal['model'], $refusal['id']),
            ];
        }
        return $rows;
    }
}
// End of file ReportView.php
// Location: EventEspresso\EventMigrator\domain\services\migration\ui/ReportView.php
