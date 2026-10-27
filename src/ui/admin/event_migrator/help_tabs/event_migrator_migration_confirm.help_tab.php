<?php

if (! defined('ABSPATH')) {
    exit;
}
?>
<p>
    <strong><?php esc_html_e('Confirm', 'migrate-events-to-event-espresso'); ?></strong>
</p>
<p>
    <?php esc_html_e(
        '"Dry run" reads the source and shows the report without writing anything. "Begin migration" writes to your Event Espresso data and needs the backup box ticked.',
        'migrate-events-to-event-espresso'
    ); ?>
</p>
<p>
    <?php esc_html_e(
        'The migration runs in small batches in your browser. Keep the page open until it finishes. If it stops, the report shows what was done; you can run it again to continue, or roll it back from the Previous runs screen.',
        'migrate-events-to-event-espresso'
    ); ?>
</p>
<p>
    <?php esc_html_e(
        'The report lists what was created, reused and skipped, which warnings came up, and offers a crosswalk (a CSV of source ids and the Event Espresso ids they became) and a log to download.',
        'migrate-events-to-event-espresso'
    ); ?>
</p>
