<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

use DateTime;
use DateTimeImmutable;
use EE_Attendee;
use EE_Base_Class;
use EE_Line_Item;
use EE_Payment;
use EE_Registration;
use EE_Registry;
use EE_Session;
use EE_Ticket;
use EE_Transaction;
use EEM_Registration;
use EEM_Registration_Payment;
use EEM_Ticket;
use EventEspresso\core\domain\services\commands\registration\CreateRegistrationCommand;
use EventEspresso\core\domain\services\commands\transaction\CreateTransactionCommand;
use EventEspresso\core\services\commands\CommandBusInterface;
use EventEspresso\core\services\payments\PaymentProcessor;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\mapping\MappedRegistration;
use RuntimeException;

/**
 * Class BookingWriter
 *
 * The model writes behind BookingPersister, through core's own paths: the
 * create-transaction and create-registration commands, ticket line items
 * from the real ticket, and PaymentProcessor for payments (which creates the
 * registration payments and recalculates TXN_paid and the status). Objects it
 * saves are kept until reset(); callers deal in ids.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class BookingWriter
{
    private Crosswalk $crosswalk;

    private CommandBusInterface $command_bus;

    private PaymentProcessor $payment_processor;

    /**
     * @var array<string, array<int, EE_Base_Class>> model => id => object saved since the last reset()
     */
    private array $saved = [];


    public function __construct(
        Crosswalk $crosswalk,
        CommandBusInterface $command_bus,
        PaymentProcessor $payment_processor
    ) {
        $this->crosswalk         = $crosswalk;
        $this->command_bus       = $command_bus;
        $this->payment_processor = $payment_processor;
    }


    public function reset(): void
    {
        $this->saved = [];
    }


    /**
     * @throws RuntimeException When the contact cannot be saved.
     */
    public function createContact(array $fields): int
    {
        $attendee = EE_Attendee::new_instance($fields);
        if (! $attendee instanceof EE_Attendee || ! $attendee->save()) {
            throw new RuntimeException(esc_html__('A migrated contact could not be saved.', 'migrate-events-to-event-espresso'));
        }
        return $this->keep(CrosswalkKeys::MODEL_ATTENDEE, $attendee);
    }


    /**
     * Creates the transaction (and its total line item) through core's command.
     *
     * @return array{transaction_id: int, total_line_item_id: int}
     * @throws RuntimeException When core does not return a transaction with a total line item.
     */
    public function createTransaction(?DateTimeImmutable $timestamp, float $total, string $status): array
    {
        $details = ['TXN_total' => $total, 'STS_ID' => $status, 'TXN_reg_steps' => []];
        if ($timestamp !== null) {
            $details['TXN_timestamp'] = DateTime::createFromImmutable($timestamp);
        }
        $transaction = $this->command_bus->execute(new CreateTransactionCommand(null, $details));
        $total_line  = $transaction instanceof EE_Transaction ? $transaction->total_line_item() : null;
        if (! $total_line instanceof EE_Line_Item) {
            throw new RuntimeException(esc_html__('A migrated transaction could not be created.', 'migrate-events-to-event-espresso'));
        }
        $this->keep(CrosswalkKeys::MODEL_LINE_ITEM, $total_line);
        return [
            'transaction_id'     => $this->keep(CrosswalkKeys::MODEL_TRANSACTION, $transaction),
            'total_line_item_id' => (int) $total_line->ID(),
        ];
    }


    /**
     * Adds one seat to the transaction's ticket lines, built from the real ticket (TransactionLineItems): seats of the
     * same ticket at the same price share a line. A seat whose price paid differs from the ticket price gets a
     * line with no price sub-items carrying the price paid.
     *
     * @throws RuntimeException When the ticket does not exist.
     */
    public function createTicketLineItem(int $transaction_id, int $ticket_id, ?float $price_paid): int
    {
        $total_line = $this->transaction($transaction_id)->total_line_item();
        $line_item  = TransactionLineItems::addSeat($total_line, $this->ticket($ticket_id), $price_paid);
        $total_line->recalculate_total_including_taxes();
        $total_line->save_this_and_descendants_to_txn($transaction_id);
        return $this->keep(CrosswalkKeys::MODEL_LINE_ITEM, $line_item);
    }


    /**
     * Creates one registration through core's command with the source status, then applies the contact,
     * price paid, date and source code. Never calls EE_Registration::set_status().
     *
     * @throws RuntimeException When core does not return a registration.
     */
    public function createRegistration(
        int $transaction_id,
        int $line_item_id,
        int $ticket_id,
        MappedRegistration $mapped,
        int $attendee_id
    ): int {
        $this->ensureSession();
        $registration = $this->command_bus->execute(
            new CreateRegistrationCommand(
                $this->transaction($transaction_id),
                $this->saved(CrosswalkKeys::MODEL_LINE_ITEM, $line_item_id),
                $mapped->count(),
                $mapped->groupSize(),
                $mapped->status(),
                $this->ticket($ticket_id)
            )
        );
        if (! $registration instanceof EE_Registration) {
            throw new RuntimeException(esc_html__('A migrated registration could not be created.', 'migrate-events-to-event-espresso'));
        }
        $registration->set_attendee_id($attendee_id);
        $registration->set_final_price($mapped->pricePaid());
        if ($mapped->date() !== null) {
            $registration->set_reg_date(DateTime::createFromImmutable($mapped->date()));
        }
        $registration->save();
        if ($mapped->code() !== null) {
            // EE_Registration refuses to replace the code core just generated, so the source reference is written
            // through the model and the cached entity refreshed from it.
            EEM_Registration::instance()->update(['REG_code' => $mapped->code()], [['REG_ID' => $registration->ID()]]);
            $refreshed = EEM_Registration::instance()->refresh_entity_map_from_db($registration->ID());
            $registration = $refreshed instanceof EE_Registration ? $refreshed : $registration;
        }
        return $this->keep(CrosswalkKeys::MODEL_REGISTRATION, $registration);
    }


    /**
     * One more sold on the ticket and its datetimes, for an approved registration.
     */
    public function increaseSold(int $ticket_id): void
    {
        $this->ticket($ticket_id)->increaseSold(1);
    }


    /**
     * Makes TXN_total equal the line items after every seat was added, and returns it.
     */
    public function syncTransactionTotal(int $transaction_id): float
    {
        $transaction = $this->transaction($transaction_id);
        $total       = $transaction->total_line_item()->recalculate_total_including_taxes();
        $transaction->set_total($total);
        $transaction->save();
        return $total;
    }


    /**
     * @throws RuntimeException When the payment cannot be saved.
     */
    public function createPayment(int $transaction_id, array $fields, int $payment_method_id): int
    {
        $fields['TXN_ID'] = $transaction_id;
        $fields['PMD_ID'] = $payment_method_id;
        if (($fields['PAY_timestamp'] ?? null) instanceof DateTimeImmutable) {
            $fields['PAY_timestamp'] = DateTime::createFromImmutable($fields['PAY_timestamp']);
        }
        $payment = EE_Payment::new_instance($fields);
        if (! $payment instanceof EE_Payment || ! $payment->save()) {
            throw new RuntimeException(esc_html__('A migrated payment could not be saved.', 'migrate-events-to-event-espresso'));
        }
        return $this->keep(CrosswalkKeys::MODEL_PAYMENT, $payment);
    }


    /**
     * Applies the payment the way core does (registration payments, TXN_paid, status) and returns the ids of
     * the registration-payment rows it created. Call inside NotificationSuppressor.
     *
     * @return int[]
     */
    public function processPayment(int $transaction_id, int $payment_id): array
    {
        $this->payment_processor->updateTransactionBasedOnPayment(
            $this->transaction($transaction_id),
            $this->saved(CrosswalkKeys::MODEL_PAYMENT, $payment_id)
        );
        return array_map('intval', EEM_Registration_Payment::instance()->get_col([['PAY_ID' => $payment_id]]));
    }


    public function transactionStatus(int $transaction_id): string
    {
        return (string) $this->transaction($transaction_id)->status_ID();
    }


    public function setTransactionStatus(int $transaction_id, string $status): void
    {
        $transaction = $this->transaction($transaction_id);
        $transaction->set_status($status);
        $transaction->save();
    }


    /**
     * Writes the crosswalk keys; the fingerprint is read from the saved record (Crosswalk::fingerprintOf()),
     * the same way rollback pre-flight reads it back.
     */
    public function recordCrosswalk(
        string $model,
        int $id,
        string $type,
        string $source_id,
        MigrationContext $context,
        ?int $wp_user = null
    ): void {
        $object = $this->saved($model, $id);
        $this->crosswalk->record(
            $object,
            $context->source(),
            $type,
            $source_id,
            $context->runId(),
            Crosswalk::fingerprintOf($object),
            $context,
            $wp_user
        );
    }


    /**
     * Core's registration service reads the EE session id, which is not loaded in a WP-CLI or cron request.
     */
    protected function ensureSession(): void
    {
        if (! EE_Registry::instance()->SSN instanceof EE_Session) {
            EE_Registry::instance()->load_core('Session');
        }
    }


    private function keep(string $model, EE_Base_Class $object): int
    {
        $id                           = (int) $object->ID();
        $this->saved[ $model ][ $id ] = $object;
        return $id;
    }


    private function transaction(int $transaction_id): EE_Transaction
    {
        $transaction = $this->saved(CrosswalkKeys::MODEL_TRANSACTION, $transaction_id);
        if (! $transaction instanceof EE_Transaction) {
            throw new RuntimeException(esc_html__('The migrated transaction is not known to this step.', 'migrate-events-to-event-espresso'));
        }
        return $transaction;
    }


    /**
     * @throws RuntimeException When the ticket does not exist.
     */
    private function ticket(int $ticket_id): EE_Ticket
    {
        $ticket = $this->saved[ CrosswalkKeys::MODEL_TICKET ][ $ticket_id ] ?? EEM_Ticket::instance()->get_one_by_ID($ticket_id);
        if (! $ticket instanceof EE_Ticket) {
            throw new RuntimeException(esc_html__('A migrated booking refers to a ticket that does not exist.', 'migrate-events-to-event-espresso'));
        }
        $this->saved[ CrosswalkKeys::MODEL_TICKET ][ $ticket_id ] = $ticket;
        return $ticket;
    }


    /**
     * @throws RuntimeException When the object was not saved by this step.
     */
    private function saved(string $model, int $id): EE_Base_Class
    {
        if (! isset($this->saved[ $model ][ $id ])) {
            throw new RuntimeException(
                sprintf(
                    /* translators: %1$s: Event Espresso model name, %2$s: record id */
                    esc_html__('The migrated %1$s %2$s is not known to this step.', 'migrate-events-to-event-espresso'),
                    esc_html($model),
                    esc_html((string) $id)
                )
            );
        }
        return $this->saved[ $model ][ $id ];
    }
}
// End of file BookingWriter.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/BookingWriter.php
