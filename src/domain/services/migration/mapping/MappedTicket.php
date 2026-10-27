<?php

namespace EventEspresso\EventMigrator\domain\services\migration\mapping;

use EventEspresso\EventMigrator\domain\services\migration\crosswalk\Crosswalk;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;

/**
 * Class MappedTicket
 *
 * The EE_Ticket fields, base EE_Price fields and datetime links a persister
 * will write for one source ticket. Sale-window dates are UTC
 * DateTimeImmutable; the persister converts them at the model boundary.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class MappedTicket
{
    /**
     * The base price type every migrated ticket price uses.
     */
    public const BASE_PRICE_TYPE_ID = 1;

    private string $source_id;

    private array $fields;

    private array $datetime_source_ids;


    /**
     * @param string   $source_id           The source identifier of the ticket.
     * @param array    $fields              EE_Ticket field name => value (TKT_price is a float, dates are
     *                                      DateTimeImmutable, unlimited quantities are EE_INF).
     * @param string[] $datetime_source_ids The source ids of the occurrences the ticket is sold for; empty means
     *                                      every occurrence of the event.
     */
    public function __construct(string $source_id, array $fields, array $datetime_source_ids)
    {
        $this->source_id           = $source_id;
        $this->fields              = $fields;
        $this->datetime_source_ids = array_values(array_map('strval', $datetime_source_ids));
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    public function fields(): array
    {
        return $this->fields;
    }


    public function price(): float
    {
        return (float) ($this->fields['TKT_price'] ?? 0.0);
    }


    /**
     * @return string[]
     */
    public function datetimeSourceIds(): array
    {
        return $this->datetime_source_ids;
    }


    /**
     * The base EE_Price written for the ticket.
     */
    public function priceFields(): array
    {
        return [
            'PRT_ID'     => self::BASE_PRICE_TYPE_ID,
            'PRC_name'   => (string) ($this->fields['TKT_name'] ?? ''),
            'PRC_amount' => $this->price(),
        ];
    }


    public function fingerprint(): string
    {
        return Crosswalk::fingerprint($this->fields, CrosswalkKeys::MODEL_TICKET);
    }


    public function priceFingerprint(): string
    {
        return Crosswalk::fingerprint($this->priceFields(), CrosswalkKeys::MODEL_PRICE);
    }
}
// End of file MappedTicket.php
// Location: EventEspresso\EventMigrator\domain\services\migration\mapping/MappedTicket.php
