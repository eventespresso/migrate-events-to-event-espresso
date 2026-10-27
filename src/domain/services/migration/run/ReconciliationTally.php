<?php

namespace EventEspresso\EventMigrator\domain\services\migration\run;

/**
 * Class ReconciliationTally
 *
 * What the source held and what the run migrated, collected booking by booking for the reconciliation
 * (REQ-022): registrations per event (the first MAX_EVENTS events, then only a count of the rest) and approved
 * payment totals per source currency. Bookings an earlier run already migrated are not tallied. Saved with the
 * run record, so a run that spans many batch requests keeps one tally.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class ReconciliationTally
{
    /**
     * Events given their own row; the report notes how many more there were.
     */
    public const MAX_EVENTS = 500;

    /**
     * @var array<string, array{event_id: int, source: int, migrated: int}> source event id => row
     */
    private array $events = [];

    private int $more_events = 0;

    private string $last_more_event = '';

    /**
     * @var array<string, float> source currency => approved source payments
     */
    private array $source_payments = [];

    private float $migrated_payments = 0.0;

    private string $site_currency = '';


    /**
     * @param string $source_event_id The booking's source event.
     * @param int    $event_id        Its Event Espresso event (a placeholder id in a dry run).
     * @param int    $source          Registrations the source booking holds (its seats).
     * @param int    $migrated        Registrations the run created for it.
     */
    public function addBooking(string $source_event_id, int $event_id, int $source, int $migrated): void
    {
        if (! isset($this->events[ $source_event_id ]) && count($this->events) >= self::MAX_EVENTS) {
            // Bookings arrive event by event, so a change of event id is a new event.
            if ($source_event_id !== $this->last_more_event) {
                $this->more_events++;
                $this->last_more_event = $source_event_id;
            }
            return;
        }
        $row              = $this->events[ $source_event_id ]
            ?? ['event_id' => $event_id, 'source' => 0, 'migrated' => 0];
        $row['source']   += $source;
        $row['migrated'] += $migrated;

        $this->events[ $source_event_id ] = $row;
    }


    /**
     * @param array<string, float> $source        Source currency => approved amounts in one booking.
     * @param string               $site_currency The site currency the run stores every amount in.
     * @param float                $migrated      Approved amounts the run created for the booking.
     */
    public function addPayments(array $source, string $site_currency, float $migrated): void
    {
        foreach ($source as $currency => $amount) {
            $currency                           = (string) $currency;
            $this->source_payments[ $currency ] = ($this->source_payments[ $currency ] ?? 0.0) + $amount;
        }
        $this->migrated_payments += $migrated;
        $this->site_currency      = $site_currency;
    }


    /**
     * @return array<string, array{event_id: int, source: int, migrated: int}>
     */
    public function events(): array
    {
        return $this->events;
    }


    public function moreEvents(): int
    {
        return $this->more_events;
    }


    /**
     * @return array<string, float>
     */
    public function sourcePayments(): array
    {
        return $this->source_payments;
    }


    public function migratedPayments(): float
    {
        return $this->migrated_payments;
    }


    public function siteCurrency(): string
    {
        return $this->site_currency;
    }


    public function toArray(): array
    {
        return [
            'events'            => $this->events,
            'more_events'       => $this->more_events,
            'last_more_event'   => $this->last_more_event,
            'source_payments'   => $this->source_payments,
            'migrated_payments' => $this->migrated_payments,
            'site_currency'     => $this->site_currency,
        ];
    }


    public static function fromArray(array $data): ReconciliationTally
    {
        $tally = new ReconciliationTally();
        foreach ((array) ($data['events'] ?? []) as $source_event_id => $row) {
            $row                                        = (array) $row;
            $tally->events[ (string) $source_event_id ] = [
                'event_id' => (int) ($row['event_id'] ?? 0),
                'source'   => (int) ($row['source'] ?? 0),
                'migrated' => (int) ($row['migrated'] ?? 0),
            ];
        }
        $tally->more_events     = (int) ($data['more_events'] ?? 0);
        $tally->last_more_event = (string) ($data['last_more_event'] ?? '');
        foreach ((array) ($data['source_payments'] ?? []) as $currency => $amount) {
            $tally->source_payments[ (string) $currency ] = (float) $amount;
        }
        $tally->migrated_payments = (float) ($data['migrated_payments'] ?? 0.0);
        $tally->site_currency     = (string) ($data['site_currency'] ?? '');
        return $tally;
    }
}
// End of file ReconciliationTally.php
// Location: EventEspresso\EventMigrator\domain\services\migration\run/ReconciliationTally.php
