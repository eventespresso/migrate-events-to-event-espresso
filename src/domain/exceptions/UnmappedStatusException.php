<?php

namespace EventEspresso\EventMigrator\domain\exceptions;

use EventEspresso\EventMigrator\application\services\migration\WarningRule;

/**
 * Class UnmappedStatusException
 *
 * A raw source status has no entry in the adapter's StatusTable. REQ-017:
 * the record stops and is reported, never defaulted. A record-level
 * exception, so the run continues with the next record.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class UnmappedStatusException extends MigrationRecordException
{
    private string $domain;

    private string $raw_status;


    /**
     * @param string $domain      One of "registration", "transaction", "payment" or "event".
     * @param string $raw_status  The source value that has no mapping.
     * @param string $source_type The source entity type, when known.
     * @param string $source_id   The source entity id, when known.
     */
    public function __construct(string $domain, string $raw_status, string $source_type = '', string $source_id = '')
    {
        $this->domain     = $domain;
        $this->raw_status = $raw_status;
        parent::__construct(
            sprintf(
                /* translators: %1$s: status domain (registration, transaction, payment, event), %2$s: raw value */
                esc_html__('The source %1$s status "%2$s" has no mapping.', 'migrate-events-to-event-espresso'),
                esc_html($domain),
                esc_html($raw_status)
            ),
            WarningRule::STATUS_UNMAPPED,
            $source_type,
            $source_id
        );
    }


    public function domain(): string
    {
        return $this->domain;
    }


    public function rawStatus(): string
    {
        return $this->raw_status;
    }
}
// End of file UnmappedStatusException.php
// Location: EventEspresso\EventMigrator\domain\exceptions/UnmappedStatusException.php
