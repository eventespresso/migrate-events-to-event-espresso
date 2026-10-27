<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Template: the summary on the migration confirm step.
 *
 * @var array[] $lines {label, value}
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
?>
<div class="ee-event-migrator-confirm">
    <table class="widefat striped ee-event-migrator-table">
        <tbody>
            <?php foreach ($lines as $line) : ?>
                <tr>
                    <th scope="row"><?php echo esc_html($line['label']); ?></th>
                    <td><?php echo esc_html($line['value']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="ee-attention ee-event-migrator-notice">
        <strong><?php esc_html_e('Important:', 'migrate-events-to-event-espresso'); ?></strong>
        <?php esc_html_e(
            'A dry run reads the source and shows what would be created, and writes nothing. A migration writes to your Event Espresso data, so make a database backup first. You can roll a finished migration back from the Previous runs screen as long as the migrated records have not been changed.',
            'migrate-events-to-event-espresso'
        ); ?>
    </div>
</div>
