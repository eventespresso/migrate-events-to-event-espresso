<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventin;

use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventinSettings
 *
 * Reads Eventin's `etn_event_options` settings array once (currency and the
 * global attendee-question schema) and its version marker. The database is
 * read directly so the data is available when the plugin is deactivated.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventinSettings
{
    private SourceDatabase $db;

    private ?array $settings = null;


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * The ISO currency code of the site's Eventin prices, upper-cased; '' when unset.
     *
     * @throws MigrationRecordException When the read fails.
     */
    public function currency(): string
    {
        return strtoupper(trim((string) ($this->settings()['etn_settings_country_currency'] ?? '')));
    }


    /**
     * The global attendee-question schema rows (used by events without a schema of their own).
     *
     * @return array[]
     * @throws MigrationRecordException When the read fails.
     */
    public function extraFields(): array
    {
        $rows = $this->settings()['attendee_extra_fields'] ?? $this->settings()['extra_fields'] ?? [];
        return array_values(array_filter((array) $rows, 'is_array'));
    }


    /**
     * The version Eventin last migrated its data to; '' when unknown.
     *
     * @throws MigrationRecordException When the read fails.
     */
    public function version(): string
    {
        return trim((string) $this->db->option(EventinSchema::VERSION_OPTION));
    }


    /**
     * @throws MigrationRecordException
     */
    private function settings(): array
    {
        if ($this->settings === null) {
            $stored         = SourceDatabase::safeUnserialize((string) $this->db->option(EventinSchema::SETTINGS_OPTION));
            $this->settings = is_array($stored) ? $stored : [];
        }
        return $this->settings;
    }
}
// End of file EventinSettings.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventin/EventinSettings.php
