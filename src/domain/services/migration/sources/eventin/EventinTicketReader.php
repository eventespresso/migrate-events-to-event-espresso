<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\eventin;

use EventEspresso\EventMigrator\application\services\migration\records\SourceDateTime;
use EventEspresso\EventMigrator\application\services\migration\records\SourceTicketRecord;
use EventEspresso\EventMigrator\application\services\migration\records\UnmappedConcept;

/**
 * Class EventinTicketReader
 *
 * Reads the ticket variations (`etn_ticket_variations` meta, research
 * profile §3.4) of an event's occurrences. A ticket is identified by its slug,
 * which is only unique within an event, so a ticket's source id is
 * "<event post id>:<slug>" (the series parent's id for a series). The
 * occurrences of a series copy the parent's variations, so a slug seen on
 * several of them is one ticket on all those dates. Global stock moves the
 * capacity to the dates; a disabled ticket is kept (orders may use it) and
 * listed as an unmapped concept.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class EventinTicketReader
{
    /**
     * The source id of a ticket of an event.
     */
    public static function ticketId(int $event_post_id, string $slug): string
    {
        return $event_post_id . ':' . $slug;
    }


    /**
     * @param int     $event_post_id The post id that prefixes the ticket ids.
     * @param array[] $occurrences   The occurrences as ['id' => post id, 'meta' => its meta].
     * @param ?string $zone          The event's readable zone, or null.
     * @return array{records: SourceTicketRecord[], unmapped: UnmappedConcept[]}
     */
    public function read(int $event_post_id, array $occurrences, ?string $zone): array
    {
        $tickets  = [];
        $dates    = [];
        $disabled = [];
        foreach ($occurrences as $occurrence) {
            $meta    = $occurrence['meta'];
            $pooled  = $this->truthy((string) ($meta['etn_enable_global_stock'] ?? ''));
            $closing = EventinDates::parse((string) ($meta['etn_registration_deadline'] ?? ''), $zone);
            foreach ($this->variations((array) ($meta['etn_ticket_variations'] ?? [])) as $variation) {
                $slug = trim((string) ($variation['etn_ticket_slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $dates[ $slug ][] = (string) $occurrence['id'];
                if (isset($tickets[ $slug ])) {
                    continue;
                }
                $tickets[ $slug ] = [$variation, $pooled, $closing];
                if (isset($variation['etn_enable_ticket']) && ! $this->truthy((string) $variation['etn_enable_ticket'])) {
                    $disabled[] = (string) $variation['etn_ticket_name'];
                }
            }
        }
        $records = [];
        foreach ($tickets as $slug => [$variation, $pooled, $closing]) {
            $records[] = $this->record($event_post_id, $slug, $variation, $pooled, $closing, $zone, $dates[ $slug ]);
        }
        $unmapped = $disabled !== []
            ? [new UnmappedConcept('disabled-tickets', __('Disabled tickets', 'migrate-events-to-event-espresso'), $disabled)]
            : [];
        return ['records' => $records, 'unmapped' => $unmapped];
    }


    /**
     * @param array $stored The unserialised etn_ticket_variations meta.
     * @return array[]
     */
    private function variations(array $stored): array
    {
        return array_values(array_filter($stored, 'is_array'));
    }


    /**
     * @param int             $event_post_id The post id that prefixes the ticket id.
     * @param string          $slug          The ticket slug.
     * @param array           $variation     Its variation row.
     * @param bool            $pooled        Whether the event uses global stock.
     * @param ?SourceDateTime $closing       The event's registration deadline, or null.
     * @param ?string         $zone          The event's readable zone.
     * @param string[]        $datetime_ids  The occurrences the ticket is on.
     */
    private function record(
        int $event_post_id,
        string $slug,
        array $variation,
        bool $pooled,
        ?SourceDateTime $closing,
        ?string $zone,
        array $datetime_ids
    ): SourceTicketRecord {
        $capacity  = (int) ($variation['etn_avaiilable_tickets'] ?? -1);
        $unlimited = $pooled
            || $capacity <= 0
            || $this->truthy((string) ($variation['etn_unlimited_tickets'] ?? ''))
            || $this->truthy((string) ($variation['etn_ticket_unlimited'] ?? ''));
        $max       = (int) ($variation['etn_max_ticket'] ?? 0);
        $start     = EventinDates::local((string) ($variation['start_date'] ?? ''), (string) ($variation['start_time'] ?? ''), $zone);
        $end       = EventinDates::local((string) ($variation['end_date'] ?? ''), (string) ($variation['end_time'] ?? ''), $zone);
        return new SourceTicketRecord(
            self::ticketId($event_post_id, $slug),
            (string) ($variation['etn_ticket_name'] ?? ''),
            [
                'price'               => (float) ($variation['etn_ticket_price'] ?? 0),
                'raw_price'           => (string) ($variation['etn_ticket_price'] ?? ''),
                'quantity'            => $unlimited ? null : $capacity,
                'min'                 => (int) ($variation['etn_min_ticket'] ?? 0),
                'max'                 => $max > 0 ? $max : null,
                'sale_start'          => $start,
                'sale_end'            => $end ?? $closing,
                'datetime_source_ids' => $datetime_ids,
            ]
        );
    }


    private function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true'], true);
    }
}
// End of file EventinTicketReader.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\eventin/EventinTicketReader.php
