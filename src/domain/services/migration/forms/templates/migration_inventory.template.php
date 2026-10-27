<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Template: the pre-flight inventory on the migration options step.
 *
 * @var string   $source_name  The source's display name.
 * @var string   $version      The detected source version.
 * @var array[]  $rows         {key, label, count|null}
 * @var bool     $is_empty     True when the source holds no records under the chosen options.
 * @var string[] $not_migrated Source concepts with no Event Espresso counterpart.
 * @var string   $timezone     How the source stores times.
 * @var bool     $unverified   True when the installed version has not been tested with the migrator.
 * @var int      $excluded     Events left out by the chosen time horizon.
 * @var bool     $events_only  True when the source has bookings but only events were chosen.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
?>
<div class="ee-event-migrator-inventory">
    <h3>
        <?php
        printf(
            /* translators: 1: source plugin name, 2: its version */
            esc_html__('%1$s %2$s holds', 'migrate-events-to-event-espresso'),
            esc_html($source_name),
            esc_html($version)
        );
        ?>
    </h3>
    <?php if ($unverified) : ?>
        <div class="ee-attention ee-event-migrator-notice">
            <?php esc_html_e(
                'This version of the plugin has not been tested with the migrator. Run a dry run first and read its report before migrating.',
                'migrate-events-to-event-espresso'
            ); ?>
        </div>
    <?php endif; ?>
    <table class="widefat striped ee-event-migrator-table">
        <tbody>
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <th scope="row"><?php echo esc_html($row['label']); ?></th>
                    <td>
                        <?php
                        if ($row['count'] === null) {
                            esc_html_e('Not available in this source', 'migrate-events-to-event-espresso');
                        } else {
                            echo esc_html(number_format_i18n($row['count']));
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (! empty($excluded)) : ?>
        <p class="ee-event-migrator-excluded">
            <?php
            printf(
                /* translators: %s: number of source events */
                esc_html(_n('%s event is excluded by the time horizon.', '%s events are excluded by the time horizon.', (int) $excluded, 'migrate-events-to-event-espresso')),
                esc_html(number_format_i18n((int) $excluded))
            );
            ?>
        </p>
    <?php endif; ?>
    <?php if (! empty($events_only)) : ?>
        <p class="ee-event-migrator-events-only">
            <?php esc_html_e('Bookings, attendees and payments will not be migrated (events only).', 'migrate-events-to-event-espresso'); ?>
        </p>
    <?php endif; ?>
    <?php if ($is_empty) : ?>
        <p class="ee-event-migrator-notice ee-attention">
            <?php esc_html_e(
                'The source has no records under these options. Change the options below, or choose another source. You can still run a dry run.',
                'migrate-events-to-event-espresso'
            ); ?>
        </p>
    <?php endif; ?>
    <?php if ($timezone !== '') : ?>
        <p><?php echo esc_html($timezone); ?></p>
    <?php endif; ?>
    <?php if ($not_migrated !== []) : ?>
        <h4><?php esc_html_e('Not migrated', 'migrate-events-to-event-espresso'); ?></h4>
        <p><?php esc_html_e('Event Espresso has no counterpart for these. They are kept as text where possible and listed in the report.', 'migrate-events-to-event-espresso'); ?></p>
        <ul class="ee-event-migrator-list">
            <?php foreach ($not_migrated as $concept) : ?>
                <li><?php echo esc_html($concept); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
