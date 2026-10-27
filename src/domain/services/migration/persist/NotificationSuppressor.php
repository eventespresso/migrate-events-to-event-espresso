<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

use Throwable;

/**
 * Class NotificationSuppressor
 *
 * Silences every side effect of writing historical bookings (REQ-024) for as
 * long as it is active, then puts everything back exactly as it was:
 *
 * - registration messages: the deliver-notifications filter returns false;
 * - payment messages and third-party payment listeners: PaymentProcessor
 *   fires AHEE__EE_Payment_Processor__update_txn_based_on_payment (plus a
 *   __successful / __not_successful / __no_payment_made variant) on every
 *   call, and EED_Messages::payment(), eea-webhooks, eea-mailchimp and
 *   eea-affiliate-wp listen there with no filter to stop them, so the whole
 *   hook entry is taken out of $wp_filter and restored afterwards;
 * - WP-User sync on contact creation (eea-wp-user-integration);
 * - AHEE__EE_Transaction_Processor__update_transaction_and_registrations_after_checkout_or_payment and
 *   AHEE__EE_Registration__set_status__after_update (eea-webhooks, eea-affiliate-wp and eea-wait-lists listen
 *   there). On the migration path core fires them only for an IPN, which the booking writer never passes; they
 *   are silenced as well so a future change in core cannot reach those listeners.
 *
 * Exactly the hooks in SILENCED_HOOKS and the deliver-notifications filter are affected. The event step's
 * model saves (events, datetimes, tickets, venues, categories) are not wrapped and fire their normal hooks.
 *
 * The removal is scoped: suppress()/restore() nest, and only the outermost
 * restore() reinstates the snapshots, so one booking or a whole chunk can be
 * wrapped. Rollback needs no suppression: it never processes payments or
 * changes registration statuses.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class NotificationSuppressor
{
    public const DELIVER_NOTIFICATIONS_FILTER = 'FHEE__EED_Messages___maybe_registration__deliver_notifications';

    /**
     * Higher than core's own __return_true (default priority), so false wins.
     */
    public const DELIVER_NOTIFICATIONS_PRIORITY = 999;

    /**
     * Hooks whose every callback is removed while active.
     */
    public const SILENCED_HOOKS = [
        'AHEE__EE_Payment_Processor__update_txn_based_on_payment',
        'AHEE__EE_Payment_Processor__update_txn_based_on_payment__successful',
        'AHEE__EE_Payment_Processor__update_txn_based_on_payment__not_successful',
        'AHEE__EE_Payment_Processor__update_txn_based_on_payment__no_payment_made',
        'AHEE__EE_Transaction_Processor__update_transaction_and_registrations_after_checkout_or_payment',
        'AHEE__EE_Registration__set_status__after_update',
        'FHEE_EventEspresso_core_domain_services_commands_attendee_CreateAttendeeCommandHandler__findExistingAttendee__existing_attendee',
    ];

    /**
     * hook name => the WP_Hook that was registered when suppression began (absent when none was)
     *
     * @var array<string, object>
     */
    private array $snapshots = [];

    private int $depth = 0;


    /**
     * Starts (or nests) suppression.
     */
    public function suppress(): void
    {
        $this->depth++;
        if ($this->depth > 1) {
            return;
        }
        add_filter(self::DELIVER_NOTIFICATIONS_FILTER, '__return_false', self::DELIVER_NOTIFICATIONS_PRIORITY);
        $this->snapshots = [];
        foreach (self::SILENCED_HOOKS as $hook) {
            if (isset($GLOBALS['wp_filter'][ $hook ])) {
                // The WP_Hook object itself is kept and the entry unset; remove_all_actions() would empty that
                // same object in place and leave nothing to restore.
                $this->snapshots[ $hook ] = $GLOBALS['wp_filter'][ $hook ];
                unset($GLOBALS['wp_filter'][ $hook ]);
            }
        }
    }


    /**
     * Ends one level of suppression; the outermost call reinstates every snapshot exactly and drops anything
     * registered on the silenced hooks in the meantime.
     */
    public function restore(): void
    {
        if ($this->depth === 0) {
            return;
        }
        $this->depth--;
        if ($this->depth > 0) {
            return;
        }
        remove_filter(self::DELIVER_NOTIFICATIONS_FILTER, '__return_false', self::DELIVER_NOTIFICATIONS_PRIORITY);
        foreach (self::SILENCED_HOOKS as $hook) {
            unset($GLOBALS['wp_filter'][ $hook ]);
            if (isset($this->snapshots[ $hook ])) {
                $GLOBALS['wp_filter'][ $hook ] = $this->snapshots[ $hook ];
            }
        }
        $this->snapshots = [];
    }


    public function isActive(): bool
    {
        return $this->depth > 0;
    }


    /**
     * Runs $work with notifications suppressed, returns whatever it returns, and always restores, even when
     * $work throws.
     *
     * @throws Throwable Whatever $work throws, after restoring.
     */
    public function during(callable $work)
    {
        $this->suppress();
        try {
            return $work();
        } finally {
            $this->restore();
        }
    }
}
// End of file NotificationSuppressor.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/NotificationSuppressor.php
