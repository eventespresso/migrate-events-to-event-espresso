<?php

namespace EventEspresso\EventMigrator\domain\services\migration\report;

use EventEspresso\EventMigrator\application\services\migration\SourceInventory;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\run\MigrationRunRecord;

/**
 * Class RunReportBuilder
 *
 * Builds a run's reconciliation (REQ-022): for every entity, the source
 * inventory taken when the run started against what the run created,
 * reused, skipped and failed, the records the crosswalk holds for the run,
 * registrations per event and approved payments per currency (source
 * against Event Espresso), the warning totals per rule with a few samples,
 * and the sold-count adjustments. Pure: the job supplies the inventory
 * snapshot, the crosswalk counts and a real run's Event Espresso totals.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class RunReportBuilder
{
    /**
     * Report entity => SourceInventory count key.
     */
    public const ENTITY_INVENTORY = [
        'event'    => SourceInventory::COUNT_EVENTS,
        'venue'    => SourceInventory::COUNT_VENUES,
        'category' => SourceInventory::COUNT_CATEGORIES,
        'ticket'   => SourceInventory::COUNT_TICKETS,
        'attendee' => SourceInventory::COUNT_ATTENDEES,
        'booking'  => SourceInventory::COUNT_BOOKINGS,
        'payment'  => SourceInventory::COUNT_PAYMENTS,
    ];

    /**
     * Report entity => EE model the crosswalk records it under.
     */
    public const ENTITY_MODELS = [
        'event'        => CrosswalkKeys::MODEL_EVENT,
        'datetime'     => CrosswalkKeys::MODEL_DATETIME,
        'ticket'       => CrosswalkKeys::MODEL_TICKET,
        'venue'        => CrosswalkKeys::MODEL_VENUE,
        'category'     => CrosswalkKeys::MODEL_TERM_TAXONOMY,
        'attendee'     => CrosswalkKeys::MODEL_ATTENDEE,
        'booking'      => CrosswalkKeys::MODEL_TRANSACTION,
        'registration' => CrosswalkKeys::MODEL_REGISTRATION,
        'payment'      => CrosswalkKeys::MODEL_PAYMENT,
    ];

    /**
     * Samples kept per warning rule in the reconciliation; the full list is in the run log.
     */
    public const SAMPLES_PER_RULE = 5;


    /**
     * Sets the record's reconciliation.
     *
     * @param MigrationRunRecord $record           The finished run.
     * @param array<string, int> $source_counts    SourceInventory::counts() taken when the run started.
     * @param array<string, int> $crosswalk_counts EE model => records the crosswalk holds for the run;
     *                                             empty for a dry run.
     * @param array|null         $actuals          What a real run left in Event Espresso, from the crosswalk:
     *                                             {registrations: event id => count, approved_payments: float};
     *                                             null for a dry run, whose figures come from the mapped records.
     */
    public function build(
        MigrationRunRecord $record,
        array $source_counts,
        array $crosswalk_counts = [],
        ?array $actuals = null
    ): void {
        $record->setReconciliation(
            [
                'entities'                => $this->entities($record, $source_counts, $crosswalk_counts),
                'registrations_per_event' => $this->registrationsPerEvent($record, $actuals),
                'payments_by_currency'    => $this->paymentsByCurrency($record, $actuals),
                'warnings'                => $this->warnings($record),
                'sold_adjustments'        => [
                    'tickets' => count($record->soldAdjustments()),
                    'total'   => array_sum($record->soldAdjustments()),
                ],
            ]
        );
    }


    /**
     * @return array<string, array{source: ?int, created: int, reused: int, skipped: int, failed: int,
     *     unaccounted: ?int, in_crosswalk: ?int}>
     */
    private function entities(MigrationRunRecord $record, array $source_counts, array $crosswalk_counts): array
    {
        $entities = array_unique(
            array_merge(array_keys(self::ENTITY_INVENTORY), array_keys(self::ENTITY_MODELS), array_keys($record->counts()))
        );
        $rows     = [];
        foreach ($entities as $entity) {
            $row       = [
                'created' => $record->count($entity, MigrationRunRecord::COUNT_CREATED),
                'reused'  => $record->count($entity, MigrationRunRecord::COUNT_REUSED),
                'skipped' => $record->count($entity, MigrationRunRecord::COUNT_SKIPPED),
                'failed'  => $record->count($entity, MigrationRunRecord::COUNT_FAILED),
            ];
            $key       = self::ENTITY_INVENTORY[ $entity ] ?? '';
            $source    = $key !== '' && isset($source_counts[ $key ]) ? (int) $source_counts[ $key ] : null;
            $model     = self::ENTITY_MODELS[ $entity ] ?? '';
            $crosswalk = $model !== '' && isset($crosswalk_counts[ $model ]) ? (int) $crosswalk_counts[ $model ] : null;
            if ($source === null && $crosswalk === null && array_sum($row) === 0) {
                continue;
            }
            $rows[ $entity ] = ['source' => $source] + $row + [
                'unaccounted'  => $source !== null ? $source - array_sum($row) : null,
                'in_crosswalk' => $crosswalk,
            ];
        }
        return $rows;
    }


    /**
     * Source registrations against Event Espresso registrations for each event with bookings.
     *
     * @return array{rows: array<int, array{source_event_id: string, event_id: int, source: int, ee: int}>,
     *     more: int}
     */
    private function registrationsPerEvent(MigrationRunRecord $record, ?array $actuals): array
    {
        $tally = $record->tally();
        $rows  = [];
        foreach ($tally->events() as $source_event_id => $event) {
            $rows[] = [
                'source_event_id' => (string) $source_event_id,
                'event_id'        => $event['event_id'],
                'source'          => $event['source'],
                'ee'              => $actuals !== null
                    ? (int) ($actuals['registrations'][ $event['event_id'] ] ?? 0)
                    : $event['migrated'],
            ];
        }
        return ['rows' => $rows, 'more' => $tally->moreEvents()];
    }


    /**
     * Approved source payments per source currency against the approved Event Espresso payments, which are all
     * in the site currency.
     *
     * @return array{site_currency: string, rows: array<string, array{source: float, ee: float}>}
     */
    private function paymentsByCurrency(MigrationRunRecord $record, ?array $actuals): array
    {
        $tally = $record->tally();
        $rows  = [];
        foreach ($tally->sourcePayments() as $currency => $amount) {
            $rows[ $currency ] = ['source' => round($amount, 2), 'ee' => 0.0];
        }
        if ($rows === []) {
            return ['site_currency' => $tally->siteCurrency(), 'rows' => []];
        }
        $site                = $tally->siteCurrency();
        $rows[ $site ]       = $rows[ $site ] ?? ['source' => 0.0, 'ee' => 0.0];
        $rows[ $site ]['ee'] = round(
            $actuals !== null ? (float) ($actuals['approved_payments'] ?? 0.0) : $tally->migratedPayments(),
            2
        );
        ksort($rows);
        return ['site_currency' => $site, 'rows' => $rows];
    }


    /**
     * @return array{total: int, by_rule: array<string, array{count: int, samples: array}>}
     */
    private function warnings(MigrationRunRecord $record): array
    {
        $by_rule = [];
        foreach ($record->warningsByRule() as $rule => $count) {
            $by_rule[ $rule ] = ['count' => $count, 'samples' => []];
        }
        foreach ($record->warningsSample() as $warning) {
            $rule = $warning->rule();
            if (isset($by_rule[ $rule ]) && count($by_rule[ $rule ]['samples']) < self::SAMPLES_PER_RULE) {
                $by_rule[ $rule ]['samples'][] = ['type' => $warning->sourceType(), 'id' => $warning->sourceId()];
            }
        }
        ksort($by_rule);
        return ['total' => $record->warningsTotal(), 'by_rule' => $by_rule];
    }
}
// End of file RunReportBuilder.php
// Location: EventEspresso\EventMigrator\domain\services\migration\report/RunReportBuilder.php
