<?php

namespace EventEspresso\EventMigrator\application\services\migration\records;

/**
 * Class SourceCheckinRecord
 *
 * A check-in the source recorded for a booking on one of its dates.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceCheckinRecord
{
    private string $datetime_source_id;

    private SourceDateTime $checked_in_at;

    private bool $checked_in;


    /**
     * @param string         $datetime_source_id Source identifier of the occurrence checked in to.
     * @param SourceDateTime $checked_in_at      When the check-in happened.
     * @param bool           $checked_in         False when the source recorded a check-out.
     */
    public function __construct(string $datetime_source_id, SourceDateTime $checked_in_at, bool $checked_in = true)
    {
        $this->datetime_source_id = $datetime_source_id;
        $this->checked_in_at      = $checked_in_at;
        $this->checked_in         = $checked_in;
    }


    public function datetimeSourceId(): string
    {
        return $this->datetime_source_id;
    }


    public function checkedInAt(): SourceDateTime
    {
        return $this->checked_in_at;
    }


    public function isCheckedIn(): bool
    {
        return $this->checked_in;
    }
}
// End of file SourceCheckinRecord.php
// Location: EventEspresso\EventMigrator\application\services\migration\records/SourceCheckinRecord.php
