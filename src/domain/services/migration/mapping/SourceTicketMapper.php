<?php

namespace EventEspresso\EventMigrator\domain\services\migration\mapping;

use DateTimeImmutable;
use DateTimeZone;
use EventEspresso\EventMigrator\application\services\migration\MigrationContext;
use EventEspresso\EventMigrator\application\services\migration\MigrationWarning;
use EventEspresso\EventMigrator\application\services\migration\records\SourceEventRecord;
use EventEspresso\EventMigrator\application\services\migration\records\SourceTicketRecord;
use EventEspresso\EventMigrator\application\services\migration\WarningRule;
use EventEspresso\EventMigrator\domain\services\migration\crosswalk\CrosswalkKeys;
use EventEspresso\EventMigrator\domain\services\migration\normalize\EventGroup;
use EventEspresso\EventMigrator\domain\services\migration\normalize\TicketSynthesizer;
use EventEspresso\EventMigrator\domain\services\migration\normalize\TimezoneNormalizer;

/**
 * Class SourceTicketMapper
 *
 * Turns an event's source tickets (explicit, or synthesised by the
 * TicketSynthesizer) into the MappedTickets a persister writes (REQ-013),
 * with their datetime links and sale window in UTC. Used by
 * SourceEventMapper. Pure apart from the warnings it adds to the context.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
class SourceTicketMapper
{
    private TicketSynthesizer $synthesizer;


    public function __construct(TicketSynthesizer $synthesizer)
    {
        $this->synthesizer = $synthesizer;
    }


    /**
     * @param SourceEventRecord  $event      The source event.
     * @param EventGroup         $group      Its group; a collapsed series sells its tickets for every occurrence.
     * @param MappedDatetime[]   $datetimes  The event's mapped occurrences.
     * @param TimezoneNormalizer $normalizer The run's normalizer, for the sale window.
     * @param MigrationContext   $context    The run, for warnings.
     * @return MappedTicket[]
     */
    public function map(
        SourceEventRecord $event,
        EventGroup $group,
        array $datetimes,
        TimezoneNormalizer $normalizer,
        MigrationContext $context
    ): array {
        $known  = array_map(static fn(MappedDatetime $datetime): string => $datetime->sourceId(), $datetimes);
        $mapped = [];
        foreach ($this->synthesizer->fromEvent($event, $context) as $ticket) {
            // A collapsed series sells the first occurrence's tickets for every occurrence.
            $refs     = $group->isSeries() ? [] : array_values(array_intersect($ticket->datetimeSourceIds(), $known));
            $linked   = $refs === [] ? $datetimes : array_filter(
                $datetimes,
                static fn(MappedDatetime $datetime): bool => in_array($datetime->sourceId(), $refs, true)
            );
            $mapped[] = new MappedTicket(
                $ticket->sourceId(),
                $this->ticketFields($ticket, $event, $linked, $normalizer, $context),
                $refs
            );
        }
        return $mapped;
    }


    /**
     * EE_Ticket fields. A missing sale start is the mapping time; a missing sale end is the end of the last
     * linked occurrence, because EE's own default ("now") would close sales the moment the ticket is saved.
     *
     * @param SourceTicketRecord $ticket     The source ticket.
     * @param SourceEventRecord  $event      The source event, for its timezone.
     * @param MappedDatetime[]   $linked     The occurrences the ticket is sold for.
     * @param TimezoneNormalizer $normalizer The run's normalizer.
     * @param MigrationContext   $context    The run, for warnings.
     */
    private function ticketFields(
        SourceTicketRecord $ticket,
        SourceEventRecord $event,
        array $linked,
        TimezoneNormalizer $normalizer,
        MigrationContext $context
    ): array {
        $price = $ticket->price();
        if ($price === null) {
            $context->addWarning(
                new MigrationWarning(
                    WarningRule::COST_UNPARSEABLE,
                    CrosswalkKeys::TYPE_TICKET,
                    $ticket->sourceId(),
                    [$ticket->rawPrice()]
                )
            );
            $price = 0.0;
        }
        $last_end = null;
        foreach ($linked as $datetime) {
            $last_end = $last_end === null || $datetime->end() > $last_end ? $datetime->end() : $last_end;
        }
        $sale_start = $ticket->saleStart();
        $sale_end   = $ticket->saleEnd();
        $quantity   = $ticket->quantity();
        $name       = trim($ticket->name());
        return [
            'TKT_name'        => $name !== '' ? $name : __('General Admission', 'migrate-events-to-event-espresso'),
            'TKT_description' => wp_kses_post($ticket->description()),
            'TKT_price'       => max(0.0, $price),
            'TKT_qty'         => $quantity === null || $quantity < 0 ? EE_INF : $quantity,
            'TKT_min'         => max(0, $ticket->min()),
            'TKT_max'         => $ticket->max() === null || $ticket->max() < 0 ? EE_INF : $ticket->max(),
            'TKT_required'    => $ticket->isRequired(),
            'TKT_start_date'  => $sale_start !== null
                ? $normalizer->toUtc($sale_start, $event->timezone())
                : new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'TKT_end_date'    => $sale_end !== null
                ? $normalizer->toUtc($sale_end, $event->timezone())
                : $last_end,
        ];
    }
}
// End of file SourceTicketMapper.php
// Location: EventEspresso\EventMigrator\domain\services\migration\mapping/SourceTicketMapper.php
