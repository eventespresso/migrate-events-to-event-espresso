<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventin;

use DateTimeImmutable;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceZone;

/**
 * Class EventinDates
 *
 * How Eventin stores dates (research profile §2.3): a local `Y-m-d` date and
 * a `g:i A` time ("9:00 AM") in separate meta, in the event's own timezone
 * (an IANA name, or "UTC+6"). Values that cannot be read ("Invalid Date")
 * are treated as unset.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventinDates
{
    /**
     * A zone PHP can read from Eventin's event_timezone; null when blank or unreadable.
     */
    public static function zone(?string $stored): ?string
    {
        return SourceZone::normalize($stored);
    }


    /**
     * A local date and time as a source value; null when the date is unreadable.
     *
     * @param string      $date The "Y-m-d" date.
     * @param string      $time The time ("9:00 AM", "21:00"); midnight when blank or unreadable.
     * @param string|null $zone The event's readable zone, or null for the site zone.
     */
    public static function local(string $date, string $time, ?string $zone): ?SourceDateTime
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date) !== 1) {
            return null;
        }
        $clock  = self::clock(trim($time));
        $value  = substr($date, 0, 10) . ' ' . $clock;
        return $zone !== null
            ? new SourceDateTime($value, TimezoneModel::PER_EVENT, $zone)
            : new SourceDateTime($value, TimezoneModel::SITE_LOCAL);
    }


    /**
     * A free-form local datetime (a registration deadline) as a source value; null when unreadable.
     */
    public static function parse(string $text, ?string $zone): ?SourceDateTime
    {
        $text = trim($text);
        if ($text === '' || strtotime($text) === false) {
            return null;
        }
        $parsed = new DateTimeImmutable($text);
        $value  = $parsed->format('Y-m-d H:i:s');
        return $zone !== null
            ? new SourceDateTime($value, TimezoneModel::PER_EVENT, $zone)
            : new SourceDateTime($value, TimezoneModel::SITE_LOCAL);
    }


    private static function clock(string $time): string
    {
        if ($time === '') {
            return '00:00:00';
        }
        $parsed = strtotime('1970-01-01 ' . $time);
        return $parsed === false ? '00:00:00' : gmdate('H:i:s', $parsed);
    }
}
// End of file EventinDates.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventin/EventinDates.php
