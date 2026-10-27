<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Template: the list of previous migration runs.
 *
 * @var array[] $rows       {run_id, source, mode, status, started, finished, actions, urls}
 * @var string  $wizard_url The wizard's first step.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
?>
<div class="ee-event-migrator-runs">
    <h2><?php esc_html_e('Previous runs', 'migrate-events-to-event-espresso'); ?></h2>
    <?php if ($rows === []) : ?>
        <p><?php esc_html_e('No migrations or dry runs have been run on this site.', 'migrate-events-to-event-espresso'); ?></p>
    <?php else : ?>
        <table class="widefat striped ee-event-migrator-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Started', 'migrate-events-to-event-espresso'); ?></th>
                    <th><?php esc_html_e('Source', 'migrate-events-to-event-espresso'); ?></th>
                    <th><?php esc_html_e('Type', 'migrate-events-to-event-espresso'); ?></th>
                    <th><?php esc_html_e('Status', 'migrate-events-to-event-espresso'); ?></th>
                    <th><?php esc_html_e('Actions', 'migrate-events-to-event-espresso'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row['started']); ?></td>
                        <td><?php echo esc_html($row['source']); ?></td>
                        <td><?php echo esc_html($row['mode']); ?></td>
                        <td><?php echo esc_html($row['status']); ?></td>
                        <td>
                            <a href="<?php echo esc_url($row['urls']['report']); ?>"><?php esc_html_e('Report', 'migrate-events-to-event-espresso'); ?></a>
                            <?php if ($row['actions']['crosswalk']) : ?>
                                | <a href="<?php echo esc_url($row['urls']['crosswalk']); ?>"><?php esc_html_e('Crosswalk', 'migrate-events-to-event-espresso'); ?></a>
                            <?php endif; ?>
                            <?php if ($row['actions']['log']) : ?>
                                | <a href="<?php echo esc_url($row['urls']['log']); ?>"><?php esc_html_e('Log', 'migrate-events-to-event-espresso'); ?></a>
                            <?php endif; ?>
                            <?php if ($row['actions']['rollback']) : ?>
                                | <a href="<?php echo esc_url($row['urls']['rollback']); ?>"><?php esc_html_e('Roll back', 'migrate-events-to-event-espresso'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <p>
        <a class="button button-primary" href="<?php echo esc_url($wizard_url); ?>">
            <?php esc_html_e('Start a new migration', 'migrate-events-to-event-espresso'); ?>
        </a>
    </p>
</div>
