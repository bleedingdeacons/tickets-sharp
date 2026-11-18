<?php

declare(strict_types=1);

namespace TicketsSharp\Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Every slug, post type and meta key Tickets Sharp owns, in one place.
 *
 * Event Tickets hard-codes a few names for a commercial WooCommerce add-on
 * (the `woo` provider slug, `_tribe_wooticket_*` meta keys). None of those are
 * reused here, so the two can never read each other's data.
 */
final class Keys
{
    /** Status-manager slug (Event Tickets' "provider slug"). */
    public const PROVIDER_SLUG = 'tsharp';

    /** ORM repository slug; also the tickets-form fields template name. */
    public const ORM_PROVIDER = 'tickets-sharp';

    public const ATTENDEE_POST_TYPE = 'tsharp_attendee';

    /** Ticket product → event. */
    public const TICKET_EVENT = '_tsharp_for_event';

    // Attendee meta.
    public const ATTENDEE_EVENT = '_tsharp_event';
    public const ATTENDEE_PRODUCT = '_tsharp_product';
    public const ATTENDEE_ORDER = '_tsharp_order';
    public const ATTENDEE_ORDER_ITEM = '_tsharp_order_item';
    public const ATTENDEE_STATUS = '_tsharp_status';
    public const ATTENDEE_CHECKIN = '_tsharp_checkedin';
    public const ATTENDEE_OPTOUT = '_tsharp_attendee_optout';
    public const ATTENDEE_FIELDS = '_tsharp_attendee_meta';
    public const ATTENDEE_PURCHASER_NAME = '_tsharp_purchaser_name';
    public const ATTENDEE_PURCHASER_EMAIL = '_tsharp_purchaser_email';
    public const ATTENDEE_TICKET_SENT = '_tsharp_ticket_sent';
    public const ATTENDEE_ACTIVITY_LOG = '_tsharp_activity_log';
    public const ATTENDEE_PRICE_PAID = '_tsharp_price_paid';
    public const ATTENDEE_CURRENCY = '_tsharp_price_currency';

    // Shared with Event Tickets' generic attendee keys, which its own
    // repositories and e-mail templates already read.
    public const ATTENDEE_FULL_NAME = '_tribe_tickets_full_name';
    public const ATTENDEE_EMAIL = '_tribe_tickets_email';
    public const ATTENDEE_SECURITY_CODE = '_tribe_tickets_security_code';
    public const ATTENDEE_USER_ID = '_tribe_tickets_attendee_user_id';

    // Order meta.
    public const ORDER_ATTENDEES_GENERATED = '_tsharp_attendees_generated';
    public const ORDER_TICKETS_SENT = '_tsharp_tickets_sent';

    // Order item meta.
    public const ITEM_EVENT = '_tsharp_event_id';
    public const ITEM_ATTENDEES = '_tsharp_attendees';

    // Cart item data.
    public const CART_EVENT = 'tsharp_event_id';
    public const CART_ATTENDEES = 'tsharp_attendees';

    // Ticket product meta.
    public const PRODUCT_FIELDS = '_tsharp_attendee_fields';

    public const OPTION = 'tickets_sharp_settings';

    private function __construct()
    {
    }
}
