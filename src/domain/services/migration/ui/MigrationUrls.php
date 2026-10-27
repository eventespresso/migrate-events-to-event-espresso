<?php

namespace EventEspresso\EventMigrator\domain\services\migration\ui;

use EEH_URL;

/**
 * Class MigrationUrls
 *
 * The admin URLs the report, the run list and the rollback screens link to.
 * Action routes carry the route nonce; ids in them are 16-hex run ids only,
 * never source data.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class MigrationUrls
{
    public static function wizard(string $admin_url): string
    {
        return add_query_arg(['action' => 'show_migration_step'], $admin_url);
    }


    public static function runs(string $admin_url): string
    {
        return add_query_arg(['action' => 'migration_runs'], $admin_url);
    }


    public static function report(string $admin_url, string $run_id): string
    {
        return MigrationLauncher::reportUrl($admin_url, $run_id);
    }


    public static function crosswalk(string $admin_url, string $run_id): string
    {
        return self::action('download_crosswalk', $admin_url, $run_id);
    }


    public static function log(string $admin_url, string $run_id): string
    {
        return self::action('download_migration_log', $admin_url, $run_id);
    }


    public static function rollbackConfirm(string $admin_url, string $run_id): string
    {
        return self::action('rollback_confirm', $admin_url, $run_id);
    }


    /**
     * The target of the rollback confirmation form (a POST).
     */
    public static function rollbackRun(string $admin_url, string $run_id): string
    {
        return self::action('rollback_run', $admin_url, $run_id);
    }


    private static function action(string $action, string $admin_url, string $run_id): string
    {
        return EEH_URL::add_query_args_and_nonce(['action' => $action, 'run_id' => $run_id], $admin_url);
    }
}
// End of file MigrationUrls.php
// Location: EventEspresso\EventMigrator\domain\services\migration\ui/MigrationUrls.php
