<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventon_lite;

use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;
use EventEspresso\EventMigrator\domain\services\migration\sources\SourceDatabase;

/**
 * Class EventonSettings
 *
 * Reads EventON's options once: the general settings array (global timezone,
 * custom data field labels), the `evo_tax_meta` array that holds the location
 * and organizer details of the terms, and the version marker. The database is
 * read directly so the data is available when the plugin is deactivated.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventonSettings
{
    private SourceDatabase $db;

    /**
     * @var array<string, array>
     */
    private array $options = [];


    public function __construct(SourceDatabase $db)
    {
        $this->db = $db;
    }


    /**
     * The zone events without a `_evo_tz` are written in: the global zone when "use it for all events" is on,
     * otherwise null (the site's zone).
     *
     * @throws MigrationRecordException When the read fails.
     */
    public function defaultZone(): ?string
    {
        $settings = $this->option(EventonSchema::SETTINGS_OPTION);
        $zone     = trim((string) ($settings['evo_global_tzo'] ?? ''));
        return ($settings['evo_tzo_all'] ?? '') === 'yes' && $zone !== '' ? $zone : null;
    }


    /**
     * The label of a custom data field (1 to 10) when it is switched on, otherwise ''.
     *
     * @throws MigrationRecordException When the read fails.
     */
    public function customFieldLabel(int $index): string
    {
        $settings = $this->option(EventonSchema::SETTINGS_OPTION);
        return ($settings[ 'evcal_af_' . $index ] ?? '') === 'yes' ? trim((string) ($settings[ 'evcal_ec_f' . $index . 'a1' ] ?? '')) : '';
    }


    /**
     * The details of a location or organizer term, from `evo_tax_meta[<taxonomy>][<term id>]`.
     *
     * @return array<string, string>
     * @throws MigrationRecordException When the read fails.
     */
    public function termMeta(string $taxonomy, int $term_id): array
    {
        $meta = $this->option(EventonSchema::TAX_META_OPTION)[ $taxonomy ][ $term_id ] ?? [];
        return is_array($meta) ? array_map('strval', array_filter($meta, 'is_scalar')) : [];
    }


    /**
     * The version EventON Lite last recorded; '' when unknown.
     *
     * @throws MigrationRecordException When the read fails.
     */
    public function version(): string
    {
        return trim((string) $this->db->option(EventonSchema::VERSION_OPTION));
    }


    /**
     * @return array<mixed>
     * @throws MigrationRecordException
     */
    private function option(string $name): array
    {
        if (! isset($this->options[ $name ])) {
            $stored                = SourceDatabase::safeUnserialize((string) $this->db->option($name));
            $this->options[ $name ] = is_array($stored) ? $stored : [];
        }
        return $this->options[ $name ];
    }
}
// End of file EventonSettings.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventon_lite/EventonSettings.php
