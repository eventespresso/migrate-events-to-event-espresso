<?php

namespace EventEspresso\EventMigrator\domain\services\migration\mapping;

use DateTimeImmutable;

/**
 * Class MappedCheckin
 *
 * One check-in (or check-out) on a migrated datetime, found through
 * PersistedEvent::datetimeIdFor().
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class MappedCheckin
{
    private string $datetime_source_id;

    private DateTimeImmutable $timestamp;

    private bool $checked_in;


    /**
     * @param string            $datetime_source_id The source occurrence checked in to.
     * @param DateTimeImmutable $timestamp          CHK_timestamp in UTC.
     * @param bool              $checked_in         CHK_in; false for a recorded check-out.
     */
    public function __construct(string $datetime_source_id, DateTimeImmutable $timestamp, bool $checked_in)
    {
        $this->datetime_source_id = $datetime_source_id;
        $this->timestamp          = $timestamp;
        $this->checked_in         = $checked_in;
    }


    public function datetimeSourceId(): string
    {
        return $this->datetime_source_id;
    }


    public function timestamp(): DateTimeImmutable
    {
        return $this->timestamp;
    }


    public function isCheckedIn(): bool
    {
        return $this->checked_in;
    }
}
// End of file MappedCheckin.php
// Location: EventEspresso\EventMigrator\domain\services\migration\mapping/MappedCheckin.php
