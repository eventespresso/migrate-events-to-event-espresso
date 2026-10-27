<?php

/**
 * Runs when the plugin is deleted from the Plugins screen (WP_UNINSTALL_PLUGIN).
 *
 * Removes what the add-on itself stored: its options (lock, wizard config, run index, one record per
 * run, the last-run transient), its log folder under the Event Espresso uploads folder, and the
 * ee_migrate_events capability it added to roles. It never touches the events, venues, tickets,
 * contacts, registrations or payments a migration created, nor the crosswalk extra meta on them, nor
 * any source plugin's data. On multisite every site is cleaned.
 *
 * Event Espresso core may already be gone when this runs, so nothing here depends on it.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Removes the add-on's data from the current site.
 */
function migrate_events_to_event_espresso_uninstall_site(): void
{
    global $wpdb;
    foreach (['ee_event_migrator_lock', 'ee_event_migrator_config', 'ee_event_migrator_runs'] as $option) {
        delete_option($option);
    }
    delete_transient('ee_event_migrator_last_run');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- run records are one option each with a known prefix and autoload off; nothing lists them for delete_option() otherwise.
    $run_options = $wpdb->get_col(
        $wpdb->prepare(
            'SELECT option_name FROM %i WHERE option_name LIKE %s',
            $wpdb->options,
            $wpdb->esc_like('ee_event_migrator_run_') . '%'
        )
    );
    foreach ((array) $run_options as $option) {
        delete_option((string) $option);
    }
    foreach (wp_roles()->role_objects as $role) {
        if ($role->has_cap('ee_migrate_events')) {
            $role->remove_cap('ee_migrate_events');
        }
    }
    migrate_events_to_event_espresso_uninstall_remove_logs();
}


/**
 * Removes the log folder (uploads/espresso/event-migrator) through WP_Filesystem; skipped when the
 * filesystem cannot be used without credentials.
 */
function migrate_events_to_event_espresso_uninstall_remove_logs(): void
{
    $uploads = wp_upload_dir();
    if (! empty($uploads['error']) || empty($uploads['basedir'])) {
        return;
    }
    $folder = trailingslashit($uploads['basedir']) . 'espresso/event-migrator';
    if (! is_dir($folder)) {
        return;
    }
    if (! function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    global $wp_filesystem;
    if (! WP_Filesystem() || ! $wp_filesystem instanceof WP_Filesystem_Base) {
        return;
    }
    $wp_filesystem->delete($folder, true);
}


/**
 * Cleans every site of a network, or the single site.
 */
function migrate_events_to_event_espresso_uninstall(): void
{
    if (! is_multisite()) {
        migrate_events_to_event_espresso_uninstall_site();
        return;
    }
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
        switch_to_blog((int) $site_id);
        migrate_events_to_event_espresso_uninstall_site();
        restore_current_blog();
    }
}


migrate_events_to_event_espresso_uninstall();
// End of file uninstall.php
// Location: wp-content/plugins/migrate-events-to-event-espresso/uninstall.php
