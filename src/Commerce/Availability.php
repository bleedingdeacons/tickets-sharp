<?php

declare(strict_types=1);

namespace TicketsSharp\Commerce;

use TicketsSharp\Integration\Shop;
use TicketsSharp\Stock\SharedCapacity;
use Tribe__Tickets__Ticket_Object;
use WC_Cart;
use WC_Product;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Keeps WooCommerce from selling more tickets than Event Tickets says exist.
 *
 * WooCommerce does not manage stock for ticket products (see
 * {@see TicketProducts}), so its own stock checks never fire for them. These
 * hooks put Event Tickets' availability — per ticket, and across an event's
 * shared capacity — in their place, at every point WooCommerce asks: adding to
 * the cart, changing a quantity, and checking the cart (which both the classic
 * and the block checkout do before taking payment).
 */
class Availability
{
    public function __construct(
        private readonly Provider $provider,
        private readonly TicketProducts $products,
        private readonly SharedCapacity $shared,
        private readonly Shop $shop,
    ) {
    }

    public function register(): void
    {
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validateAddToCart'], 20, 3);
        add_filter('woocommerce_update_cart_validation', [$this, 'validateCartUpdate'], 20, 4);
        add_action('woocommerce_check_cart_items', [$this, 'checkCartItems']);
        add_action('woocommerce_store_api_cart_errors', [$this, 'storeApiErrors'], 10, 2);
        add_filter('woocommerce_is_purchasable', [$this, 'isPurchasable'], 20, 2);
        add_filter('woocommerce_product_is_in_stock', [$this, 'isInStock'], 20, 2);
    }

    /**
     * @param mixed $passed
     * @param mixed $productId
     * @param mixed $quantity
     * @return mixed
     */
    public function validateAddToCart($passed, $productId, $quantity)
    {
        $productId = is_numeric($productId) ? (int) $productId : 0;
        if (! $passed || ! $this->products->isTicket($productId)) {
            return $passed;
        }

        $requested = $this->cartQuantities();
        $requested[$productId] = ($requested[$productId] ?? 0) + (is_numeric($quantity) ? (int) $quantity : 0);

        return $this->reject($this->problemsFor($requested, [$productId]));
    }

    /**
     * @param mixed $passed
     * @param mixed $cartItemKey
     * @param mixed $values
     * @param mixed $quantity
     * @return mixed
     */
    public function validateCartUpdate($passed, $cartItemKey, $values, $quantity)
    {
        $productId = is_array($values) && is_numeric($values['product_id'] ?? null) ? (int) $values['product_id'] : 0;
        if (! $passed || ! $this->products->isTicket($productId)) {
            return $passed;
        }

        $requested = $this->cartQuantities(is_string($cartItemKey) ? $cartItemKey : null);
        $requested[$productId] = ($requested[$productId] ?? 0) + (is_numeric($quantity) ? (int) $quantity : 0);

        return $this->reject($this->problemsFor($requested, [$productId]));
    }

    public function checkCartItems(): void
    {
        foreach ($this->problemsFor($this->cartQuantities()) as $problem) {
            $this->shop->notice($this->message($problem));
        }
    }

    /**
     * The block checkout's Store API collects cart errors here.
     *
     * @param mixed $errors
     * @param mixed $cart
     */
    public function storeApiErrors($errors, $cart = null): void
    {
        if (! $errors instanceof WP_Error) {
            return;
        }

        foreach ($this->problemsFor($this->cartQuantities()) as $problem) {
            $errors->add('tsharp_ticket_unavailable', $this->message($problem));
        }
    }

    /**
     * Outside its sale window a ticket cannot be bought, whichever way it is
     * reached (the product is hidden, but a direct add-to-cart link still works).
     *
     * @param mixed $purchasable
     * @param mixed $product
     * @return mixed
     */
    public function isPurchasable($purchasable, $product)
    {
        if (! $purchasable || ! $product instanceof WC_Product || ! $this->products->isTicket($product->get_id())) {
            return $purchasable;
        }

        $ticket = $this->ticket($product->get_id());

        return $ticket instanceof Tribe__Tickets__Ticket_Object && $ticket->date_in_range();
    }

