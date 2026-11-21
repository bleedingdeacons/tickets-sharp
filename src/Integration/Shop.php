<?php

declare(strict_types=1);

namespace TicketsSharp\Integration;

use WC_Cart;
use WC_Order;
use WC_Product;
use WC_Product_Simple;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The one place Tickets Sharp reaches into WooCommerce's globals, so the
 * services can be tested with this class mocked.
 */
class Shop
{
    public function product(int $productId): ?WC_Product
    {
        if ($productId <= 0) {
            return null;
        }
        $product = wc_get_product($productId);

        return $product instanceof WC_Product ? $product : null;
    }

    public function newTicketProduct(): WC_Product_Simple
    {
        return new WC_Product_Simple();
    }

    public function order(int $orderId): ?WC_Order
    {
        if ($orderId <= 0) {
            return null;
        }
        $order = wc_get_order($orderId);

        return $order instanceof WC_Order ? $order : null;
    }

    public function cart(): ?WC_Cart
    {
        if (! function_exists('WC')) {
            return null;
        }
        $cart = WC()->cart;
        if (! $cart instanceof WC_Cart && function_exists('wc_load_cart')) {
            // The cart is only loaded on front-end requests; load it for ours.
            wc_load_cart();
            $cart = WC()->cart;
        }

        return $cart instanceof WC_Cart ? $cart : null;
    }

    /** A value from the shopper's WooCommerce session, or null. */
    public function sessionGet(string $key): mixed
    {
        $session = function_exists('WC') ? WC()->session : null;

        return is_object($session) && method_exists($session, 'get') ? $session->get($key) : null;
    }

    /** Stores a value in the shopper's WooCommerce session; null removes it. */
    public function sessionSet(string $key, mixed $value): void
    {
        $session = function_exists('WC') ? WC()->session : null;
        if (is_object($session) && method_exists($session, 'set')) {
            $session->set($key, $value);
        }
    }

    public function checkoutUrl(): string
    {
        return (string) wc_get_checkout_url();
    }

    public function cartUrl(): string
    {
        return (string) wc_get_cart_url();
    }

    public function notice(string $message, string $type = 'error'): void
    {
        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, $type);
        }
    }

    public function currency(): string
    {
        return function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '';
    }

    /** WooCommerce's currency symbol, HTML-encoded as WooCommerce returns it (e.g. `&pound;`). */
    public function currencySymbol(): string
    {
        return function_exists('get_woocommerce_currency_symbol') ? (string) get_woocommerce_currency_symbol() : '';
    }

    /** WooCommerce's symbol position: `left`, `right`, `left_space` or `right_space`. */
    public function currencyPosition(): string
    {
        return (string) get_option('woocommerce_currency_pos', 'left');
    }

    public function decimalSeparator(): string
    {
        return function_exists('wc_get_price_decimal_separator') ? (string) wc_get_price_decimal_separator() : '.';
    }

    public function thousandSeparator(): string
    {
        return function_exists('wc_get_price_thousand_separator') ? (string) wc_get_price_thousand_separator() : ',';
    }

    public function decimals(): int
    {
        return function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
    }

    public function formatPrice(float $amount): string
    {
        return function_exists('wc_price') ? wp_strip_all_tags((string) wc_price($amount)) : number_format($amount, 2);
    }
}
