<?php

namespace EventEspresso\EventMigrator\domain\services\migration\ui;

/**
 * Class CsvImporterCard
 *
 * The "CSV file" card on the wizard's first step (REQ-001). It is always shown: a link to the Event Importer
 * add-on when that add-on is active and the user may import events, otherwise disabled with the reason.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class CsvImporterCard
{
    /**
     * The Event Importer add-on's main file, as the WP plugin API names it.
     */
    public const PLUGIN_FILE = 'eea-event-importer/mainfile.php';


    /**
     * @param bool   $installed  True when the Event Importer add-on is installed.
     * @param bool   $active     True when it is loaded.
     * @param bool   $can_import True when the user has the ee_import_events capability.
     * @param string $url        The importer's admin page.
     * @return array{selectable: bool, url: string, reason: string}
     */
    public static function build(bool $installed, bool $active, bool $can_import, string $url): array
    {
        if ($active && $can_import) {
            return ['selectable' => true, 'url' => $url, 'reason' => ''];
        }
        if ($active) {
            $reason = __('You do not have permission to import events.', 'migrate-events-to-event-espresso');
        } elseif ($installed) {
            $reason = __(
                'The Event Importer add-on is installed but not active. Activate it to import events from a CSV file.',
                'migrate-events-to-event-espresso'
            );
        } else {
            $reason = __('Importing events from a CSV file needs the Event Importer add-on.', 'migrate-events-to-event-espresso');
        }
        return ['selectable' => false, 'url' => '', 'reason' => $reason];
    }
}
// End of file CsvImporterCard.php
// Location: EventEspresso\EventMigrator\domain\services\migration\ui/CsvImporterCard.php
