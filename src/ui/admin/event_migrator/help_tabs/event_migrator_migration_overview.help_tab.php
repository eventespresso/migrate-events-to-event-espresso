<?php

if (! defined('ABSPATH')) {
    exit;
}

use EventEspresso\core\domain\Domain;

?>
<p>
    <strong><?php printf(
        /* translators: %s: Event Espresso brand name */
        esc_html__('Migrating to %s from another event plugin', 'migrate-events-to-event-espresso'),
        esc_html(Domain::brandName())
    ); ?></strong>
</p>
<p>
    <?php esc_html_e(
        'The Event Migrator reads events, venues, tickets and (optionally) bookings straight from another event plugin that is installed on this site and creates the matching Event Espresso records. Nothing is exported or uploaded.',
        'migrate-events-to-event-espresso'
    ); ?>
</p>
<p>
    <strong><?php esc_html_e('Backup', 'migrate-events-to-event-espresso'); ?></strong>
    <?php esc_html_e('Make a complete database backup before you begin a migration. A dry run writes nothing and needs no backup.', 'migrate-events-to-event-espresso'); ?>
</p>
<p>
    <strong><?php esc_html_e('Dry run', 'migrate-events-to-event-espresso'); ?></strong>
    <?php esc_html_e('A dry run goes through the whole migration without writing anything and reports what would be created and which warnings would appear. Run one first.', 'migrate-events-to-event-espresso'); ?>
</p>
<p>
    <strong><?php esc_html_e('Draft events', 'migrate-events-to-event-espresso'); ?></strong>
    <?php esc_html_e('Migrated events are created as drafts so you can review them. Only a user who may publish events can keep the source publish state.', 'migrate-events-to-event-espresso'); ?>
</p>
<p>
    <strong><?php esc_html_e('Rollback', 'migrate-events-to-event-espresso'); ?></strong>
    <?php esc_html_e('A finished migration can be rolled back from the Previous runs screen. The rollback is refused, and nothing is deleted, if a migrated record was changed afterwards or other records now depend on it.', 'migrate-events-to-event-espresso'); ?>
</p>
<p>
    <strong><?php esc_html_e('Running again', 'migrate-events-to-event-espresso'); ?></strong>
    <?php esc_html_e('A record that an earlier migration already created is recognised and skipped, so a migration can be run again after more records are added to the source.', 'migrate-events-to-event-espresso'); ?>
</p>
