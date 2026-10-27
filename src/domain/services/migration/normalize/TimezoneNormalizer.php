<?php

namespace EventEspresso\EventMigrator\domain\services\migration\normalize;

use DateTimeImmutable;
use DateTimeZone;
use EventEspresso\core\exceptions\InvalidDataTypeException;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use Exception;

/**
 * Class TimezoneNormalizer
 *
 * Converts a source date/time to a UTC instant according to the source's
 * timezone model (REQ-012). Pure: no database, no WordPress options. Every
 * result is a DateTimeImmutable in UTC; persisters convert it with
 * DateTime::createFromImmutable() at the EE model boundary.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class TimezoneNormalizer
{
    /**
     * Raw values of this many digits or more are unix timestamps.
     */
    private const MIN_TIMESTAMP_DIGITS = 10;

    /**
     * Accepted wall-clock layouts, most specific first. The "!" resets unparsed fields to the epoch.
     */
    private const WALL_CLOCK_FORMATS = [
        '!Y-m-d H:i:s',
        '!Y-m-d H:i',
        '!Y-m-d\TH:i:s',
        '!Y-m-d\TH:i',
        '!Y-m-d',
    ];

    private string $site_timezone;

    private DateTimeZone $site_zone;

    private DateTimeZone $utc;


    /**
     * @param string $site_timezone The site timezone identifier, e.g. "America/Denver".
     * @throws InvalidDataTypeException When the identifier is not a valid timezone.
     */
    public function __construct(string $site_timezone)
    {
        $zone = $this->zone($site_timezone);
        if (! $zone instanceof DateTimeZone) {
            throw new InvalidDataTypeException(
                '$site_timezone',
                esc_html($site_timezone),
                'a valid timezone identifier'
            );
        }
        $this->site_timezone = $site_timezone;
        $this->site_zone     = $zone;
        $this->utc           = new DateTimeZone('UTC');
    }


    public function siteTimezone(): string
    {
        return $this->site_timezone;
    }


    /**
     * The UTC instant a source value stands for.
     *
     * @param SourceDateTime $dt       The source value and its timezone model.
     * @param string|null    $event_tz The event's own timezone, used by the per-event models when the value has none.
     * @throws InvalidDataTypeException When the timezone model is unknown.
     */
    public function toUtc(SourceDateTime $dt, ?string $event_tz = null): DateTimeImmutable
    {
        if (! TimezoneModel::isValid($dt->model())) {
            throw new InvalidDataTypeException('$dt->model()', esc_html($dt->model()), 'a TimezoneModel constant');
        }
        $raw = trim($dt->raw());
        if ($this->isTimestamp($raw)) {
            $instant = new DateTimeImmutable('@' . $raw);
            if ($dt->model() !== TimezoneModel::WALL_CLOCK_AS_UTC) {
                return $instant;
            }
            // The digits are a wall clock written as if it were UTC: re-read them in the event's zone.
            return $this->parseWallClock($instant->format('Y-m-d H:i:s'), $this->localZone($dt, $event_tz));
        }
        $zone = $dt->model() === TimezoneModel::UTC ? $this->utc : $this->localZone($dt, $event_tz);
        return $this->parseWallClock($raw, $zone);
    }


    /**
     * The UTC start and end of an event, expanding all-day values to the whole
     * site-local day (REQ-012). An empty end falls back to the start's day for
     * all-day events.
     *
     * @param SourceDateTime $start    The source start.
     * @param SourceDateTime $end      The source end.
     * @param string|null    $event_tz The event's own timezone, when the source gives one.
     * @return DateTimeImmutable[] The UTC start at index 0 and the UTC end at index 1.
     */
    public function normalizeRange(SourceDateTime $start, SourceDateTime $end, ?string $event_tz = null): array
    {
        if (! $start->isAllDay() && ! $end->isAllDay()) {
            return [$this->toUtc($start, $event_tz), $this->toUtc($end, $event_tz)];
        }
        $start_day = $this->sourceLocalDate($start, $event_tz);
        $end_day   = trim($end->raw()) === '' ? $start_day : $this->sourceLocalDate($end, $event_tz);
        return [
            $this->expandAllDay(new DateTimeImmutable($start_day, $this->site_zone))[0],
            $this->expandAllDay(new DateTimeImmutable($end_day, $this->site_zone))[1],
        ];
    }


    /**
     * 00:00:00 to 23:59:59 of the given calendar date in the site timezone, as UTC instants.
     *
     * @param DateTimeImmutable $local_date Only its Y-m-d is used, read as a site-local date.
     * @return DateTimeImmutable[] The UTC start at index 0 and the UTC end at index 1.
     */
    public function expandAllDay(DateTimeImmutable $local_date): array
    {
        $day = new DateTimeImmutable($local_date->format('Y-m-d'), $this->site_zone);
        return [
            $day->setTime(0, 0, 0)->setTimezone($this->utc),
            $day->setTime(23, 59, 59)->setTimezone($this->utc),
        ];
    }


    /**
     * Whether converting from the given zone to the site zone would shift times. Two zones with the same
     * UTC offset in January and July of the reference year are treated as equal.
     *
     * @param string|null            $zone The event timezone; empty means "same as the site".
     * @param DateTimeImmutable|null $at   The instant whose year supplies the reference dates; defaults to now.
     */
    public function differsFromSite(?string $zone, ?DateTimeImmutable $at = null): bool
    {
        if ($zone === null || trim($zone) === '') {
            return false;
        }
        $other = $this->zone($zone);
        if (! $other instanceof DateTimeZone) {
            return true;
        }
        if (strcasecmp($other->getName(), $this->site_zone->getName()) === 0) {
            return false;
        }
        $year = (int) ($at ?? new DateTimeImmutable('now', $this->utc))->format('Y');
        foreach (['01-01', '07-01'] as $month_day) {
            $reference = new DateTimeImmutable($year . '-' . $month_day . ' 12:00:00', $this->utc);
            if ($other->getOffset($reference) !== $this->site_zone->getOffset($reference)) {
                return true;
            }
        }
        return false;
    }


    /**
     * The timezone-differs warning for a value stored against an event timezone that is not the site's, or
     * null when no conversion warning applies (REQ-012).
     *
     * @param SourceDateTime $dt          The source value.
     * @param string|null    $event_tz    The event's own timezone, when the source gives one.
     * @param string         $source_type The source entity type the warning is filed under.
     * @param string         $source_id   The source entity id the warning is filed under.
     */
    public function warningFor(
        SourceDateTime $dt,
        ?string $event_tz,
        string $source_type,
        string $source_id
    ): ?MigrationWarning {
        if (! in_array($dt->model(), [TimezoneModel::PER_EVENT, TimezoneModel::WALL_CLOCK_AS_UTC], true)) {
            return null;
        }
        $zone = trim((string) ($dt->timezone() ?? $event_tz ?? ''));
        if ($zone === '' || ! $this->differsFromSite($zone)) {
            return null;
        }
        return new MigrationWarning(
            WarningRule::TIMEZONE_DIFFERS,
            $source_type,
            $source_id,
            [$zone, $this->site_timezone]
        );
    }


    /**
     * The calendar date (Y-m-d) the source meant, read in the zone the source wrote it in.
     */
    private function sourceLocalDate(SourceDateTime $dt, ?string $event_tz): string
    {
        $raw = trim($dt->raw());
        if ($dt->model() === TimezoneModel::WALL_CLOCK_AS_UTC && $this->isTimestamp($raw)) {
            return gmdate('Y-m-d', (int) $raw);
        }
        $instant = $this->toUtc($dt, $event_tz);
        $zone    = $dt->model() === TimezoneModel::UTC ? $this->site_zone : $this->localZone($dt, $event_tz);
        return $instant->setTimezone($zone)->format('Y-m-d');
    }


    /**
     * The zone a wall-clock value is written in: the site's for SITE_LOCAL, otherwise the value's own, then
     * the event's, then the site's.
     */
    private function localZone(SourceDateTime $dt, ?string $event_tz): DateTimeZone
    {
        if ($dt->model() === TimezoneModel::SITE_LOCAL || $dt->model() === TimezoneModel::UTC) {
            return $this->site_zone;
        }
        foreach ([$dt->timezone(), $event_tz] as $candidate) {
            $zone = $this->zone((string) $candidate);
            if ($zone instanceof DateTimeZone) {
                return $zone;
            }
        }
        return $this->site_zone;
    }


    private function zone(string $identifier): ?DateTimeZone
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }
        try {
            return new DateTimeZone($identifier);
        } catch (Exception $exception) {
            return null;
        }
    }


    private function isTimestamp(string $raw): bool
    {
        return preg_match('/^\d{' . self::MIN_TIMESTAMP_DIGITS . ',}$/D', $raw) === 1;
    }


    /**
     * Reads a wall-clock string in a zone and returns the UTC instant. A time that does not exist in the zone
     * (the skipped hour of a spring-forward day) is moved forward by PHP, which is a well-defined conversion;
     * malformed or impossible dates such as 2026-02-30 are rejected.
     *
     * @throws MigrationRecordException When no accepted layout matches.
     */
    private function parseWallClock(string $value, DateTimeZone $zone): DateTimeImmutable
    {
        // Control characters can never be part of a date, and PHP refuses null bytes outright.
        $formats = preg_match('/^[\x20-\x7E]*$/D', $value) === 1 ? self::WALL_CLOCK_FORMATS : [];
        foreach ($formats as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, $zone);
            if ($parsed === false) {
                continue;
            }
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }
            return $parsed->setTimezone($this->utc);
        }
        // The message is escaped; the rule and entity type are constants.
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        throw new MigrationRecordException(
            sprintf(
                /* translators: %s: the date value the source stored */
                esc_html__('The source date "%s" could not be read.', 'migrate-events-to-event-espresso'),
                esc_html(mb_substr($value, 0, 40))
            ),
            WarningRule::RECORD_FAILED,
            'datetime'
        );
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }
}
// End of file TimezoneNormalizer.php
// Location: EventEspresso\EventMigrator\domain\services\migration\normalize/TimezoneNormalizer.php
