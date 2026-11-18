<?php

declare(strict_types=1);

namespace TicketsSharp\Commerce;

use TicketsSharp\Admin\TicketFormFields;
use TicketsSharp\Attendees\FieldSet;
use TicketsSharp\Attendees\Ledger;
use TicketsSharp\Integration\EventTickets;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Support\Keys;
use Tribe__Tickets__Global_Stock;
use Tribe__Tickets__Ticket_Object;
use WC_Data_Exception;
use WC_Product;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Ticket ↔ WooCommerce product.
 *
 * A ticket is a simple, virtual product hidden from the shop catalogue and
 * search, linked to its event by `_tsharp_for_event`. It is written through
 * WooCommerce's product CRUD so its lookup tables stay right.
 *
 * Stock: WooCommerce is told (in "view" context only) that ticket products do
 * not manage stock, so it never holds, reduces or restores it. Event Tickets'
 * attendee-based inventory is the single source of truth, and
 * {@see \TicketsSharp\Stock\StockSync} mirrors it into `_stock` for the Event
 * Tickets code that reads that meta directly.
 */
class TicketProducts
{
    public function __construct(
        private readonly Shop $shop,
        private readonly EventTickets $eventTickets,
        private readonly Ledger $ledger,
    ) {
    }

    public function register(): void
    {
        add_filter('woocommerce_product_get_manage_stock', [$this, 'filterManageStock'], 10, 2);
    }

    /**
     * @param mixed $manage
     * @param mixed $product
     * @return mixed
     */
    public function filterManageStock($manage, $product)
    {
        if ($product instanceof WC_Product && $this->isTicket($product->get_id())) {
            return false;
        }

        return $manage;
    }

    public function isTicket(int $productId): bool
    {
        return $this->eventFor($productId) > 0;
    }

    public function eventFor(int $productId): int
    {
        if ($productId <= 0) {
            return 0;
        }
        $event = get_post_meta($productId, Keys::TICKET_EVENT, true);

        return is_numeric($event) ? (int) $event : 0;
    }

    /**
     * Creates or updates the product for a ticket saved in Event Tickets'
     * editor (classic metabox or block editor; both arrive here).
     *
     * @param array<string, mixed> $raw The raw request data Event Tickets passes on.
     * @return int The product ID, or 0 on failure.
     */
    public function save(int $eventId, Tribe__Tickets__Ticket_Object $ticket, array $raw): int
    {
        $existingId = is_numeric($ticket->ID) ? (int) $ticket->ID : 0;
        $isUpdate = $existingId > 0;

        $product = $isUpdate ? $this->shop->product($existingId) : $this->shop->newTicketProduct();
        if (! $product instanceof WC_Product) {
            return 0;
        }

        $price = self::price($ticket->price);

        $product->set_name(self::text($ticket->name));
        $product->set_short_description(self::text($ticket->description));
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->set_virtual(true);
        $product->set_sold_individually(false);
        $product->set_regular_price($price);
        $product->set_price($price);
        if (is_numeric($ticket->menu_order)) {
            $product->set_menu_order((int) $ticket->menu_order);
        }

        $globalStock = $this->eventTickets->globalStock($eventId);
        $taken = $isUpdate ? $this->ledger->countsForTicket($existingId)['held'] : 0;
        $form = is_array($raw['tribe-ticket'] ?? null) ? $raw['tribe-ticket'] : [];

        $plan = CapacityPlan::fromForm(
            $form,
            $globalStock->is_enabled(),
            $this->eventTickets->eventCapacity($eventId),
            $isUpdate,
            $taken
        );

        if ($plan->manageStock) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($plan->stock);
            $product->set_backorders('no');
            $product->set_stock_status($plan->stock > 0 ? 'instock' : 'outofstock');
        } else {
            $product->set_manage_stock(false);
            $product->set_stock_status('instock');
        }

        $productId = $product->save();
        if ($productId <= 0) {
            return 0;
        }

        $ticket->ID = $productId;
        update_post_meta($productId, Keys::TICKET_EVENT, $eventId);

        $this->applySku($product, $eventId, self::text($raw['ticket_sku'] ?? ''));

        update_post_meta($productId, '_type', self::text($raw['ticket_type'] ?? 'default') ?: 'default');
        update_post_meta(
            $productId,
            $this->eventTickets->showDescriptionKey(),
            ! empty($ticket->show_description) && $ticket->show_description !== 'no' ? 'yes' : 'no'
        );

