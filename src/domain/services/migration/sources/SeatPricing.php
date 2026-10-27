<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources;

use EventEspresso\EventMigrator\application\services\migration\records\SourceSeatRecord;

/**
 * Class SeatPricing
 *
 * Seats priced at what the booking really cost. Several sources keep a
 * ticket's list price on each seat but record discounts and extra charges on
 * the booking as a whole; the engine builds the transaction from the seats, so
 * seats that do not add up to the booking total make the transaction total
 * disagree with the payments. Scaling the seats to the total keeps the two
 * the same.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class SeatPricing
{
    /**
     * The seats with every price scaled by the same factor so they add up to the total; unchanged when the
     * seats have no price to scale or already add up to the total.
     *
     * @param SourceSeatRecord[] $seats The booking's seats at their list prices.
     * @param float              $total The booking total.
     * @return SourceSeatRecord[]
     */
    public static function scaleToTotal(array $seats, float $total): array
    {
        $listed = 0.0;
        foreach ($seats as $seat) {
            $listed += $seat->pricePaid() * $seat->quantity();
        }
        if ($listed <= 0.0 || abs($listed - $total) < 0.005) {
            return $seats;
        }
        return array_map(
            static fn(SourceSeatRecord $seat): SourceSeatRecord => new SourceSeatRecord(
                $seat->ticketSourceId(),
                round($seat->pricePaid() * $total / $listed, 4),
                $seat->quantity(),
                $seat->attendeeName()
            ),
            $seats
        );
    }
}
// End of file SeatPricing.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources/SeatPricing.php
