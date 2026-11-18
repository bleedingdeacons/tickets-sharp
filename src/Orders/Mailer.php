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
 * Sends an order's tickets through Event Tickets' own ticket e-mail.
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

        $byEvent = [];
        foreach ($this->provider->get_attendees_by_order_id($order->get_id()) as $attendee) {
            $status = is_string($attendee['order_status'] ?? null) ? $attendee['order_status'] : '';
            if (! $this->statuses->isPaid($status)) {
                continue;
            }
            $eventId = is_numeric($attendee['event_id'] ?? null) ? (int) $attendee['event_id'] : 0;
            $byEvent[$eventId][] = $attendee;
        }

        if ($byEvent === []) {
            return false;
        }

        $sent = false;
        foreach ($byEvent as $eventId => $attendees) {
            $sent = $this->provider->send_tickets_email_for_attendees(
                $attendees,
                [
                    'post_id' => $eventId,
                    'order_id' => $order->get_id(),
                    'send_purchaser_all' => true,
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
