<?php

declare(strict_types=1);

namespace TicketsSharp\Orders;

use TicketsSharp\Attendees\FieldSet;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Support\Keys;
use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Sends an order's tickets through Event Tickets' own ticket e-mail.
 *
 * - The buyer gets one e-mail per event holding every ticket they bought.
 * - Where a ticket requires each attendee's email, its attendees are also
 *   e-mailed their tickets at the address they gave: one e-mail per event to
 *   each distinct address, holding every ticket given that address, from any
 *   line of the order. Addresses match ignoring case and surrounding spaces.
 *   This e-mail goes out even when the address is the buyer's own.
 *
 * Each e-mail is addressed here rather than left to Event Tickets'
 * send_tickets_email_for_attendees(), which would also group the buyer's
 * tickets in with an attendee's when they share an address.
 *
 * With Event Tickets' "Tickets Emails" on (its default), the e-mail is its
 * Ticket e-mail template, so it looks and is configured like every other
 * ticket the site sends; {@see \TicketsSharp\CheckIn\QrCode} adds the QR code.
 */
class Mailer
{
    /** @var array<int, bool> Product ID => whether it requires each attendee's email. */
    private array $requiresEmail = [];

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
            if ($this->statuses->isPaid($status)) {
                $eventId = is_numeric($attendee['event_id'] ?? null) ? (int) $attendee['event_id'] : 0;
                $byEvent[$eventId][] = $attendee;
            }
        }

        if ($byEvent === []) {
            return false;
        }

        $buyer = (string) $order->get_billing_email();
        $sent = false;

        foreach ($byEvent as $eventId => $attendees) {
            $args = ['provider' => Keys::PROVIDER_SLUG, 'post_id' => $eventId, 'order_id' => $order->get_id()];

            if ($buyer !== '') {
                $sent = $this->send($buyer, $attendees, $args) || $sent;
            }

            foreach ($this->byHolderAddress($attendees, $buyer === '') as [$to, $tickets]) {
                $sent = $this->send($to, $tickets, $args) || $sent;
            }
        }

        if ($sent) {
            $order->update_meta_data(Keys::ORDER_TICKETS_SENT, 'yes');
            $order->save();
            do_action('tickets_sharp/tickets_sent', $order->get_id());
        }

        return $sent;
    }

    /**
     * @param array<string, mixed> $attendee
     */
    public function requiresEmail(array $attendee): bool
    {
        $productId = is_numeric($attendee['product_id'] ?? null) ? (int) $attendee['product_id'] : 0;
        if ($productId <= 0) {
            return false;
        }

        if (! isset($this->requiresEmail[$productId])) {
            $this->requiresEmail[$productId] = false;
            foreach (FieldSet::parse(get_post_meta($productId, Keys::PRODUCT_FIELDS, true)) as $field) {
                if ($field['type'] === 'email' && $field['required']) {
                    $this->requiresEmail[$productId] = true;
                    break;
                }
            }
        }

        return $this->requiresEmail[$productId];
    }

    /**
     * The attendees who are sent their own tickets, grouped by the address
     * they gave, in the order each address first appears.
     *
     * @param list<array<string, mixed>> $attendees
     * @param bool $everyone Every attendee with an address, not only those of
     *                       tickets requiring it (the order has no buyer address,
     *                       so there is nobody else to give the tickets to).
     * @return list<array{0: string, 1: list<array<string, mixed>>}> [address, its tickets]
     */
    private function byHolderAddress(array $attendees, bool $everyone): array
    {
        $groups = [];
        foreach ($attendees as $attendee) {
            if (! $everyone && ! $this->requiresEmail($attendee)) {
                continue;
            }

            $holder = is_string($attendee['holder_email'] ?? null) ? trim($attendee['holder_email']) : '';
            if ($holder === '' || ! is_email($holder)) {
                continue;
            }

            $key = strtolower($holder);
            $groups[$key] ??= [$holder, []];
            $groups[$key][1][] = $attendee;
        }

        return array_values($groups);
    }

    /**
     * @param list<array<string, mixed>> $attendees
     * @param array<string, mixed>       $args
     */
    private function send(string $to, array $attendees, array $args): bool
    {
        return (bool) $this->provider->send_tickets_email_for_attendee($to, $attendees, $args);
    }
}
