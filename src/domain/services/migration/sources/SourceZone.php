<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources;

use DateTimeZone;
use Exception;

/**
 * Class SourceZone
 *
 * Reads the timezone strings source plugins store: a PHP zone name, or an
 * offset written "UTC+2", "UTC-5:30" or "UTC+5.5" (decimal hours), as Events
 * Manager and The Events Calendar do. Shared by every adapter whose source
 * keeps a per-event zone.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceZone
{
    /**
     * A zone PHP can read ("+02:00", "America/Denver"), or null when blank or unreadable.
     */
    public static function normalize(?string $stored): ?string
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return null;
        }
        if (preg_match('/^UTC([+-])(\d{1,2})(?:[:.](\d{1,2}))?$/D', $stored, $match)) {
            $minutes = isset($match[3]) ? (int) $match[3] : 0;
            if (isset($match[3]) && strlen($match[3]) === 1) {
                $minutes *= 6;
            }
            return sprintf('%s%02d:%02d', $match[1], (int) $match[2], $minutes);
        }
        try {
            new DateTimeZone($stored);
            return $stored;
        } catch (Exception $exception) {
            return null;
        }
    }
}
// End of file SourceZone.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources/SourceZone.php
