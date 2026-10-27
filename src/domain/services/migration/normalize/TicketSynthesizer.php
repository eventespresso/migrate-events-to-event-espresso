<?php

namespace EventEspresso\EventMigrator\domain\services\migration\normalize;

use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\records\SourceEventRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceTicketRecord;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;

/**
 * Class TicketSynthesizer
 *
 * Makes sure every migrated event can be registered for (REQ-013). Explicit
 * source tickets pass through. A source that only has a price and capacity on
 * the event hands over one implicit ticket record, which becomes a "General
 * Admission" ticket, or one ticket per slot for a "10||20||5" style multiprice.
 * Missing or unreadable prices become free tickets with a warning. Pure.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class TicketSynthesizer
{
    /**
     * Separator of the multi-value price, capacity and name lists some sources store.
     */
    public const MULTI_SEPARATOR = '||';

    /**
     * Words a source may use for "no charge".
     */
    private const FREE_WORDS = ['free', 'no charge', 'gratis'];


    /**
     * The tickets to create for an event.
     *
     * @return SourceTicketRecord[] At least one ticket.
     */
    public function fromEvent(SourceEventRecord $event, MigrationContext $context): array
    {
        $explicit = [];
        $implicit = [];
        foreach ($event->tickets() as $ticket) {
            if ($ticket->isImplicit()) {
                $implicit[] = $ticket;
            } else {
                $explicit[] = $ticket;
            }
        }
        if ($explicit !== []) {
            return $explicit;
        }
        if ($implicit !== []) {
            return $this->expandImplicit($implicit[0], $event, $context);
        }
        $context->addWarning(
            new MigrationWarning(
                WarningRule::TICKET_FREE_FALLBACK,
                CrosswalkKeys::TYPE_TICKET,
                $event->sourceId()
            )
        );
        return [$this->freeTicket($event->sourceId() . ':free', $this->generalAdmissionName())];
    }


    /**
     * A price written by a human as a number: "25", "25.00", "$25", "25,00", "1,234.50", "EUR 12,50".
     * Null for anything else, including ranges ("10 - 20"), negatives, words and empty text. Free words and
     * "0" are not handled here; see isFreeText().
     */
    public static function parsePrice(string $text): ?float
    {
        $pattern = '/^\s*(?:[^\d\s.,+\-]{1,4}\s?)?'
            . '(\d{1,3}(?:[.,\s]\d{3})+(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?)'
            . '\s?(?:[^\d\s.,+\-]{1,4})?\s*$/u';
        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }
        $number = $matches[1];
        // A trailing separator followed by one or two digits is the decimal part; every other separator groups thousands.
        if (preg_match('/^(.*)[.,](\d{1,2})$/', $number, $parts) === 1) {
            $whole = preg_replace('/[.,\s]/', '', $parts[1]);
            return (float) ($whole . '.' . $parts[2]);
        }
        return (float) preg_replace('/[.,\s]/', '', $number);
    }


    /**
     * Whether the text says the event is free: empty is not free text, "0" and the free words are.
     */
    public static function isFreeText(string $text): bool
    {
        $text = strtolower(trim($text));
        return $text === '0' || in_array($text, self::FREE_WORDS, true);
    }


    /**
     * @return SourceTicketRecord[]
     */
    private function expandImplicit(
        SourceTicketRecord $implicit,
        SourceEventRecord $event,
        MigrationContext $context
    ): array {
        $prices = $this->slots($implicit->rawPrice());
        if ($prices === [] && $implicit->price() !== null) {
            $prices = [(string) $implicit->price()];
        }
        $names  = $this->slots($implicit->name());
        $counts = $this->slots($implicit->rawQuantity());
        $multi  = count($prices) > 1;

        $tickets = [];
        $slots   = $prices === [] ? [''] : $prices;
        foreach ($slots as $index => $price_text) {
            $price = $this->priceFor($price_text, $implicit->sourceId(), $context);
            if ($price !== null && ! $multi) {
                $context->addWarning(
                    new MigrationWarning(
                        WarningRule::TICKET_SYNTHESIZED,
                        CrosswalkKeys::TYPE_TICKET,
                        $implicit->sourceId()
                    )
                );
            }
            $tickets[] = $this->ticketFor(
                $implicit,
                $multi ? $implicit->sourceId() . ':' . $index : $implicit->sourceId(),
                $this->nameFor($names, $index, $multi),
                $price ?? 0.0,
                $this->quantityFor($implicit, $counts, $index, $multi)
            );
        }
        if ($multi) {
            $context->addWarning(
                new MigrationWarning(
                    WarningRule::TICKET_SYNTHESIZED,
                    CrosswalkKeys::TYPE_TICKET,
                    $implicit->sourceId()
                )
            );
        }
        return $tickets;
    }


    /**
     * The price of one slot: parsed, or zero for free text. Null, after adding the warning, when the text is
     * blank or unreadable; the caller then makes the ticket free.
     */
    private function priceFor(string $text, string $source_id, MigrationContext $context): ?float
    {
        if (self::isFreeText($text)) {
            return 0.0;
        }
        $price = self::parsePrice($text);
        if ($price !== null) {
            return $price;
        }
        $context->addWarning(
            $text === ''
                ? new MigrationWarning(WarningRule::TICKET_FREE_FALLBACK, CrosswalkKeys::TYPE_TICKET, $source_id)
                : new MigrationWarning(WarningRule::COST_UNPARSEABLE, CrosswalkKeys::TYPE_TICKET, $source_id, [$text])
        );
        return null;
    }


    /**
     * @param string[] $names The slots of the implicit ticket's name list.
     * @param int      $index The price slot being built.
     * @param bool     $multi Whether the ticket has several price slots.
     */
    private function nameFor(array $names, int $index, bool $multi): string
    {
        $name = trim($names[ $index ] ?? '');
        if ($name !== '' && ($multi || count($names) === 1)) {
            return $name;
        }
        $general = $this->generalAdmissionName();
        return $multi ? sprintf('%s %d', $general, $index + 1) : $general;
    }


    /**
     * The capacity of one slot, null when unlimited. A single capacity shared by several prices is a pool
     * that belongs on the datetime, so each ticket is left unlimited.
     *
     * @param SourceTicketRecord $implicit The implicit ticket record.
     * @param string[]           $counts   The slots of its raw capacity list.
     * @param int                $index    The price slot being built.
     * @param bool               $multi    Whether the ticket has several price slots.
     */
    private function quantityFor(SourceTicketRecord $implicit, array $counts, int $index, bool $multi): ?int
    {
        if ($multi) {
            return count($counts) > 1 ? $this->quantity($counts[ $index ] ?? '') : null;
        }
        return $counts === [] ? $implicit->quantity() : $this->quantity($counts[0]);
    }


    /**
     * A positive capacity, or null (unlimited) for blank, non-numeric and negative values such as "-1".
     */
    private function quantity(string $text): ?int
    {
        $text = trim($text);
        return preg_match('/^\d+$/D', $text) === 1 && (int) $text > 0 ? (int) $text : null;
    }


    private function ticketFor(
        SourceTicketRecord $implicit,
        string $source_id,
        string $name,
        float $price,
        ?int $quantity
    ): SourceTicketRecord {
        return new SourceTicketRecord(
            $source_id,
            $name,
            [
                'price'               => $price,
                'raw_price'           => $implicit->rawPrice(),
                'currency'            => $implicit->currency(),
                'quantity'            => $quantity,
                'min'                 => $implicit->min(),
                'max'                 => $implicit->max(),
                'description'         => $implicit->description(),
                'sale_start'          => $implicit->saleStart(),
                'sale_end'            => $implicit->saleEnd(),
                'datetime_source_ids' => $implicit->datetimeSourceIds(),
                'required'            => $implicit->isRequired(),
                'synthesized'         => true,
            ]
        );
    }


    private function freeTicket(string $source_id, string $name): SourceTicketRecord
    {
        return new SourceTicketRecord($source_id, $name, ['price' => 0.0, 'synthesized' => true]);
    }


    /**
     * The values of a "||" separated list, trimmed; an empty string has no slots.
     *
     * @return string[]
     */
    private function slots(string $list): array
    {
        $list = trim($list);
        return $list === '' ? [] : array_map('trim', explode(self::MULTI_SEPARATOR, $list));
    }


    private function generalAdmissionName(): string
    {
        return __('General Admission', 'migrate-events-to-event-espresso');
    }
}
// End of file TicketSynthesizer.php
// Location: EventEspresso\EventMigrator\domain\services\migration\normalize/TicketSynthesizer.php
