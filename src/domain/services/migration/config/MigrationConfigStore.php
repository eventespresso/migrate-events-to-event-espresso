<?php

namespace EventEspresso\EventMigrator\domain\services\migration\config;

use stdClass;

/**
 * Class MigrationConfigStore
 *
 * Compare-and-set persistence for the wizard's MigrationConfig. Every wizard
 * step reads the stored option once (load()), checks its staging revision
 * against that snapshot, changes the config and saves with
 * saveIfUnchanged(), which succeeds only if the stored value still equals the
 * snapshot. Two tabs, or a tab and a starting run, can therefore never
 * overwrite each other's state without one of them being told.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class MigrationConfigStore
{
    private MigrationConfig $config;


    public function __construct(MigrationConfig $config)
    {
        $this->config = $config;
    }


    public function config(): MigrationConfig
    {
        return $this->config;
    }


    /**
     * Reads the stored option straight from the database (bypassing the option caches), populates the config
     * from it and returns the raw snapshot to hand to saveIfUnchanged(); null when nothing is stored yet.
     */
    public function load(): ?string
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap on the options row: the option caches are bypassed on purpose and cleared after the write.
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM %i WHERE option_name = %s",
                $wpdb->options,
                $this->config->getWpOptionName()
            )
        );
        $raw = $raw === null ? null : (string) $raw;
        if ($raw !== null && $raw !== '') {
            $json = json_decode($raw, false);
            if ($json instanceof stdClass) {
                $this->config->fromJsonSerializedData($json);
            }
        }
        return $raw;
    }


    /**
     * Saves the config only if the stored value still equals the snapshot. The caller must have changed at
     * least one field, so a successful write always changes the value and rows-affected is a reliable signal.
     *
     * @param string|null $expected_raw The snapshot returned by load().
     * @return bool true when this call's write is the one that landed
     */
    public function saveIfUnchanged(?string $expected_raw): bool
    {
        global $wpdb;
        $option_name = $this->config->getWpOptionName();
        $new_value   = (string) wp_json_encode($this->config->toJsonSerializableData());
        if ($expected_raw === null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- see load().
            $rows_affected = $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
                    $wpdb->options,
                    $option_name,
                    $new_value
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- see load().
            $rows_affected = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s",
                    $wpdb->options,
                    $new_value,
                    $option_name,
                    $expected_raw
                )
            );
        }
        // The raw queries bypass the option API, so its caches must be cleared.
        wp_cache_delete($option_name, 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
        return $rows_affected === 1;
    }
}
// End of file MigrationConfigStore.php
// Location: EventEspresso\EventMigrator\domain\services\migration\config/MigrationConfigStore.php