        foreach (['start_date', 'start_time', 'end_date', 'end_time'] as $key) {
            $value = $ticket->{$key} ?? null;
            if (is_string($value) && $value !== '') {
                update_post_meta($productId, '_ticket_' . $key, $value);
            } else {
                delete_post_meta($productId, '_ticket_' . $key);
            }
        }

        $this->applyEventCapacity($eventId, $plan, $globalStock);

        if ($plan->manageStock) {
            update_post_meta($productId, Tribe__Tickets__Global_Stock::TICKET_STOCK_MODE, $plan->mode);
            if ($plan->mode === Tribe__Tickets__Global_Stock::CAPPED_STOCK_MODE && $plan->capacity !== null) {
                update_post_meta($productId, Tribe__Tickets__Global_Stock::TICKET_STOCK_CAP, $plan->capacity);
            } else {
                delete_post_meta($productId, Tribe__Tickets__Global_Stock::TICKET_STOCK_CAP);
            }
        } else {
            delete_post_meta($productId, Tribe__Tickets__Global_Stock::TICKET_STOCK_MODE);
            delete_post_meta($productId, Tribe__Tickets__Global_Stock::TICKET_STOCK_CAP);
        }

        if ($plan->capacity !== null) {
            $this->eventTickets->setTicketCapacity($ticket, $plan->capacity);
        }

        $this->saveAttendeeFields($productId, $raw, $isUpdate);

        return $productId;
    }

    /**
     * Stores what the ticket asks of each attendee, from the ticket form's
     * "Attendee information" box ({@see \TicketsSharp\Admin\TicketFormFields}).
     *
     * A form without the box (the block editor) leaves an existing ticket's
     * fields alone, and gives a new ticket the default: each attendee's name.
     *
     * @param array<string, mixed> $raw
     */
    public function saveAttendeeFields(int $productId, array $raw, bool $isUpdate): void
    {
        if (array_key_exists(TicketFormFields::INPUT, $raw)) {
            $text = is_string($raw[TicketFormFields::INPUT]) ? wp_unslash($raw[TicketFormFields::INPUT]) : '';
        } elseif (! $isUpdate) {
            $text = TicketFormFields::DEFAULT_TEXT;
        } else {
            return;
        }

        $fields = FieldSet::fromText($text);
        if ($fields === []) {
            delete_post_meta($productId, Keys::PRODUCT_FIELDS);
            return;
        }

        update_post_meta($productId, Keys::PRODUCT_FIELDS, $fields);
    }

    /**
     * Removes a ticket. The product goes to the trash, never deleted outright:
     * orders that sold it still point at it.
     */
    public function trash(int $productId): bool
    {
        if (! $this->isTicket($productId)) {
            return false;
        }

        $eventId = $this->eventFor($productId);
        $result = wp_trash_post($productId);

        if ($eventId > 0) {
            // Remember where it came from, as Event Tickets does for its own tickets.
            update_post_meta($productId, '_tsharp_deleted_from_event', $eventId);
        }

        return $result !== false && $result !== null;
    }

    private function applyEventCapacity(int $eventId, CapacityPlan $plan, Tribe__Tickets__Global_Stock $globalStock): void
    {
        if (! $plan->setEventCapacity || $plan->eventCapacity === null) {
            return;
        }

        $globalStock->enable();
        $globalStock->set_stock_level($plan->eventCapacity, true);
        update_post_meta($eventId, $this->eventTickets->capacityKey(), $plan->eventCapacity);
        update_post_meta($eventId, Tribe__Tickets__Global_Stock::GLOBAL_STOCK_ENABLED, 1);
    }

    private function applySku(WC_Product $product, int $eventId, string $requested): void
    {
        $current = (string) $product->get_sku('edit');
        $wanted = $requested !== '' ? $requested : ($current !== '' ? $current : sprintf('TS-%d-%d', $eventId, $product->get_id()));

        if ($wanted === $current) {
            return;
        }

        try {
            $product->set_sku($wanted);
            $product->save();
        } catch (WC_Data_Exception $e) {
            // WooCommerce refuses a SKU another product already has. The ticket
            // still works without one; keep whatever it had.
            unset($e);
        }
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function price(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '0';
        }

        $price = (string) $value;

        return (float) $price < 0 ? '0' : $price;
    }
}
