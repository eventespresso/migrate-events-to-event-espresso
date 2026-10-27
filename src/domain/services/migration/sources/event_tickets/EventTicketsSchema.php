<?php

namespace EventEspresso\EventMigrator\domain\services\migration\sources\event_tickets;

/**
 * Class EventTicketsSchema
 *
 * The names Event Tickets (StellarWP) stores its Tickets Commerce data under,
 * from version 5.30.1 (research profile §2): tickets, orders and attendees
 * are posts of their own types, linked by meta and `post_parent`. RSVPs of
 * the current ("v2") RSVP version are Tickets Commerce records with the
 * `free` gateway. The old RSVP v1 post types are not read.
 *
 * @package     Event Espresso
 * @author      Event Espresso
 * @since       1.0.0.p
 */
final class EventTicketsSchema
{
    public const PLUGIN_FILE = 'event-tickets/event-tickets.php';

    public const POST_TYPE_TICKET = 'tec_tc_ticket';

    public const POST_TYPE_ORDER = 'tec_tc_order';

    public const POST_TYPE_ATTENDEE = 'tec_tc_attendee';

    /**
     * The only host post type whose tickets are migrated (plan decision).
     */
    public const HOST_POST_TYPE = 'tribe_events';

    public const ORDER_STATUS_PREFIX = 'tec-tc-';

    /**
     * Ticket post meta.
     */
    public const TICKET_EVENT = '_tec_tickets_commerce_event';

    public const TICKET_PRICE = '_price';

    public const TICKET_SALE_CHECKED = '_sale_price_checked';

    public const TICKET_SALE_PRICE = '_sale_price';

    public const TICKET_CAPACITY = '_tribe_ticket_capacity';

    public const TICKET_STOCK_MODE = '_global_stock_mode';

    public const TICKET_STOCK_CAP = '_global_stock_cap';

    public const TICKET_START = '_ticket_start_date';

    public const TICKET_END = '_ticket_end_date';

    public const TICKET_TYPE = '_type';

    /**
     * Event post meta (shared capacity).
     */
    public const EVENT_USE_GLOBAL_STOCK = '_tribe_ticket_use_global_stock';

    public const EVENT_GLOBAL_STOCK_LEVEL = '_tribe_ticket_global_stock_level';

    /**
     * Attendee post meta.
     */
    public const ATTENDEE_EVENT = '_tec_tickets_commerce_event';

    public const ATTENDEE_TICKET = '_tec_tickets_commerce_ticket';

    public const ATTENDEE_PRICE_PAID = '_tec_tickets_commerce_price_paid';

    public const ATTENDEE_RSVP_STATUS = '_tec_tickets_commerce_rsvp_status';

    public const ATTENDEE_OPTOUT = '_tec_tickets_commerce_optout';

    public const ATTENDEE_CHECKED_IN = '_tec_tickets_commerce_checked_in';

    public const ATTENDEE_CHECKED_IN_DETAILS = '_tec_tickets_commerce_checked_in_details';

    public const ATTENDEE_NAME = '_tec_tickets_commerce_full_name';

    /**
     * Order post meta.
     */
    public const ORDER_GATEWAY = '_tec_tc_order_gateway';

    public const ORDER_GATEWAY_ORDER_ID = '_tec_tc_order_gateway_order_id';

    public const ORDER_TOTAL = '_tec_tc_order_total_value';

    public const ORDER_CURRENCY = '_tec_tc_order_currency';

    public const ORDER_HASH = '_tec_tc_order_hash';

    public const ORDER_USER_ID = '_tec_tc_order_purchaser_user_id';

    public const ORDER_EMAIL = '_tec_tc_order_purchaser_email';

    public const ORDER_FIRST_NAME = '_tec_tc_order_purchaser_first_name';

    public const ORDER_LAST_NAME = '_tec_tc_order_purchaser_last_name';

    public const ORDER_FULL_NAME = '_tec_tc_order_purchaser_full_name';

    public const ORDER_STATUS_LOG = '_tec_tc_order_status_log_';

    /**
     * The order's cart items, serialized; applied coupons are items of type "coupon" (code in "slug", the discount
     * in "sub_total"). Coupon definitions are in the tec_order_modifiers table.
     */
    public const ORDER_ITEMS = '_tec_tc_order_items';

    /**
     * @return string[]
     */
    public static function ticketMetaKeys(): array
    {
        return [
            self::TICKET_PRICE,
            self::TICKET_SALE_CHECKED,
            self::TICKET_SALE_PRICE,
            self::TICKET_CAPACITY,
            self::TICKET_STOCK_MODE,
            self::TICKET_STOCK_CAP,
            self::TICKET_START,
            self::TICKET_END,
        ];
    }


    /**
     * @return string[]
     */
    public static function attendeeMetaKeys(): array
    {
        return [
            self::ATTENDEE_EVENT,
            self::ATTENDEE_TICKET,
            self::ATTENDEE_PRICE_PAID,
            self::ATTENDEE_RSVP_STATUS,
            self::ATTENDEE_OPTOUT,
            self::ATTENDEE_CHECKED_IN,
            self::ATTENDEE_CHECKED_IN_DETAILS,
            self::ATTENDEE_NAME,
        ];
    }


    /**
     * @return string[]
     */
    public static function orderMetaKeys(): array
    {
        return [
            self::ORDER_GATEWAY,
            self::ORDER_GATEWAY_ORDER_ID,
            self::ORDER_TOTAL,
            self::ORDER_CURRENCY,
            self::ORDER_HASH,
            self::ORDER_USER_ID,
            self::ORDER_EMAIL,
            self::ORDER_FIRST_NAME,
            self::ORDER_LAST_NAME,
            self::ORDER_FULL_NAME,
            self::ORDER_ITEMS,
            self::ORDER_STATUS_LOG . 'completed',
            self::ORDER_STATUS_LOG . 'refunded',
            self::ORDER_STATUS_LOG . 'reversed',
        ];
    }
}
// End of file EventTicketsSchema.php
// Location: EventEspresso\EventMigrator\domain\services\migration\sources\event_tickets/EventTicketsSchema.php