    /**
     * @param mixed $inStock
     * @param mixed $product
     * @return mixed
     */
    public function isInStock($inStock, $product)
    {
        if (! $product instanceof WC_Product || ! $this->products->isTicket($product->get_id())) {
            return $inStock;
        }

        $ticket = $this->ticket($product->get_id());

        return $ticket instanceof Tribe__Tickets__Ticket_Object && (int) $ticket->available() !== 0;
    }

    /**
     * Ticket quantities in the current cart.
     *
     * @return array<int, int>
     */
    public function cartQuantities(?string $exceptKey = null): array
    {
        $cart = $this->shop->cart();
        if (! $cart instanceof WC_Cart) {
            return [];
        }

        $quantities = [];
        foreach ($cart->get_cart() as $key => $item) {
            if ($key === $exceptKey || ! is_array($item)) {
                continue;
            }
            $productId = is_numeric($item['product_id'] ?? null) ? (int) $item['product_id'] : 0;
            if (! $this->products->isTicket($productId)) {
                continue;
            }
            $quantities[$productId] = ($quantities[$productId] ?? 0) + (is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 0);
        }

        return $quantities;
    }

    /**
     * @param array<int, int> $requested
     * @param list<int>|null  $only Report only problems involving these products.
     * @return list<array{product: int, event: int, allowed: int, reason: string, name: string}>
     */
    public function problemsFor(array $requested, ?array $only = null): array
    {
        $tickets = [];
        $events = [];

        foreach (array_keys($requested) as $productId) {
            $ticket = $this->ticket($productId);
            $eventId = $this->products->eventFor($productId);
            if (! $ticket instanceof Tribe__Tickets__Ticket_Object) {
                $tickets[$productId] = ['available' => 0, 'shared' => false, 'event' => $eventId, 'name' => (string) $productId];
                continue;
            }
            $tickets[$productId] = [
                'available' => (int) $ticket->available(),
                'shared' => $this->shared->isShared($productId),
                'event' => $eventId,
                'name' => (string) $ticket->name,
            ];
            $events[$eventId] = true;
        }

        $remaining = [];
        foreach (array_keys($events) as $eventId) {
            $ids = $this->provider->get_tickets_ids($eventId);
            $remaining[$eventId] = $this->shared->remaining($eventId, is_array($ids) ? $ids : []);
        }

        $problems = CartCapacity::problems($requested, $tickets, $remaining);

        if ($only !== null) {
            $problems = array_values(array_filter($problems, static fn (array $p): bool => in_array($p['product'], $only, true)));
        }

        return $problems;
    }

    /**
     * @param array{product: int, event: int, allowed: int, reason: string, name: string} $problem
     */
    public function message(array $problem): string
    {
        if ($problem['allowed'] === 0) {
            /* translators: %s: ticket name. */
            return sprintf(__('Sorry, "%s" is sold out.', 'tickets-sharp'), $problem['name']);
        }

        if ($problem['reason'] === 'shared') {
            return sprintf(
                /* translators: 1: number of tickets, 2: ticket name. */
                __('Sorry, only %1$d tickets are left for this event, including "%2$s". Please reduce the number of tickets in your basket.', 'tickets-sharp'),
                $problem['allowed'],
                $problem['name']
            );
        }

        return sprintf(
            /* translators: 1: number of tickets, 2: ticket name. */
            __('Sorry, only %1$d of "%2$s" are left. Please reduce the quantity in your basket.', 'tickets-sharp'),
            $problem['allowed'],
            $problem['name']
        );
    }

    /**
     * @param list<array{product: int, event: int, allowed: int, reason: string, name: string}> $problems
     */
    private function reject(array $problems): bool
    {
        foreach ($problems as $problem) {
            $this->shop->notice($this->message($problem));
        }

        return $problems === [];
    }

    private function ticket(int $productId): ?Tribe__Tickets__Ticket_Object
    {
        $ticket = $this->provider->get_ticket(0, $productId);

        return $ticket instanceof Tribe__Tickets__Ticket_Object ? $ticket : null;
    }
}
