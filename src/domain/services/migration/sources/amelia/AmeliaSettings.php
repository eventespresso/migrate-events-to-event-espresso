<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\amelia;

use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class AmeliaSettings
 *
 * Reads Amelia's `amelia_settings` option (a JSON object) once: the currency
 * its prices are in and the version it last ran as. The database is read
 * directly so the data is available when the plugin is deactivated.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class AmeliaSettings
{
    private SourceDatabase $db;

    private ?array $settings = null;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * The ISO currency code Amelia charges in, upper-cased; '' when the option is missing.
     *
     * @throws MigrationRecordException When the read fails.
     */
    public function currency(): string
    {
        return strtoupper(trim((string) ($this->settings()['payments']['currency'] ?? '')));
    }


    /**
     * The version Amelia last recorded as running; '' when unknown.
     *
     * @throws MigrationRecordException When the read fails.
     */
    public function version(): string
    {
        return trim((string) ($this->settings()['activation']['version'] ?? ''));
    }


    /**
     * @throws MigrationRecordException
     */
    private function settings(): array
    {
        if ($this->settings === null) {
            $decoded        = json_decode((string) $this->db->option(AmeliaSchema::SETTINGS_OPTION), true);
            $this->settings = is_array($decoded) ? $decoded : [];
        }
        return $this->settings;
    }
}
// End of file AmeliaSettings.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\amelia/AmeliaSettings.php
