<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Template: a migration run's report.
 *
 * @var array<string, mixed> $report Built by ReportView::build().
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
?>
<div class="ee-event-migrator-report">
    <h2>
        <?php
        if ($report['is_dry_run']) {
            // The dry-run status label already names the run type.
            echo esc_html($report['status_label']);
        } else {
            printf(
                /* translators: 1: run type (Migration or Dry run), 2: its status */
                esc_html__('%1$s: %2$s', 'migrate-events-to-event-espresso'),
                esc_html($report['mode']),
                esc_html($report['status_label'])
            );
        }
        ?>
    </h2>
    <p>
        <?php
        printf(
            /* translators: 1: source plugin slug, 2: start date and time */
            esc_html__('Source: %1$s. Started %2$s.', 'migrate-events-to-event-espresso'),
            esc_html($report['source']),
            esc_html($report['started'])
        );
        if ($report['finished'] !== '') {
            echo ' ';
            printf(
                /* translators: %s: finish date and time */
                esc_html__('Finished %s.', 'migrate-events-to-event-espresso'),
                esc_html($report['finished'])
            );
        }
        ?>
    </p>
    <?php if ($report['is_dry_run']) : ?>
        <div class="ee-attention ee-event-migrator-notice">
            <?php esc_html_e(
                'This was a dry run. Nothing was written to Event Espresso. The numbers below are what a migration with the same options would create.',
                'migrate-events-to-event-espresso'
            ); ?>
        </div>
    <?php endif; ?>

    <?php if ($report['nothing']) : ?>
        <div class="ee-attention ee-event-migrator-notice">
            <?php esc_html_e('Nothing was migrated: the source has no records under these options.', 'migrate-events-to-event-espresso'); ?>
        </div>
    <?php endif; ?>

    <?php if ($report['refusals'] !== []) : ?>
        <div class="ee-attention ee-event-migrator-notice">
            <strong><?php esc_html_e('The rollback was refused and nothing was deleted.', 'migrate-events-to-event-espresso'); ?></strong>
            <?php esc_html_e('These migrated records were changed or are now used by other records:', 'migrate-events-to-event-espresso'); ?>
            <ul class="ee-event-migrator-list">
                <?php foreach ($report['refusals'] as $refusal) : ?>
                    <li>
                        <?php if ($refusal['url'] !== '') : ?>
                            <a href="<?php echo esc_url($refusal['url']); ?>">
                                <?php echo esc_html($refusal['label'] . ' #' . $refusal['id']); ?>
                            </a>
                        <?php else : ?>
                            <?php echo esc_html($refusal['label'] . ' #' . $refusal['id']); ?>
                        <?php endif; ?>
                        &mdash; <?php echo esc_html($refusal['reason']); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <h3><?php esc_html_e('What happened', 'migrate-events-to-event-espresso'); ?></h3>
    <?php if ($report['entities'] === []) : ?>
        <p><?php esc_html_e('No records were processed.', 'migrate-events-to-event-espresso'); ?></p>
    <?php else : ?>
        <table class="widefat striped ee-event-migrator-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Records', 'migrate-events-to-event-espresso'); ?></th>
                    <th><?php esc_html_e('In the source', 'migrate-events-to-event-espresso'); ?></th>
                    <th><?php esc_html_e('Created', 'migrate-events-to-event-espresso'); ?></th>
                    <th><?php esc_html_e('Reused', 'migrate-events-to-event-espresso'); ?></th>
                    <th><?php esc_html_e('Skipped', 'migrate-events-to-event-espresso'); ?></th>
                    <th><?php esc_html_e('Failed', 'migrate-events-to-event-espresso'); ?></th>
                    <th><?php esc_html_e('Unaccounted for', 'migrate-events-to-event-espresso'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['entities'] as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row['label']); ?></td>
                        <td><?php echo esc_html($row['source']); ?></td>
                        <td><?php echo esc_html(number_format_i18n($row['created'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n($row['reused'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n($row['skipped'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n($row['failed'])); ?></td>
                        <td><?php echo esc_html($row['unaccounted']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($report['per_event']['rows'] !== []) : ?>
        <h3><?php esc_html_e('Registrations per event', 'migrate-events-to-event-espresso'); ?></h3>
        <table class="widefat striped ee-event-migrator-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Source event', 'migrate-events-to-event-espresso'); ?></th>
                    <th scope="col"><?php esc_html_e('Event Espresso event', 'migrate-events-to-event-espresso'); ?></th>
                    <th scope="col"><?php esc_html_e('In the source', 'migrate-events-to-event-espresso'); ?></th>
                    <th scope="col"><?php esc_html_e('In Event Espresso', 'migrate-events-to-event-espresso'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['per_event']['rows'] as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row['source_event_id']); ?></td>
                        <td><?php echo esc_html($row['event_id']); ?></td>
                        <td><?php echo esc_html(number_format_i18n($row['source'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n($row['ee'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($report['per_event']['more'] > 0) : ?>
            <p>
                <?php
                printf(
                    esc_html(
                        /* translators: %s: number of events */
                        _n(
                            '%s more event is not listed.',
                            '%s more events are not listed.',
                            $report['per_event']['more'],
                            'migrate-events-to-event-espresso'
                        )
                    ),
                    esc_html(number_format_i18n($report['per_event']['more']))
                );
                ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($report['payments'] !== []) : ?>
        <h3><?php esc_html_e('Approved payments by currency', 'migrate-events-to-event-espresso'); ?></h3>
        <table class="widefat striped ee-event-migrator-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Currency', 'migrate-events-to-event-espresso'); ?></th>
                    <th scope="col"><?php esc_html_e('In the source', 'migrate-events-to-event-espresso'); ?></th>
                    <th scope="col"><?php esc_html_e('In Event Espresso', 'migrate-events-to-event-espresso'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['payments'] as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row['currency']); ?></td>
                        <td><?php echo esc_html(number_format_i18n($row['source'], 2)); ?></td>
                        <td><?php echo esc_html(number_format_i18n($row['ee'], 2)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><?php esc_html_e('Event Espresso stores every amount in the site currency.', 'migrate-events-to-event-espresso'); ?></p>
    <?php endif; ?>

    <?php if ($report['warnings'] !== []) : ?>
        <h3><?php esc_html_e('Warnings', 'migrate-events-to-event-espresso'); ?></h3>
        <table class="widefat striped ee-event-migrator-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Warning', 'migrate-events-to-event-espresso'); ?></th>
                    <th><?php esc_html_e('Times', 'migrate-events-to-event-espresso'); ?></th>
                    <th><?php esc_html_e('Examples (type:source id)', 'migrate-events-to-event-espresso'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['warnings'] as $warning) : ?>
                    <tr>
                        <td>
                            <code><?php echo esc_html($warning['rule']); ?></code><br>
                            <?php echo wp_kses_post($warning['example_html']); ?>
                        </td>
                        <td><?php echo esc_html(number_format_i18n($warning['count'])); ?></td>
                        <td><?php echo esc_html(implode(', ', $warning['samples'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($report['links']['log'] !== '') : ?>
            <p>
                <?php esc_html_e('The run log lists every warning by rule and record id.', 'migrate-events-to-event-espresso'); ?>
                <a href="<?php echo esc_url($report['links']['log']); ?>"><?php esc_html_e('Download the log', 'migrate-events-to-event-espresso'); ?></a>
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($report['reuse'] !== []) : ?>
        <h3><?php esc_html_e('Existing records that were reused', 'migrate-events-to-event-espresso'); ?></h3>
        <ul class="ee-event-migrator-list">
            <?php foreach ($report['reuse'] as $note) : ?>
                <li>
                    <?php if ($note['url'] !== '') : ?>
                        <a href="<?php echo esc_url($note['url']); ?>"><?php echo esc_html($note['label'] . ' #' . $note['id']); ?></a>
                    <?php else : ?>
                        <?php echo esc_html($note['label'] . ' #' . $note['id']); ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($report['sold'] !== []) : ?>
        <h3>
            <?php $report['is_dry_run']
                ? esc_html_e('Ticket sold counts a migration would change', 'migrate-events-to-event-espresso')
                : esc_html_e('Ticket sold counts changed', 'migrate-events-to-event-espresso'); ?>
        </h3>
        <table class="widefat striped ee-event-migrator-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Ticket', 'migrate-events-to-event-espresso'); ?></th>
                    <th><?php esc_html_e('Change in sold', 'migrate-events-to-event-espresso'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['sold'] as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row['ticket']); ?></td>
                        <td><?php echo esc_html(sprintf('%+d', $row['delta'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p class="ee-event-migrator-actions">
        <?php if ($report['links']['crosswalk'] !== '') : ?>
            <a class="button" href="<?php echo esc_url($report['links']['crosswalk']); ?>">
                <?php esc_html_e('Download the crosswalk (CSV)', 'migrate-events-to-event-espresso'); ?>
            </a>
        <?php endif; ?>
        <?php if ($report['links']['log'] !== '') : ?>
            <a class="button" href="<?php echo esc_url($report['links']['log']); ?>">
                <?php esc_html_e('Download the log', 'migrate-events-to-event-espresso'); ?>
            </a>
        <?php endif; ?>
        <?php if ($report['links']['rollback'] !== '') : ?>
            <a class="button" href="<?php echo esc_url($report['links']['rollback']); ?>">
                <?php esc_html_e('Roll back this migration', 'migrate-events-to-event-espresso'); ?>
            </a>
        <?php endif; ?>
        <a class="button" href="<?php echo esc_url($report['links']['runs']); ?>">
            <?php esc_html_e('Previous runs', 'migrate-events-to-event-espresso'); ?>
        </a>
    </p>
</div>
