<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Template: confirm rolling back a migration.
 *
 * @var string $source      The run's source.
 * @var string $started     When the run started.
 * @var string $status      The run's status label.
 * @var string $action_url  The rollback route URL, carrying the route nonce.
 * @var string $report_url  Back to the run's report.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
?>
<div class="ee-event-migrator-rollback">
    <h2><?php esc_html_e('Roll back this migration', 'migrate-events-to-event-espresso'); ?></h2>
    <p>
        <?php
        printf(
            /* translators: 1: source plugin slug, 2: start date and time, 3: run status */
            esc_html__('Migration from %1$s, started %2$s (%3$s).', 'migrate-events-to-event-espresso'),
            esc_html($source),
            esc_html($started),
            esc_html($status)
        );
        ?>
    </p>
    <div class="ee-attention ee-event-migrator-notice">
        <?php esc_html_e(
            'Rolling back permanently deletes every record this migration created and restores the ticket sold counts. Records that were changed after the migration, or that other records now depend on, stop the rollback before anything is deleted. Records that existed before the migration are never touched.',
            'migrate-events-to-event-espresso'
        ); ?>
    </div>
    <form method="post" action="<?php echo esc_url($action_url); ?>">
        <p>
            <label>
                <input type="checkbox" name="ee-migration-rollback-backup" value="yes" required>
                <?php esc_html_e('I have a database backup.', 'migrate-events-to-event-espresso'); ?>
            </label>
        </p>
        <p>
            <button type="submit" class="button button-primary">
                <?php esc_html_e('Roll back', 'migrate-events-to-event-espresso'); ?>
            </button>
            <a class="button" href="<?php echo esc_url($report_url); ?>"><?php esc_html_e('Cancel', 'migrate-events-to-event-espresso'); ?></a>
        </p>
    </form>
</div>
