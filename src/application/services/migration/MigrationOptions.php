<?php

namespace EventEspresso\EventMigrator\application\services\migration;

use DateTimeImmutable;
use DateTimeZone;
use EventEspresso\core\exceptions\InvalidDataTypeException;

/**
 * Class MigrationOptions
 *
 * The choices an admin makes for one migration run (REQ-004, REQ-005,
 * REQ-006, REQ-007, REQ-011). Built only through fromArray(), which validates
 * every value, so a MigrationOptions instance is always well-formed.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class MigrationOptions
{
    public const HORIZON_ALL      = 'all';

    public const HORIZON_UPCOMING = 'upcoming';

    public const HORIZON_AFTER    = 'after:';

    public const DOMAINS_EVENTS   = 'events';

    public const DOMAINS_BOOKINGS = 'events_bookings';

    public const RECURRENCE_SINGLE_EVENT         = 'single_event';

    public const RECURRENCE_EVENT_PER_OCCURRENCE = 'event_per_occurrence';

    private string $source;

    private string $horizon;

    private string $domains;

    private string $recurrence;

    private bool $preserve_status;

    private bool $dry_run;


    private function __construct(
        string $source,
        string $horizon,
        string $domains,
        string $recurrence,
        bool $preserve_status,
        bool $dry_run
    ) {
        $this->source          = $source;
        $this->horizon         = $horizon;
        $this->domains         = $domains;
        $this->recurrence      = $recurrence;
        $this->preserve_status = $preserve_status;
        $this->dry_run         = $dry_run;
    }


    /**
     * Builds validated options from form input, a config option or batch extra_data.
     *
     * @param array $data Keys: source (slug), horizon ("all" | "upcoming" | "after:YYYY-MM-DD"),
     *                    domains ("events" | "events_bookings"), recurrence ("single_event" |
     *                    "event_per_occurrence"), preserve_status (bool), dry_run (bool).
     * @throws InvalidDataTypeException When any value is missing or outside its allowed set.
     */
    public static function fromArray(array $data): MigrationOptions
    {
        $source = (string) ($data['source'] ?? '');
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/D', $source)) {
            throw new InvalidDataTypeException('$data[source]', esc_html($source), 'a lower-case source slug');
        }
        $horizon = (string) ($data['horizon'] ?? self::HORIZON_ALL);
        if (! self::isValidHorizon($horizon)) {
            throw new InvalidDataTypeException(
                '$data[horizon]',
                esc_html($horizon),
                '"all", "upcoming" or "after:YYYY-MM-DD"'
            );
        }
        $domains = (string) ($data['domains'] ?? self::DOMAINS_EVENTS);
        if (! in_array($domains, [self::DOMAINS_EVENTS, self::DOMAINS_BOOKINGS], true)) {
            throw new InvalidDataTypeException(
                '$data[domains]',
                esc_html($domains),
                '"events" or "events_bookings"'
            );
        }
        $recurrence = (string) ($data['recurrence'] ?? self::RECURRENCE_SINGLE_EVENT);
        if (
            ! in_array(
                $recurrence,
                [self::RECURRENCE_SINGLE_EVENT, self::RECURRENCE_EVENT_PER_OCCURRENCE],
                true
            )
        ) {
            throw new InvalidDataTypeException(
                '$data[recurrence]',
                esc_html($recurrence),
                '"single_event" or "event_per_occurrence"'
            );
        }
        return new MigrationOptions(
            $source,
            $horizon,
            $domains,
            $recurrence,
            self::strictBool($data['preserve_status'] ?? false, '$data[preserve_status]'),
            self::strictBool($data['dry_run'] ?? false, '$data[dry_run]')
        );
    }


    /**
     * Accepts real booleans and the form strings filter_var() recognises; anything else is rejected.
     *
     * @throws InvalidDataTypeException
     */
    private static function strictBool($value, string $name): bool
    {
        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            throw new InvalidDataTypeException(
                esc_html($name),
                esc_html(is_scalar($value) ? (string) $value : gettype($value)),
                'boolean'
            );
        }
        return $bool;
    }


    /**
     * "all", "upcoming", or "after:" followed by a real calendar date in strict Y-m-d form.
     */
    public static function isValidHorizon(string $horizon): bool
    {
        if ($horizon === self::HORIZON_ALL || $horizon === self::HORIZON_UPCOMING) {
            return true;
        }
        if (strpos($horizon, self::HORIZON_AFTER) !== 0) {
            return false;
        }
        $date   = substr($horizon, strlen(self::HORIZON_AFTER));
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }


    public function source(): string
    {
        return $this->source;
    }


    public function horizon(): string
    {
        return $this->horizon;
    }


    public function isAllEvents(): bool
    {
        return $this->horizon === self::HORIZON_ALL;
    }


    public function isUpcomingOnly(): bool
    {
        return $this->horizon === self::HORIZON_UPCOMING;
    }


    /**
     * The Y-m-d date of an "after:" horizon, or null for the other horizons.
     */
    public function horizonDate(): ?string
    {
        return strpos($this->horizon, self::HORIZON_AFTER) === 0
            ? substr($this->horizon, strlen(self::HORIZON_AFTER))
            : null;
    }


    public function domains(): string
    {
        return $this->domains;
    }


    public function includesBookings(): bool
    {
        return $this->domains === self::DOMAINS_BOOKINGS;
    }


    public function recurrence(): string
    {
        return $this->recurrence;
    }


    public function isEventPerOccurrence(): bool
    {
        return $this->recurrence === self::RECURRENCE_EVENT_PER_OCCURRENCE;
    }


    public function preserveStatus(): bool
    {
        return $this->preserve_status;
    }


    public function isDryRun(): bool
    {
        return $this->dry_run;
    }


    /**
     * The same shape fromArray() accepts, for the config option and batch extra_data.
     */
    public function toArray(): array
    {
        return [
            'source'          => $this->source,
            'horizon'         => $this->horizon,
            'domains'         => $this->domains,
            'recurrence'      => $this->recurrence,
            'preserve_status' => $this->preserve_status,
            'dry_run'         => $this->dry_run,
        ];
    }
}
// End of file MigrationOptions.php
// Location: EventEspresso\EventMigrator\application\services\migration/MigrationOptions.php
