<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy;

use DateInterval;
use DateTimeImmutable;
use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventsMadeEasyProps
 *
 * EME's text-encoded values (research profile §2): the JSON `event_properties`
 * (older rows are PHP-serialized), the `||` separated prices and seat counts,
 * and the registration window written as days and hours before the event's
 * start or end.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventsMadeEasyProps
{
    /**
     * An event's `event_properties` as an array; empty when blank or unreadable.
     */
    public static function decode(?string $stored): array
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return [];
        }
        $decoded = is_serialized($stored) ? SourceDatabase::safeUnserialize($stored) : json_decode($stored, true);
        return is_array($decoded) ? $decoded : [];
    }


    /**
     * The values of a `||` separated list, trimmed; an empty string has no values.
     *
     * @return string[]
     */
    public static function slots(?string $list): array
    {
        $list = trim((string) $list);
        return $list === '' ? [] : array_map('trim', explode(EventsMadeEasySchema::MULTI, $list));
    }


    /**
     * When registration opens or closes: the event's start or end less the days and hours the properties give,
     * or null when no offset is set. $which is "start" or "end" (the property prefix `rsvp_<which>_`).
     *
     * @param array  $props The decoded event_properties.
     * @param array  $event The eme_events row.
     * @param string $which Either "start" or "end".
     */
    public static function window(array $props, array $event, string $which): ?SourceDateTime
    {
        $days  = (int) ($props[ 'rsvp_' . $which . '_number_days' ] ?? 0);
        $hours = (int) ($props[ 'rsvp_' . $which . '_number_hours' ] ?? 0);
        if ($days + $hours <= 0) {
            return null;
        }
        $target = ($props[ 'rsvp_' . $which . '_target' ] ?? 'start') === 'end' ? 'event_end' : 'event_start';
        $base   = trim((string) ($event[ $target ] ?? '')) ?: (string) ($event['event_start'] ?? '');
        if ($base === '') {
            return null;
        }
        $moment = (new DateTimeImmutable($base))->sub(new DateInterval(sprintf('P%dDT%dH', $days, $hours)));
        return new SourceDateTime($moment->format('Y-m-d H:i:s'), TimezoneModel::SITE_LOCAL);
    }
}
// End of file EventsMadeEasyProps.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\events_made_easy/EventsMadeEasyProps.php
