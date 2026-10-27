<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Template: the source cards on the migration wizard's first step.
 *
 * @var array[] $cards        {slug, name, version, selectable, status, reason, scope, unverified}
 * @var array   $csv          {selectable, url, reason}: the CSV importer card (CsvImporterCard::build()).
 * @var string  $selected     The slug chosen earlier, if any.
 * @var string  $source_param The radio buttons' name.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
?>
<fieldset class="ee-event-migrator-cards-fieldset">
<legend class="screen-reader-text"><?php esc_html_e('Source plugin', 'migrate-events-to-event-espresso'); ?></legend>
<div class="ee-event-migrator-cards">
    <?php foreach ($cards as $card) : ?>
        <?php
        $card_class  = $card['selectable'] ? 'ee-event-migrator-card' : 'ee-event-migrator-card ee-event-migrator-card-disabled';
        $card_id     = 'ee-migration-source-' . $card['slug'];
        $described   = [$card_id . '-scope', $card_id . '-status'];
        $card['reason'] !== '' && $described[] = $card_id . '-reason';
        $card['unverified'] && $described[]    = $card_id . '-unverified';
        ?>
        <div class="<?php echo esc_attr($card_class); ?>">
            <label>
                <input type="radio"
                       name="<?php echo esc_attr($source_param); ?>"
                       value="<?php echo esc_attr($card['slug']); ?>"
                       aria-describedby="<?php echo esc_attr(implode(' ', $described)); ?>"
                       <?php checked($selected, $card['slug']); ?>
                       <?php disabled(! $card['selectable']); ?>>
                <strong><?php echo esc_html($card['name']); ?></strong>
                <?php if ($card['version'] !== '') : ?>
                    <span class="ee-event-migrator-version"><?php echo esc_html($card['version']); ?></span>
                <?php endif; ?>
            </label>
            <p class="ee-event-migrator-card-scope" id="<?php echo esc_attr($card_id . '-scope'); ?>">
                <?php echo esc_html($card['scope']); ?>
            </p>
            <p class="ee-event-migrator-card-status" id="<?php echo esc_attr($card_id . '-status'); ?>">
                <?php echo esc_html($card['status']); ?>
            </p>
            <?php if ($card['reason'] !== '') : ?>
                <p class="ee-event-migrator-card-reason" id="<?php echo esc_attr($card_id . '-reason'); ?>">
                    <?php echo esc_html($card['reason']); ?>
                </p>
            <?php endif; ?>
            <?php if ($card['unverified']) : ?>
                <p class="ee-event-migrator-card-reason" id="<?php echo esc_attr($card_id . '-unverified'); ?>">
                    <?php esc_html_e(
                        'This version has not been tested with the migrator. Run a dry run first and check the report.',
                        'migrate-events-to-event-espresso'
                    ); ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <div class="<?php echo esc_attr(
        $csv['selectable']
            ? 'ee-event-migrator-card ee-event-migrator-card-csv'
            : 'ee-event-migrator-card ee-event-migrator-card-csv ee-event-migrator-card-disabled'
    ); ?>">
        <strong><?php esc_html_e('CSV file', 'migrate-events-to-event-espresso'); ?></strong>
        <p class="ee-event-migrator-card-scope"><?php esc_html_e('Events only', 'migrate-events-to-event-espresso'); ?></p>
        <?php if ($csv['selectable']) : ?>
            <p>
                <a class="button" href="<?php echo esc_url($csv['url']); ?>">
                    <?php esc_html_e('Open the Event Importer', 'migrate-events-to-event-espresso'); ?>
                </a>
            </p>
        <?php else : ?>
            <p class="ee-event-migrator-card-reason"><?php echo esc_html($csv['reason']); ?></p>
        <?php endif; ?>
    </div>
</div>
</fieldset>
