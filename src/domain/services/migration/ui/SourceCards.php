<?php

namespace EventEspresso\EventMigrator\domain\services\migration\ui;

use EventEspresso\EventMigrator\application\services\migration\SourceAdapter;
use EventEspresso\EventMigrator\application\services\migration\SourceCapabilities;
use EventEspresso\EventMigrator\application\services\migration\SourceDetection;

/**
 * Class SourceCards
 *
 * The data behind the source cards on the wizard's first step (REQ-001): one
 * card per installed source, selectable when the plugin is active and shown
 * disabled with the reason when it is only installed. Sources that are not
 * installed are not listed. Values are plain text; the template escapes them.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceCards
{
    /**
     * @param array<string, array{adapter: SourceAdapter, detection: SourceDetection}> $detected From SourceAdapterRegistry::detected().
     * @return array<int, array{slug: string, name: string, version: string, selectable: bool, status: string, reason: string, scope: string, unverified: bool}>
     */
    public static function build(array $detected): array
    {
        $cards = [];
        foreach ($detected as $slug => $entry) {
            $detection = $entry['detection'];
            if (! $detection->isInstalled()) {
                continue;
            }
            $selectable = $detection->isSelectable();
            $cards[]    = [
                'slug'       => (string) $slug,
                'name'       => $entry['adapter']->name(),
                'version'    => $detection->version(),
                'selectable' => $selectable,
                'status'     => $selectable
                    ? __('Active', 'migrate-events-to-event-espresso')
                    : __('Installed but not active', 'migrate-events-to-event-espresso'),
                'reason'     => $selectable ? '' : self::reason($detection),
                'scope'      => self::scope($entry['adapter']->capabilities()),
                'unverified' => $selectable && ! $detection->isSchemaVerified(),
            ];
        }
        return $cards;
    }


    /**
     * Whether a slug is one of the cards and can be chosen.
     *
     * @param string                                                                   $slug     The submitted source slug.
     * @param array<string, array{adapter: SourceAdapter, detection: SourceDetection}> $detected From SourceAdapterRegistry::detected().
     */
    public static function isSelectable(string $slug, array $detected): bool
    {
        return isset($detected[ $slug ]) && $detected[ $slug ]['detection']->isSelectable();
    }


    public static function scope(SourceCapabilities $capabilities): string
    {
        return $capabilities->provides(SourceCapabilities::DOMAIN_BOOKINGS)
            ? __('Events and bookings', 'migrate-events-to-event-espresso')
            : __('Events only', 'migrate-events-to-event-espresso');
    }


    private static function reason(SourceDetection $detection): string
    {
        $reason = __('Activate the plugin to migrate from it.', 'migrate-events-to-event-espresso');
        return $detection->reason() !== '' ? $detection->reason() . ' ' . $reason : $reason;
    }
}
// End of file SourceCards.php
// Location: EventEspresso\EventMigrator\domain\services\migration\ui/SourceCards.php
