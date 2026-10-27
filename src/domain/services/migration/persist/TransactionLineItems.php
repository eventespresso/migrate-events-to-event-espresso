<?php

namespace EventEspresso\EventMigrator\domain\services\migration\persist;

use EE_Error;
use EE_Line_Item;
use EE_Ticket;
use EEH_Line_Item;
use EEM_Line_Item;
use ReflectionException;

/**
 * Class TransactionLineItems
 *
 * A transaction's line items. Adds one seat the way checkout does: seats of
 * the same ticket at the same price share one ticket line whose quantity goes
 * up. A seat paid at a different price gets its own line carrying that price,
 * with a code of its own, because core replaces a child line that has the same
 * code (EE_Line_Item::add_child_line_item()). Deletes every line item of a
 * transaction, including any the tree walk from the grand total misses.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class TransactionLineItems
{
    /**
     * @param EE_Line_Item $total_line The transaction's grand total line.
     * @param EE_Ticket    $ticket     The seat's ticket.
     * @param float|null   $price_paid The seat's price when it differs from the ticket's, or null.
     * @return EE_Line_Item The ticket line the seat was added to.
     * @throws EE_Error
     * @throws ReflectionException
     */
    public static function addSeat(EE_Line_Item $total_line, EE_Ticket $ticket, ?float $price_paid): EE_Line_Item
    {
        $unit_price = $price_paid ?? (float) $ticket->price();
        $existing   = array_values(
            array_filter(
                EEH_Line_Item::get_ticket_line_items($total_line),
                static fn(EE_Line_Item $line): bool => (int) $line->OBJ_ID() === (int) $ticket->ID()
            )
        );
        foreach ($existing as $line) {
            if (abs((float) $line->unit_price() - $unit_price) < 0.005) {
                EEH_Line_Item::increment_quantity($line, 1);
                return $line;
            }
        }
        $suffix = count($existing);
        $unique = static function (EE_Line_Item $line) use ($suffix): EE_Line_Item {
            if ($suffix > 0) {
                $line->set_code($line->generate_code() . '-' . $suffix);
            }
            return $line;
        };
        add_filter('FHEE__EEH_Line_Item__create_ticket_line_item__line_item', $unique);
        try {
            $line = EEH_Line_Item::create_ticket_line_item($total_line, $ticket, 1);
        } finally {
            remove_filter('FHEE__EEH_Line_Item__create_ticket_line_item__line_item', $unique);
        }
        if ($price_paid !== null) {
            EEH_Line_Item::delete_all_child_items($line);
            $line->set_unit_price($price_paid);
            $line->set_total($price_paid);
        }
        return $line;
    }


    /**
     * Deletes the transaction's line item tree, then any line item still carrying the transaction's id.
     *
     * @param int $transaction_id     The transaction.
     * @param int $total_line_item_id Its grand total line, when known; found by type otherwise.
     * @throws EE_Error
     * @throws ReflectionException
     */
    public static function deleteAll(int $transaction_id, int $total_line_item_id = 0): void
    {
        $total = $total_line_item_id > 0
            ? EEM_Line_Item::instance()->get_one_by_ID($total_line_item_id)
            : EEM_Line_Item::instance()->get_one([['TXN_ID' => $transaction_id, 'LIN_type' => EEM_Line_Item::type_total]]);
        if ($total instanceof EE_Line_Item) {
            EEH_Line_Item::delete_all_child_items($total);
            $total->delete_permanently();
        }
        if ($transaction_id > 0) {
            EEM_Line_Item::instance()->delete_permanently([['TXN_ID' => $transaction_id]], false);
        }
    }
}
// End of file TransactionLineItems.php
// Location: EventEspresso\EventMigrator\domain\services\migration\persist/TransactionLineItems.php
