<?php

if (! defined('ABSPATH')) {
    exit;
}
?>
<p>
    <strong><?php esc_html_e('Choose a source', 'migrate-events-to-event-espresso'); ?></strong>
</p>
<p>
    <?php esc_html_e(
        'Each card is an event plugin found on this site. A plugin that is installed but not active is shown greyed out: activate it to migrate from it. Plugins that are not installed are not listed.',
        'migrate-events-to-event-espresso'
    ); ?>
</p>
<p>
    <?php esc_html_e(
        '"Events and bookings" means the migrator can also bring over contacts, registrations and payments. "Events only" sources have no bookings to migrate.',
        'migrate-events-to-event-espresso'
    ); ?>
</p>
<p>
    <?php esc_html_e('To import events from a spreadsheet, use the Event Importer add-on (CSV file).', 'migrate-events-to-event-espresso'); ?>
</p>
<p>
    <?php esc_html_e('Only one migration or import can run at a time. If one is in progress, its owner is named here instead of the cards.', 'migrate-events-to-event-espresso'); ?>
</p>
