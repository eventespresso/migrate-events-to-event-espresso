<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_manager;

use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceZone;

/**
 * Class EventsManagerDates
 *
 * How Events Manager stores dates (research profile §3, §6): each em_events
 * row has the local wall-clock date and time plus its own event_timezone
 * (a PHP zone name, or an offset written "UTC+2"), and the same instant in
 * UTC in event_start/event_end. Local values with a readable zone are used so
 * the run can report events in another timezone; otherwise the UTC columns.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventsManagerDates
{
    /**
     * A zone PHP can read, from Events Manager's event_timezone; null when blank or unreadable.
     */
    public static function zone(?string $stored): ?string
    {
        return SourceZone::normalize($stored);
    }


    /**
     * The start (or end) of an em_events row as a source value.
     *
     * @param array  $row  An em_events row.
     * @param string $edge Which edge: start or end.
     */
    public static function edge(array $row, string $edge): SourceDateTime
    {
        $zone    = self::zone($row['event_timezone'] ?? null);
        $all_day = (int) ($row['event_all_day'] ?? 0) === 1;
        $date    = (string) ($row[ 'event_' . $edge . '_date' ] ?? '');
        if ($date === '') {
            $date = (string) ($row['event_start_date'] ?? '');
        }
        $time = (string) ($row[ 'event_' . $edge . '_time' ] ?? '');
        if ($zone !== null && $date !== '') {
            return new SourceDateTime(trim($date . ' ' . ($time !== '' ? $time : '00:00:00')), TimezoneModel::PER_EVENT, $zone, $all_day);
        }
        return new SourceDateTime((string) ($row[ 'event_' . $edge ] ?? ''), TimezoneModel::UTC, null, $all_day);
    }


    /**
     * A ticket sale boundary (stored in the event's local time), or null when unset.
     */
    public static function local(?string $value, ?string $zone): ?SourceDateTime
    {
        $value = trim((string) $value);
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return null;
        }
        return $zone !== null
            ? new SourceDateTime($value, TimezoneModel::PER_EVENT, $zone)
            : new SourceDateTime($value, TimezoneModel::SITE_LOCAL);
    }
}
// End of file EventsManagerDates.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_manager/EventsManagerDates.php
