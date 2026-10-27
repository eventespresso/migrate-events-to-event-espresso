<?php

namespace EventEspresso\EventMigrator\application\services\migration\records;

/**
 * Class SourceTicketRecord
 *
 * A source ticket type. Tickets a source only implies (price and capacity on
 * the event) are synthesised into this shape by the TicketSynthesizer.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SourceTicketRecord
{
    private string $source_id;

    private string $name;

    private ?float $price;

    private string $raw_price;

    private string $currency;

    private ?int $quantity;

    private int $min;

    private ?int $max;

    private string $description;

    private ?SourceDateTime $sale_start;

    private ?SourceDateTime $sale_end;

    private array $datetime_source_ids;

    private bool $required;

    private bool $synthesized;

    private bool $implicit;

    private string $raw_quantity;


    /**
     * @param string $source_id Source identifier of the ticket.
     * @param string $name      Ticket name.
     * @param array  $extra     Optional: price (?float), raw_price (string, the unparsed source text),
     *                          currency (string), quantity (?int, null = unlimited), min (int), max (?int),
     *                          description (string), sale_start / sale_end (SourceDateTime),
     *                          datetime_source_ids (string[]), required (bool), synthesized (bool),
     *                          implicit (bool, true when the source only has a price and capacity on the event),
     *                          raw_quantity (string, the unparsed source capacity such as "40||10").
     */
    public function __construct(string $source_id, string $name, array $extra = [])
    {
        $this->source_id           = $source_id;
        $this->name                = $name;
        $this->price               = isset($extra['price']) ? (float) $extra['price'] : null;
        $this->raw_price           = (string) ($extra['raw_price'] ?? '');
        $this->currency            = (string) ($extra['currency'] ?? '');
        $this->quantity            = isset($extra['quantity']) ? (int) $extra['quantity'] : null;
        $this->min                 = (int) ($extra['min'] ?? 0);
        $this->max                 = isset($extra['max']) ? (int) $extra['max'] : null;
        $this->description         = (string) ($extra['description'] ?? '');
        $this->sale_start          = $this->dateOrNull($extra['sale_start'] ?? null);
        $this->sale_end            = $this->dateOrNull($extra['sale_end'] ?? null);
        $this->datetime_source_ids = array_map('strval', array_values((array) ($extra['datetime_source_ids'] ?? [])));
        $this->required            = (bool) ($extra['required'] ?? false);
        $this->synthesized         = (bool) ($extra['synthesized'] ?? false);
        $this->implicit            = (bool) ($extra['implicit'] ?? false);
        $this->raw_quantity        = (string) ($extra['raw_quantity'] ?? '');
    }


    private function dateOrNull($value): ?SourceDateTime
    {
        return $value instanceof SourceDateTime ? $value : null;
    }


    public function sourceId(): string
    {
        return $this->source_id;
    }


    public function name(): string
    {
        return $this->name;
    }


    /**
     * The parsed price, or null when the source price could not be parsed.
     */
    public function price(): ?float
    {
        return $this->price;
    }


    public function rawPrice(): string
    {
        return $this->raw_price;
    }


    public function currency(): string
    {
        return $this->currency;
    }


    /**
     * Number of tickets for sale, or null when unlimited.
     */
    public function quantity(): ?int
    {
        return $this->quantity;
    }


    public function min(): int
    {
        return $this->min;
    }


    public function max(): ?int
    {
        return $this->max;
    }


    public function description(): string
    {
        return $this->description;
    }


    public function saleStart(): ?SourceDateTime
    {
        return $this->sale_start;
    }


    public function saleEnd(): ?SourceDateTime
    {
        return $this->sale_end;
    }


    /**
     * Source ids of the occurrences this ticket grants access to; empty means every occurrence.
     *
     * @return string[]
     */
    public function datetimeSourceIds(): array
    {
        return $this->datetime_source_ids;
    }


    public function isRequired(): bool
    {
        return $this->required;
    }


    public function isSynthesized(): bool
    {
        return $this->synthesized;
    }


    /**
     * True when the source has no ticket entity: the price and capacity live on the event
     * and the TicketSynthesizer turns this record into real tickets.
     */
    public function isImplicit(): bool
    {
        return $this->implicit;
    }


    public function rawQuantity(): string
    {
        return $this->raw_quantity;
    }
}
// End of file SourceTicketRecord.php
// Location: EventEspresso\EventMigrator\application\services\migration\records/SourceTicketRecord.php
