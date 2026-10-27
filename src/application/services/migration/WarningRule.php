<?php

namespace EventEspresso\EventMigrator\application\services\migration;

/**
 * Class WarningRule
 *
 * The catalogue of stable warning rule identifiers a migration run can report
 * (REQ-106). Identifiers are constants so support can document them; labels
 * are translated at render time, never at construction, so no translation
 * call runs before WordPress `init`.
 *
 * The constant values are persisted (run records, the run log) and documented for support: never change one.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class WarningRule
{
    public const SCHEMA_UNVERIFIED      = 'schema-unverified';

    public const TIMEZONE_DIFFERS       = 'timezone-differs';

    public const AUTHOR_FALLBACK        = 'author-fallback';

    public const CURRENCY_MISMATCH      = 'currency-mismatch';

    public const PRICE_OVERRIDDEN       = 'price-overridden';

    public const STATUS_UNMAPPED        = 'status-unmapped';

    public const STATUS_NOT_APPLIED     = 'status-not-applied';

    public const TICKET_SYNTHESIZED     = 'ticket-synthesized';

    public const TICKET_FREE_FALLBACK   = 'ticket-free-fallback';

    public const COST_UNPARSEABLE       = 'cost-unparseable';

    public const VENUE_TEXT_ONLY        = 'venue-text-only';

    public const STATE_UNRESOLVED       = 'state-unresolved';

    public const COUNTRY_UNRESOLVED     = 'country-unresolved';

    public const CONTACT_NO_EMAIL       = 'contact-no-email';

    public const CONTACT_OPTED_OUT      = 'contact-opted-out';

    public const NOT_MODELLED           = 'not-modelled';

    public const AMOUNT_INFERRED        = 'amount-inferred';

    public const REFUND_AMOUNT_INFERRED = 'refund-amount-inferred';

    public const ALREADY_MIGRATED       = 'already-migrated';

    public const TARGET_DELETED         = 'target-deleted';

    public const RECORD_FAILED          = 'record-failed';

    public const SERIES_UNMANAGED       = 'series-unmanaged';

    public const SERIES_TICKETS_DIFFER  = 'series-tickets-differ';

    public const HORIZON_EXCLUDED       = 'horizon-excluded';

    public const DOMAIN_EXCLUDED        = 'domain-excluded';

    public const RECORD_TRASHED         = 'record-trashed';

    public const TXN_STATUS_RECONCILED  = 'txn-status-reconciled';


    /**
     * Every rule id this release can report.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::SCHEMA_UNVERIFIED,
            self::TIMEZONE_DIFFERS,
            self::AUTHOR_FALLBACK,
            self::CURRENCY_MISMATCH,
            self::PRICE_OVERRIDDEN,
            self::STATUS_UNMAPPED,
            self::STATUS_NOT_APPLIED,
            self::TICKET_SYNTHESIZED,
            self::TICKET_FREE_FALLBACK,
            self::COST_UNPARSEABLE,
            self::VENUE_TEXT_ONLY,
            self::STATE_UNRESOLVED,
            self::COUNTRY_UNRESOLVED,
            self::CONTACT_NO_EMAIL,
            self::CONTACT_OPTED_OUT,
            self::NOT_MODELLED,
            self::AMOUNT_INFERRED,
            self::REFUND_AMOUNT_INFERRED,
            self::ALREADY_MIGRATED,
            self::TARGET_DELETED,
            self::RECORD_FAILED,
            self::SERIES_UNMANAGED,
            self::SERIES_TICKETS_DIFFER,
            self::HORIZON_EXCLUDED,
            self::DOMAIN_EXCLUDED,
            self::RECORD_TRASHED,
            self::TXN_STATUS_RECONCILED,
        ];
    }


    public static function isValid(string $rule): bool
    {
        return in_array($rule, self::all(), true);
    }


    /**
     * The translated, human-readable label for a rule. Labels are sprintf format
     * strings whose numbered placeholders MigrationWarning::message() fills from
     * the warning's args. Unknown rules get a generic label that still shows
     * the id, so a report never hides a warning it cannot name.
     */
    public static function label(string $rule): string
    {
        $labels = self::labels();
        if (isset($labels[ $rule ])) {
            return $labels[ $rule ];
        }
        /* translators: %s: warning rule identifier */
        return sprintf(esc_html__('Unrecognised warning rule "%s".', 'migrate-events-to-event-espresso'), esc_html($rule));
    }


    /**
     * Rule id => translated label. Built on every call (never cached) so it is
     * safe to use at any point after `init`.
     *
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return [
            /* translators: %1$s: source plugin version, %2$s: comma-separated list of verified versions */
            self::SCHEMA_UNVERIFIED      => esc_html__(
                'Source plugin version %1$s was not verified against this migration (verified: %2$s); the data layout may differ.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %1$s: the event timezone, %2$s: the site timezone */
            self::TIMEZONE_DIFFERS       => esc_html__(
                'The event timezone %1$s differs from the site timezone %2$s; times were converted.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %1$s: the source author user id */
            self::AUTHOR_FALLBACK        => esc_html__(
                'The source author (user %1$s) no longer exists; the migrating user was used instead.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %1$s: the source currency code, %2$s: the site currency code */
            self::CURRENCY_MISMATCH      => esc_html__(
                'The payment currency %1$s differs from the site currency %2$s; the amount was stored as-is.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %1$s: the price paid in the source, %2$s: the migrated ticket price */
            self::PRICE_OVERRIDDEN       => esc_html__(
                'The price paid (%1$s) differs from the ticket price (%2$s); the line item uses the price paid.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %1$s: the status domain (registration, transaction, payment, event), %2$s: the raw value */
            self::STATUS_UNMAPPED        => esc_html__(
                'The source %1$s status "%2$s" has no mapping; the record was skipped.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %1$s: the source status that was not applied */
            self::STATUS_NOT_APPLIED     => esc_html__(
                'The source status "%1$s" was recorded but not applied; the event was created as a draft.',
                'migrate-events-to-event-espresso'
            ),
            self::TICKET_SYNTHESIZED     => esc_html__(
                'The source has no explicit ticket; a "General Admission" ticket was created from the event price and capacity.',
                'migrate-events-to-event-espresso'
            ),
            self::TICKET_FREE_FALLBACK   => esc_html__(
                'The source has no price; a free ticket was created so the event is registerable.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %1$s: the unparseable cost text */
            self::COST_UNPARSEABLE       => esc_html__(
                'The source cost "%1$s" could not be parsed as a price; a free ticket was created.',
                'migrate-events-to-event-espresso'
            ),
            self::VENUE_TEXT_ONLY        => esc_html__(
                'The source only stores a free-text location; a venue was created from that text.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %1$s: the unresolved state text */
            self::STATE_UNRESOLVED       => esc_html__(
                'The state "%1$s" could not be matched to an Event Espresso state; it was kept as text.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %1$s: the unresolved country text */
            self::COUNTRY_UNRESOLVED     => esc_html__(
                'The country "%1$s" could not be matched to an Event Espresso country; it was kept as text.',
                'migrate-events-to-event-espresso'
            ),
            self::CONTACT_NO_EMAIL       => esc_html__(
                'The booking has no email address; a contact was built from the available name.',
                'migrate-events-to-event-espresso'
            ),
            self::CONTACT_OPTED_OUT      => esc_html__(
                'The attendee opted out or was anonymised in the source; the contact was created without personal details.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %1$s: the source concept name (organizer, tag, coupon, ...) */
            self::NOT_MODELLED           => esc_html__(
                'The source concept "%1$s" has no Event Espresso counterpart; it was preserved as text or listed as not migrated.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %1$s: the inferred payment amount */
            self::AMOUNT_INFERRED        => esc_html__(
                'The source recorded a payment without an amount; the booking total (%1$s) was used.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %1$s: the inferred refund amount */
            self::REFUND_AMOUNT_INFERRED => esc_html__(
                'The source recorded a refund without an amount; the paid total (%1$s) was used.',
                'migrate-events-to-event-espresso'
            ),
            self::ALREADY_MIGRATED       => esc_html__(
                'The record was already migrated by an earlier run and was skipped.',
                'migrate-events-to-event-espresso'
            ),
            self::TARGET_DELETED         => esc_html__(
                'The record was migrated by an earlier run but its Event Espresso record has since been deleted; it was skipped.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %1$s: the failure reason */
            self::RECORD_FAILED          => esc_html__(
                'The record could not be migrated: %1$s',
                'migrate-events-to-event-espresso'
            ),
            self::SERIES_UNMANAGED       => esc_html__(
                'The source series was migrated as plain events; the Recurring Events Manager does not manage them.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %1$s: the source series identifier */
            self::SERIES_TICKETS_DIFFER  => esc_html__(
                'The occurrences of source series %1$s have different tickets; the first occurrence\'s tickets were used for the whole event.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %s: number of source events */
            self::HORIZON_EXCLUDED       => esc_html__(
                '%s source event(s) end before the chosen time horizon and were not migrated.',
                'migrate-events-to-event-espresso'
            ),
            self::DOMAIN_EXCLUDED        => esc_html__(
                'Bookings were excluded by the chosen data domains and were not migrated.',
                'migrate-events-to-event-espresso'
            ),
            self::RECORD_TRASHED         => esc_html__(
                'The source record is trashed and was not migrated.',
                'migrate-events-to-event-espresso'
            ),
            /* translators: %1$s: the status Event Espresso computed, %2$s: the source status that was re-applied */
            self::TXN_STATUS_RECONCILED  => esc_html__(
                'Event Espresso computed transaction status %1$s from the payments; the source status %2$s was re-applied.',
                'migrate-events-to-event-espresso'
            ),
        ];
    }
}
// End of file WarningRule.php
// Location: EventEspresso\EventMigrator\application\services\migration/WarningRule.php
