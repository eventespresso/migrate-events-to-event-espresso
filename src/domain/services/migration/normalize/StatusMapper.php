<?php

namespace EventEspresso\EventMigrator\domain\services\migration\normalize;

use EEM_Event;
use EEM_Payment;
use EEM_Registration;
use EEM_Transaction;
use EventEspresso\core\exceptions\InvalidDataTypeException;
use EventEspresso\EventMigrator\application\services\migration\records\StatusTable;
use EventEspresso\EventMigrator\domain\exceptions\UnmappedStatusException;

/**
 * Class StatusMapper
 *
 * Maps a raw source status to an Event Espresso status through the adapter's
 * StatusTable. There is no default branch: a raw value missing from its map
 * stops that record (REQ-017). Pure: no database access.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class StatusMapper
{
    public const DOMAIN_REGISTRATION = 'registration';

    public const DOMAIN_TRANSACTION  = 'transaction';

    public const DOMAIN_PAYMENT      = 'payment';

    public const DOMAIN_EVENT        = 'event';


    /**
     * @param StatusTable $table       The adapter's status table.
     * @param string      $raw         The raw source booking status.
     * @param string      $source_type The source entity type, for the failure report.
     * @param string      $source_id   The source entity id, for the failure report.
     * @return string An EEM_Registration status id such as "RAP".
     * @throws UnmappedStatusException When the raw value has no entry.
     */
    public function registration(
        StatusTable $table,
        string $raw,
        string $source_type = 'booking',
        string $source_id = ''
    ): string {
        return $this->lookup(self::DOMAIN_REGISTRATION, $table->registration(), $raw, $source_type, $source_id);
    }


    /**
     * @param StatusTable $table       The adapter's status table.
     * @param string      $raw         The raw source order or transaction status.
     * @param string      $source_type The source entity type, for the failure report.
     * @param string      $source_id   The source entity id, for the failure report.
     * @return string An EEM_Transaction status code such as "TCM".
     * @throws UnmappedStatusException When the raw value has no entry.
     */
    public function transaction(
        StatusTable $table,
        string $raw,
        string $source_type = 'booking',
        string $source_id = ''
    ): string {
        return $this->lookup(self::DOMAIN_TRANSACTION, $table->transaction(), $raw, $source_type, $source_id);
    }


    /**
     * @param StatusTable $table       The adapter's status table.
     * @param string      $raw         The raw source payment status.
     * @param string      $source_type The source entity type, for the failure report.
     * @param string      $source_id   The source entity id, for the failure report.
     * @return string An EEM_Payment status id such as "PAP".
     * @throws UnmappedStatusException When the raw value has no entry.
     */
    public function payment(
        StatusTable $table,
        string $raw,
        string $source_type = 'payment',
        string $source_id = ''
    ): string {
        return $this->lookup(self::DOMAIN_PAYMENT, $table->payment(), $raw, $source_type, $source_id);
    }


    /**
     * @param StatusTable $table       The adapter's status table.
     * @param string      $raw         The raw source event status.
     * @param string      $source_type The source entity type, for the failure report.
     * @param string      $source_id   The source entity id, for the failure report.
     * @return string One of "publish", "draft", "private", "cancelled" or "postponed".
     * @throws UnmappedStatusException When the raw value has no entry.
     */
    public function event(
        StatusTable $table,
        string $raw,
        string $source_type = 'event',
        string $source_id = ''
    ): string {
        return $this->lookup(self::DOMAIN_EVENT, $table->event(), $raw, $source_type, $source_id);
    }


    /**
     * The form a raw source value takes before lookup: trimmed and lower-cased. Table keys must already be
     * in this form.
     */
    public static function normalise(string $raw): string
    {
        return strtolower(trim($raw));
    }


    /**
     * The Event Espresso values each domain accepts.
     *
     * @return string[]
     */
    public static function allowedValues(string $domain): array
    {
        switch ($domain) {
            case self::DOMAIN_REGISTRATION:
                return [
                    EEM_Registration::status_id_incomplete,
                    EEM_Registration::status_id_not_approved,
                    EEM_Registration::status_id_pending_payment,
                    EEM_Registration::status_id_wait_list,
                    EEM_Registration::status_id_approved,
                    EEM_Registration::status_id_cancelled,
                    EEM_Registration::status_id_declined,
                ];
            case self::DOMAIN_TRANSACTION:
                return [
                    EEM_Transaction::failed_status_code,
                    EEM_Transaction::abandoned_status_code,
                    EEM_Transaction::incomplete_status_code,
                    EEM_Transaction::complete_status_code,
                    EEM_Transaction::overpaid_status_code,
                ];
            case self::DOMAIN_PAYMENT:
                return [
                    EEM_Payment::status_id_approved,
                    EEM_Payment::status_id_pending,
                    EEM_Payment::status_id_cancelled,
                    EEM_Payment::status_id_declined,
                    EEM_Payment::status_id_failed,
                ];
            case self::DOMAIN_EVENT:
                return ['publish', 'draft', 'private', EEM_Event::cancelled, EEM_Event::postponed];
        }
        return [];
    }


    /**
     * Problems with an adapter's table: keys that are not trimmed lower-case and values that are not
     * Event Espresso statuses of their domain. Adapter tests assert this list is empty.
     *
     * @return string[] One human-readable line per problem; empty when the table is sound.
     */
    public function validate(StatusTable $table): array
    {
        $maps     = [
            self::DOMAIN_REGISTRATION => $table->registration(),
            self::DOMAIN_TRANSACTION  => $table->transaction(),
            self::DOMAIN_PAYMENT      => $table->payment(),
            self::DOMAIN_EVENT        => $table->event(),
        ];
        $problems = [];
        foreach ($maps as $domain => $map) {
            $allowed = self::allowedValues($domain);
            foreach ($map as $key => $value) {
                if (self::normalise((string) $key) !== (string) $key) {
                    $problems[] = $domain . ' key "' . $key . '" is not trimmed lower-case';
                }
                if (! in_array($value, $allowed, true)) {
                    $problems[] = $domain . ' key "' . $key . '" maps to "' . (is_scalar($value) ? $value : gettype($value))
                        . '", which is not a ' . $domain . ' status';
                }
            }
        }
        return $problems;
    }


    /**
     * @param string                    $domain      One of the DOMAIN_* constants.
     * @param array<int|string, string> $map         The adapter's raw status => EE status map for the domain.
     * @param string                    $raw         The raw source value.
     * @param string                    $source_type The source entity type, for the failure report.
     * @param string                    $source_id   The source entity id, for the failure report.
     * @throws UnmappedStatusException When the raw value has no entry.
     * @throws InvalidDataTypeException When the table maps to something that is not an EE status.
     */
    private function lookup(string $domain, array $map, string $raw, string $source_type, string $source_id): string
    {
        $key = self::normalise($raw);
        if (! array_key_exists($key, $map)) {
            // The exception escapes its own message; the ids are data.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new UnmappedStatusException($domain, $raw, $source_type, $source_id);
        }
        $status = $map[ $key ];
        if (! is_string($status) || ! in_array($status, self::allowedValues($domain), true)) {
            // The domain is a class constant; the key and the offending value are escaped.
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new InvalidDataTypeException(
                '$table->' . $domain . '()["' . esc_html($key) . '"]',
                esc_html(is_scalar($status) ? (string) $status : gettype($status)),
                'an Event Espresso ' . $domain . ' status'
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $status;
    }
}
// End of file StatusMapper.php
// Location: EventEspresso\EventMigrator\domain\services\migration\normalize/StatusMapper.php
