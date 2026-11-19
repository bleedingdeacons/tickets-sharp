<?php

declare(strict_types=1);

namespace TicketsSharp\Orders;

use TicketsSharp\Commerce\Provider;
use TicketsSharp\Support\Keys;
use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Sends an order's tickets through Event Tickets' own ticket e-mail: one
 * e-mail per ticket, to the address its attendee gave.
 *
 * Event Tickets groups the tickets it is handed by address and sends one
 * e-mail per address, so five attendees who all give the buyer's address
 * would get one e-mail holding five tickets. Each ticket is handed over on
 * its own instead, so every ticket arrives as its own e-mail, ready to be
 * forwarded to whoever holds it. The buyer has all of them together in the
 * WooCommerce order e-mail ({@see \TicketsSharp\Emails\OrderEmailTickets}),
 * so Event Tickets' extra "everything to the buyer" e-mail is not asked for.
 *
 * With Event Tickets' "Tickets Emails" on (its default), the e-mail is its
 * Ticket e-mail template, so it looks and is configured like every other
 * ticket the site sends; {@see \TicketsSharp\CheckIn\QrCode} adds the QR code.
 */
class Mailer
{
    public function __construct(
        private readonly Provider $provider,
        private readonly StatusMap $statuses,
    ) {
    }

    /**
     * @param bool $force Send again even if this order's tickets went out already.
     * @return bool Whether anything was sent.
     */
    public function sendForOrder(WC_Order $order, bool $force = false): bool
    {
        if (! $force && $order->get_meta(Keys::ORDER_TICKETS_SENT) === 'yes') {
            return false;
        }

        $sent = false;
        foreach ($this->provider->get_attendees_by_order_id($order->get_id()) as $attendee) {
            $status = is_string($attendee['order_status'] ?? null) ? $attendee['order_status'] : '';
            if (! $this->statuses->isPaid($status)) {
                continue;
            }

            $sent = $this->provider->send_tickets_email_for_attendees(
                [$attendee],
                [
                    'post_id' => is_numeric($attendee['event_id'] ?? null) ? (int) $attendee['event_id'] : 0,
                    'order_id' => $order->get_id(),
                    'send_purchaser_all' => false,
                ]
            ) || $sent;
        }

        if ($sent) {
            $order->update_meta_data(Keys::ORDER_TICKETS_SENT, 'yes');
            $order->save();
            do_action('tickets_sharp/tickets_sent', $order->get_id());
        }

        return $sent;
    }
}
