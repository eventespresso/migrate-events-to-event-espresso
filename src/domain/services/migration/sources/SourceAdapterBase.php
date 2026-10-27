<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources;

use DateTimeImmutable;
use DateTimeZone;
use EventEspresso\core\services\database\TableAnalysis;
use EventEspresso\EventMigrator\application\services\migration\MigrationOptions;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\SourceAdapter;
use EventEspresso\EventMigrator\application\services\migration\SourceDetection;
use EventEspresso\EventMigrator\domain\exceptions\MigrationRecordException;

/**
 * Class SourceAdapterBase
 *
 * Shared read helpers for concrete adapters: table guards, prepared SELECTs,
 * post meta, horizon fragments and plugin detection, all through one
 * SourceDatabase that the adapter's reader classes share. Table names are
 * always built from an adapter's own constants plus the table prefix, never
 * from request data, and every read is a SELECT (REQ-109). Named "...Base" so
 * the registry's "*SourceAdapter.php" file mask matches only concrete
 * adapters.
 *
 * Two protected seams let tests read a fixture without the plugin installed:
 * database() (a SourceDatabase under another table prefix) and pluginState().
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
abstract class SourceAdapterBase implements SourceAdapter
{
    protected TableAnalysis $table_analysis;

    private ?SourceDatabase $database = null;

    private ?SourceDetection $detection = null;


    public function __construct(TableAnalysis $table_analysis)
    {
        $this->table_analysis = $table_analysis;
    }


    /**
     * What the adapter finds of its plugin on this site.
     *
     * @throws MigrationRecordException When a read fails.
     */
    abstract protected function detectSource(): SourceDetection;


    /**
     * {@inheritDoc}
     *
     * Computed once per adapter instance (one request): the options step and inventory() both need it.
     *
     * @throws MigrationRecordException When a read fails.
     */
    public function detect(): SourceDetection
    {
        if (! $this->detection instanceof SourceDetection) {
            $this->detection = $this->detectSource();
        }
        return $this->detection;
    }


    /**
     * The database access shared with the adapter's readers, for the site's own table prefix.
     */
    protected function database(): SourceDatabase
    {
        if (! $this->database instanceof SourceDatabase) {
            $this->database = new SourceDatabase($this->table_analysis, $GLOBALS['wpdb']);
        }
        return $this->database;
    }


    /**
     * The site-prefixed name of one of the adapter's tables.
     *
     * @param string $unprefixed A table-name class constant of the adapter, e.g. "em_events".
     */
    protected function table(string $unprefixed): string
    {
        return $this->database()->table($unprefixed);
    }


    /**
     * Whether one of the adapter's tables exists, by exact prefixed name (REQ-103).
     *
     * @param string $unprefixed A table-name class constant of the adapter.
     */
    protected function tableExists(string $unprefixed): bool
    {
        return $this->database()->tableExists($unprefixed);
    }


    /**
     * Runs a prepared SELECT and returns associative rows.
     *
     * @param string $sql  SQL with %s/%d placeholders; table names come from table().
     * @param array  $args Values bound to the placeholders, in order.
     * @throws MigrationRecordException When the database reports an error.
     */
    protected function rows(string $sql, array $args = []): array
    {
        return $this->database()->rows($sql, $args);
    }


    /**
     * Runs a prepared SELECT and returns the first column of the first row, or null.
     *
     * @throws MigrationRecordException When the database reports an error.
     */
    protected function scalar(string $sql, array $args = []): ?string
    {
        return $this->database()->scalar($sql, $args);
    }


    /**
     * Runs a prepared SELECT COUNT(...) and returns the count.
     *
     * @throws MigrationRecordException When the database reports an error.
     */
    protected function countRows(string $sql, array $args = []): int
    {
        return $this->database()->countRows($sql, $args);
    }


    /**
     * All post meta of a post, unserialised, as meta key => first value.
     *
     * @throws MigrationRecordException When the database reports an error.
     */
    protected function postMeta(int $post_id): array
    {
        return $this->database()->postMeta([$post_id])[ $post_id ] ?? [];
    }


    /**
     * A prepared "AND <expr> >= %s" fragment for the options' horizon, or ''
     * for "all events". The caller appends horizonBoundary() to its args.
     *
     * @param MigrationOptions $options         The run options carrying the horizon.
     * @param string           $end_column_expr A column or expression from the adapter's constants, never request data.
     */
    public static function horizonSql(MigrationOptions $options, string $end_column_expr): string
    {
        return $options->isAllEvents() ? '' : ' AND ' . $end_column_expr . ' >= %s';
    }


    /**
     * The "Y-m-d H:i:s" boundary bound to horizonSql(): today 00:00:00 in the
     * site timezone for "upcoming", the chosen date for "after:", '' for "all".
     */
    public static function horizonBoundary(MigrationOptions $options): string
    {
        if ($options->isAllEvents()) {
            return '';
        }
        $date = $options->horizonDate() ?? substr((string) current_time('mysql'), 0, 10);
        return $date . ' 00:00:00';
    }


    /**
     * horizonBoundary() as a UTC "Y-m-d H:i:s", for sources whose end column is stored in UTC; '' for "all".
     */
    public static function horizonBoundaryUtc(MigrationOptions $options): string
    {
        $boundary = self::horizonBoundary($options);
        if ($boundary === '') {
            return '';
        }
        return (new DateTimeImmutable($boundary, wp_timezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }


    /**
     * Builds a warning for the adapter's caller to collect.
     */
    protected function warn(string $rule, string $type, string $id, array $args = []): MigrationWarning
    {
        return new MigrationWarning($rule, $type, $id, $args);
    }


    /**
     * The detection reason shown on the source card when the plugin is installed but not active.
     */
    protected function notActiveReason(): string
    {
        /* translators: %s: source plugin name, e.g. "Events Manager" */
        return sprintf(__('%s is installed but not active.', 'migrate-events-to-event-espresso'), $this->name());
    }


    /**
     * The detection reason shown on the source card when the plugin is active but tables it needs are missing.
     */
    protected function missingTablesReason(): string
    {
        /* translators: %s: source plugin name, e.g. "Events Manager" */
        return sprintf(__('%s is active but some of its tables are missing.', 'migrate-events-to-event-espresso'), $this->name());
    }


    /**
     * The detection reason shown on the source card when the plugin's data is present without the plugin.
     */
    protected function notInstalledReason(): string
    {
        /* translators: %s: source plugin name, e.g. "Events Manager" */
        return sprintf(__('%s data was found, but the plugin is not installed.', 'migrate-events-to-event-espresso'), $this->name());
    }


    /**
     * Installed/active state and version of the source plugin. A seam so tests can describe a plugin that is
     * not installed on the test site.
     *
     * @param string $plugin_file The plugin's main file, e.g. "events-manager/events-manager.php".
     * @return array{installed: bool, active: bool, version: string}
     */
    protected function pluginState(string $plugin_file): array
    {
        return $this->detectPlugin($plugin_file);
    }


    /**
     * Installed/active state and version of a plugin by its main file
     * ("events-manager/events-manager.php"), using the WP plugin API.
     *
     * @return array{installed: bool, active: bool, version: string}
     */
    protected function detectPlugin(string $plugin_file): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins   = get_plugins();
        $installed = isset($plugins[ $plugin_file ]);
        return [
            'installed' => $installed,
            'active'    => $installed && is_plugin_active($plugin_file),
            'version'   => $installed ? (string) ($plugins[ $plugin_file ]['Version'] ?? '') : '',
        ];
    }
}
// End of file SourceAdapterBase.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources/SourceAdapterBase.php
