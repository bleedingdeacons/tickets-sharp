<?php

declare(strict_types=1);

namespace TicketsSharp\Stock;

use TicketsSharp\Attendees\Ledger;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Integration\EventTickets;
use TicketsSharp\Support\Keys;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Mirrors attendee-based availability into the meta Event Tickets reads
 * directly.
 *
 * Event Tickets' admin totals (`Tickets_Handler::get_ticket_totals()`) and
 * shared-capacity bookkeeping read `_stock`, `_stock_status` and the event's
 * global stock level straight from post meta. After any change to an event's
 * attendees, this recomputes them from the attendees themselves — so a missed
 * hook can be put right by the next change, rather than drifting for ever as a
 * running total would.
 *
 * It also runs after any WooCommerce save of a ticket product. WooCommerce's
 * `WC_Product::validate_props()` asks `get_manage_stock()` in "view" context,
 * which {@see \TicketsSharp\Commerce\TicketProducts} answers "no" for
 * tickets, so every save blanks `_stock` — and Event Tickets then reads the
 * ticket as having none left. Saves from the product editor, quick edit, the
 * REST API or another plugin would otherwise leave it at zero.
 */
class StockSync
{
    public function __construct(
        private readonly Provider $provider,
        private readonly Ledger $ledger,
        private readonly SharedCapacity $shared,
        private readonly EventTickets $eventTickets,
    ) {
    }

    public function register(): void
    {
        add_action('tickets_sharp/attendees_changed', [$this, 'syncEvents']);
        add_action('tickets_sharp/ticket_saved', [$this, 'syncEvent']);
        add_action('woocommerce_update_product', [$this, 'syncProduct']);
    }

    /**
     * @param mixed $productId
     */
    public function syncProduct($productId): void
    {
        $productId = is_numeric($productId) ? (int) $productId : 0;
        if ($productId <= 0) {
            return;
        }

        $eventId = get_post_meta($productId, Keys::TICKET_EVENT, true);
        if (is_numeric($eventId) && (int) $eventId > 0) {
            $this->syncEvent((int) $eventId);
        }
    }

    /**
     * @param mixed $eventIds
     */
    public function syncEvents($eventIds): void
    {
        foreach (array_unique(array_map('intval', is_array($eventIds) ? $eventIds : [$eventIds])) as $eventId) {
            $this->syncEvent($eventId);
        }
    }

    /**
     * @param mixed $eventId
     */
    public function syncEvent($eventId): void
    {
        $eventId = is_numeric($eventId) ? (int) $eventId : 0;
        if ($eventId <= 0) {
            return;
        }

        $ids = $this->provider->get_tickets_ids($eventId);
        $ticketIds = is_array($ids) ? $ids : [];

        $this->shared->sync($eventId, $ticketIds);
        $sharedLeft = $this->shared->remaining($eventId, $ticketIds);

        foreach ($ticketIds as $ticketId) {
            $this->syncTicket($ticketId, $sharedLeft);
        }

        $this->eventTickets->clearTicketCaches($eventId);
    }

    public function syncTicket(int $ticketId, int $sharedLeft = -1): void
    {
        if (get_post_meta($ticketId, '_manage_stock', true) !== 'yes') {
            return;
        }

        $capacity = get_post_meta($ticketId, $this->eventTickets->capacityKey(), true);
        $capacity = is_numeric($capacity) ? (int) $capacity : -1;
        if ($capacity < 0) {
            return;
        }

        $left = max(0, $capacity - count($this->ledger->seatHolders($ticketId)));
        if ($sharedLeft >= 0 && $this->shared->isShared($ticketId)) {
            $left = min($left, $sharedLeft);
        }

        update_post_meta($ticketId, '_stock', $left);
        update_post_meta($ticketId, '_stock_status', $left > 0 ? 'instock' : 'outofstock');
        wp_cache_delete((string) $ticketId, 'tec_tickets');

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($ticketId);
        }
    }
}
