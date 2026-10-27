<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\amelia;

use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\TimezoneModel;

/**
 * Class AmeliaDates
 *
 * Amelia stores every date and time in UTC (research profile §2, §6): the
 * event periods, the booking window, booking and payment times.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class AmeliaDates
{
    /**
     * A stored UTC "Y-m-d H:i:s" value as a source date, or null when blank or a zero date.
     */
    public static function utc(?string $value): ?SourceDateTime
    {
        $value = trim((string) $value);
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return null;
        }
        return new SourceDateTime($value, TimezoneModel::UTC);
    }
}
// End of file AmeliaDates.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\amelia/AmeliaDates.php
