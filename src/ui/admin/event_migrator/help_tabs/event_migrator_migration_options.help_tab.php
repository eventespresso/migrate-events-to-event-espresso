<?php

if (! defined('ABSPATH')) {
    exit;
}
?>
<p>
    <strong><?php esc_html_e('Pre-flight table', 'migrate-events-to-event-espresso'); ?></strong>
</p>
<p>
    <?php esc_html_e(
        'The table counts what the source holds for the options you chose. "Not available in this source" means the plugin has no such records. Use "Update the counts" after changing the options to see the new numbers.',
        'migrate-events-to-event-espresso'
    ); ?>
</p>
<p>
    <strong><?php esc_html_e('Which events', 'migrate-events-to-event-espresso'); ?></strong>
    <?php esc_html_e('Migrate everything, only events that have not ended yet, or only events that start after a date.', 'migrate-events-to-event-espresso'); ?>
</p>
<p>
    <strong><?php esc_html_e('What to migrate', 'migrate-events-to-event-espresso'); ?></strong>
    <?php esc_html_e('Choose events only, or events together with their bookings. Bookings bring contacts, registrations, payments, answers to custom fields and check-ins.', 'migrate-events-to-event-espresso'); ?>
</p>
<p>
    <strong><?php esc_html_e('Recurring events', 'migrate-events-to-event-espresso'); ?></strong>
    <?php esc_html_e('A recurring event can become one Event Espresso event with a datetime for every date, or one event for every occurrence. Event Espresso does not manage a series after the migration.', 'migrate-events-to-event-espresso'); ?>
</p>
<p>
    <strong><?php esc_html_e('Keep the source publish state', 'migrate-events-to-event-espresso'); ?></strong>
    <?php esc_html_e('Shown only to users who may publish events. By default every migrated event is a draft.', 'migrate-events-to-event-espresso'); ?>
</p>
