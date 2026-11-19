<?php

/**
 * Stand-ins for the parts of WooCommerce Tickets Sharp touches.
 *
 * Loaded by tests/bootstrap.php after Patchwork and listed under scanFiles in
 * phpstan.neon.dist. The classes keep their data in a plain array so a test can
 * build a real object where that reads better than a mock; method names and
 * signatures follow WooCommerce 9.x.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps

namespace {
    if (! class_exists('WooCommerce')) {
        class WooCommerce
        {
            /** @var mixed Null until WooCommerce loads the cart (front-end requests only). */
            public $cart;

            /** @var mixed The shopper's session (WC_Session), loaded with the cart. */
            public $session;
        }
    }

    if (! class_exists('WC_Data_Exception')) {
        class WC_Data_Exception extends Exception
        {
            /** @var string */
            protected $error_code;

            /**
             * WooCommerce's signature: a string error code first, then the message.
             *
             * @param string               $code
             * @param string               $message
             * @param int                  $http_status_code
             * @param array<string, mixed> $data
             */
            public function __construct($code, $message, $http_status_code = 400, $data = [])
            {
                $this->error_code = $code;
                parent::__construct($message, $http_status_code);
            }

            public function getErrorCode(): string
            {
                return $this->error_code;
            }
        }
    }

    if (! class_exists('WC_DateTime')) {
        class WC_DateTime extends DateTime
        {
            /**
             * WooCommerce's: format in the site's timezone, like date().
             *
             * @param string $format
             */
            public function date($format): string
            {
                return $this->format($format);
            }
        }
    }

    if (! class_exists('WC_Data')) {
        class WC_Data
        {
            /** @var array<string, mixed> */
            public array $props = [];

            /** @var array<string, mixed> */
            public array $meta = [];

            public int $saves = 0;

            /** @param int|WC_Data $id */
            public function __construct($id = 0)
            {
                $this->props['id'] = is_int($id) ? $id : 0;
            }

            public function get_id(): int
            {
                return (int) ($this->props['id'] ?? 0);
            }

            /**
             * @param string $key
             * @param bool   $single
             * @param string $context
             * @return mixed
             */
            public function get_meta($key = '', $single = true, $context = 'view')
            {
                return $this->meta[$key] ?? '';
            }

            /**
             * @param string $key
             * @param mixed  $value
             * @param int|string $meta_id
             */
            public function update_meta_data($key, $value, $meta_id = 0): void
            {
                $this->meta[$key] = $value;
            }

            /**
             * @param string $key
             * @param mixed  $value
             * @param bool   $unique
             */
            public function add_meta_data($key, $value, $unique = false): void
            {
                $this->meta[$key] = $value;
            }

            /** @return int */
            public function save()
            {
                ++$this->saves;
                if ($this->get_id() === 0) {
                    $this->props['id'] = 500 + $this->saves;
                }

                return $this->get_id();
            }
        }
    }

    if (! class_exists('WC_Product')) {
        class WC_Product extends WC_Data
        {
            /**
             * @param string $context
             */
            public function get_name($context = 'view'): string
            {
                return (string) ($this->props['name'] ?? '');
            }

            /** @param string $context */
            public function get_short_description($context = 'view'): string
            {
                return (string) ($this->props['short_description'] ?? '');
            }

            /** @param string $context */
            public function get_menu_order($context = 'view'): int
            {
                return (int) ($this->props['menu_order'] ?? 0);
            }

            /** @param string $context */
            public function get_price($context = 'view'): string
            {
                return (string) ($this->props['price'] ?? '');
            }

            /** @param string $context */
            public function get_regular_price($context = 'view'): string
            {
                return (string) ($this->props['regular_price'] ?? '');
            }

            /** @param string $context */
            public function get_sku($context = 'view'): string
            {
                return (string) ($this->props['sku'] ?? '');
            }

            /** @return string */
            public function get_price_html()
            {
                return '<span class="woocommerce-Price-amount amount"><bdi>£' . number_format((float) $this->get_price(), 2) . '</bdi></span>';
            }

            /** @param string $context */
            public function is_on_sale($context = 'view'): bool
            {
                return false;
            }

            /** @param string $name */
            public function set_name($name): void
            {
                $this->props['name'] = $name;
            }

            /** @param string $description */
            public function set_short_description($description): void
            {
                $this->props['short_description'] = $description;
            }

            /** @param string $status */
            public function set_status($status): void
            {
                $this->props['status'] = $status;
            }

            /** @param string $visibility */
            public function set_catalog_visibility($visibility): void
            {
                $this->props['catalog_visibility'] = $visibility;
            }

            /** @param bool|string $virtual */
            public function set_virtual($virtual): void
            {
                $this->props['virtual'] = $virtual;
            }

            /** @param bool|string $sold_individually */
            public function set_sold_individually($sold_individually): void
            {
                $this->props['sold_individually'] = $sold_individually;
            }

            /** @param string $price */
            public function set_regular_price($price): void
            {
                $this->props['regular_price'] = $price;
            }

            /** @param string $price */
            public function set_price($price): void
            {
                $this->props['price'] = $price;
            }

            /** @param int $menu_order */
            public function set_menu_order($menu_order): void
            {
                $this->props['menu_order'] = $menu_order;
            }

            /** @param bool $manage_stock */
            public function set_manage_stock($manage_stock): void
            {
                $this->props['manage_stock'] = $manage_stock;
            }

            /** @param int|float|null $quantity */
            public function set_stock_quantity($quantity): void
            {
                $this->props['stock_quantity'] = $quantity;
            }

            /** @param string $backorders */
            public function set_backorders($backorders): void
            {
                $this->props['backorders'] = $backorders;
            }

            /** @param string $status */
            public function set_stock_status($status = 'instock'): void
            {
                $this->props['stock_status'] = $status;
            }

            /** @param string $sku */
            public function set_sku($sku): void
            {
                $this->props['sku'] = $sku;
            }

            /** @param string $context */
            public function get_manage_stock($context = 'view'): bool
            {
                return (bool) ($this->props['manage_stock'] ?? false);
            }
        }
    }

    if (! class_exists('WC_Product_Simple')) {
        class WC_Product_Simple extends WC_Product
        {
        }
    }

    if (! class_exists('WC_Order_Item')) {
        class WC_Order_Item extends WC_Data
        {
        }
    }

    if (! class_exists('WC_Order_Item_Product')) {
        class WC_Order_Item_Product extends WC_Order_Item
        {
            /** @param string $context */
            public function get_product_id($context = 'view'): int
            {
                return (int) ($this->props['product_id'] ?? 0);
            }

            /**
             * @param string $context
             * @return int|float
             */
            public function get_quantity($context = 'view')
            {
                return (int) ($this->props['quantity'] ?? 0);
            }

            /** @param string $context */
            public function get_total($context = 'view'): string
            {
                return (string) ($this->props['total'] ?? '0');
            }
        }
    }

    if (! class_exists('WC_Abstract_Order')) {
        class WC_Abstract_Order extends WC_Data
        {
            /** @var array<int, WC_Order_Item> */
            public array $items = [];

            /**
             * @param string|array<int, string> $types
             * @return array<int, WC_Order_Item>
             */
            public function get_items($types = 'line_item')
            {
                return $this->items;
            }

            /** @param string $context */
            public function get_status($context = 'view'): string
            {
                return (string) ($this->props['status'] ?? 'pending');
            }

            /** @param string $context */
            public function get_currency($context = 'view'): string
            {
                return (string) ($this->props['currency'] ?? 'GBP');
            }

            /** @param string $context */
            public function get_date_created($context = 'view'): ?WC_DateTime
            {
                $date = $this->props['date_created'] ?? null;

                return $date instanceof WC_DateTime ? $date : null;
            }
        }
    }

    if (! class_exists('WC_Order')) {
        class WC_Order extends WC_Abstract_Order
        {
            /** @var list<string> */
            public array $notes = [];

            /** @param string $context */
            public function get_billing_first_name($context = 'view'): string
            {
                return (string) ($this->props['billing_first_name'] ?? '');
            }

            /** @param string $context */
            public function get_billing_last_name($context = 'view'): string
            {
                return (string) ($this->props['billing_last_name'] ?? '');
            }

            /** @param string $context */
            public function get_billing_email($context = 'view'): string
            {
                return (string) ($this->props['billing_email'] ?? '');
            }

            /** @param string $context */
            public function get_customer_id($context = 'view'): int
            {
                return (int) ($this->props['customer_id'] ?? 0);
            }

            /**
             * @param int    $item_id
             * @param string $item_type
             */
            public function get_qty_refunded_for_item($item_id, $item_type = 'line_item'): int
            {
                $refunds = $this->props['qty_refunded'] ?? [];

                return is_array($refunds) ? (int) ($refunds[$item_id] ?? 0) : 0;
            }

            /**
             * @param string $note
             * @param int    $is_customer_note
             * @param bool   $added_by_user
             */
            public function add_order_note($note, $is_customer_note = 0, $added_by_user = false): int
            {
                $this->notes[] = $note;

                return count($this->notes);
            }

            public function get_edit_order_url(): string
            {
                return 'https://example.test/wp-admin/post.php?post=' . $this->get_id() . '&action=edit';
            }
        }
    }

    if (! class_exists('WC_Email')) {
        class WC_Email
        {
            /** @var string */
            public $id = '';
        }
    }

    if (! class_exists('WC_Cart')) {
        class WC_Cart
        {
            /** @var array<string, array<string, mixed>> */
            public $cart_contents = [];

            /** @var list<array{int, int, array<string, mixed>}> */
            public array $added = [];

            public int $sessionSaves = 0;

            /** @return array<string, array<string, mixed>> */
            public function get_cart()
            {
                return $this->cart_contents;
            }

            /**
             * @param int                  $product_id
             * @param int                  $quantity
             * @param int                  $variation_id
             * @param array<string, mixed> $variation
             * @param array<string, mixed> $cart_item_data
             * @return string|false
             */
            public function add_to_cart($product_id = 0, $quantity = 1, $variation_id = 0, $variation = [], $cart_item_data = [])
            {
                $this->added[] = [$product_id, $quantity, $cart_item_data];
                $key = md5((string) $product_id . serialize($cart_item_data));
                $this->cart_contents[$key] = array_merge(
                    $cart_item_data,
                    ['product_id' => $product_id, 'quantity' => (int) ($this->cart_contents[$key]['quantity'] ?? 0) + $quantity]
                );

                return $key;
            }

            /**
             * @param string $cart_item_key
             * @param int    $quantity
             * @param bool   $refresh_totals
             * @return bool
             */
            public function set_quantity($cart_item_key, $quantity = 1, $refresh_totals = true)
            {
                if ($quantity <= 0) {
                    return $this->remove_cart_item($cart_item_key);
                }
                $this->cart_contents[$cart_item_key]['quantity'] = (int) $quantity;

                return true;
            }

            /**
             * @param string $cart_item_key
             * @return bool
             */
            public function remove_cart_item($cart_item_key)
            {
                if (! isset($this->cart_contents[$cart_item_key])) {
                    return false;
                }
                unset($this->cart_contents[$cart_item_key]);

                return true;
            }

            public function set_session(): void
            {
                ++$this->sessionSaves;
            }
        }
    }

    if (! function_exists('WC')) {
        function WC(): WooCommerce
        {
            static $woo = null;

            return $woo ??= new WooCommerce();
        }
    }

    if (! function_exists('wc_get_product')) {
        /**
         * @param mixed $the_product
         * @return WC_Product|null|false
         */
        function wc_get_product($the_product = false)
        {
            return null;
        }
    }

    if (! function_exists('wc_get_order')) {
        /**
         * @param mixed $the_order
         * @return WC_Order|false
         */
        function wc_get_order($the_order = false)
        {
            return false;
        }
    }

    if (! function_exists('wc_get_checkout_url')) {
        function wc_get_checkout_url(): string
        {
            return 'https://example.test/checkout/';
        }
    }

    if (! function_exists('wc_get_cart_url')) {
        function wc_get_cart_url(): string
        {
            return 'https://example.test/cart/';
        }
    }

    if (! function_exists('wc_add_notice')) {
        /**
         * @param string               $message
         * @param string               $notice_type
         * @param array<string, mixed> $data
         */
        function wc_add_notice($message, $notice_type = 'success', $data = []): void
        {
        }
    }

    if (! function_exists('wc_load_cart')) {
        function wc_load_cart(): void
        {
        }
    }

    if (! function_exists('get_woocommerce_currency')) {
        function get_woocommerce_currency(): string
        {
            return 'GBP';
        }
    }

    if (! function_exists('wc_price')) {
        /**
         * @param float|string         $price
         * @param array<string, mixed> $args
         */
        function wc_price($price, $args = []): string
        {
            return '£' . number_format((float) $price, 2);
        }
    }

    if (! function_exists('wc_get_order_statuses')) {
        /** @return array<string, string> */
        function wc_get_order_statuses(): array
        {
            return [
                'wc-pending' => 'Pending payment',
                'wc-processing' => 'Processing',
                'wc-on-hold' => 'On hold',
                'wc-completed' => 'Completed',
                'wc-cancelled' => 'Cancelled',
                'wc-refunded' => 'Refunded',
                'wc-failed' => 'Failed',
                'wc-checkout-draft' => 'Draft',
            ];
        }
    }

    if (! function_exists('wc_get_order_status_name')) {
        /** @param string $status */
        function wc_get_order_status_name($status): string
        {
            $statuses = wc_get_order_statuses();
            $key = str_starts_with($status, 'wc-') ? $status : 'wc-' . $status;

            return $statuses[$key] ?? $status;
        }
    }

    if (! function_exists('wc_get_page_screen_id')) {
        /** @param string $for */
        function wc_get_page_screen_id($for): string
        {
            return 'woocommerce_page_wc-orders';
        }
    }

    if (! function_exists('wc_delete_product_transients')) {
        /** @param int $post_id */
        function wc_delete_product_transients($post_id = 0): void
        {
        }
    }

    if (! function_exists('is_checkout')) {
        function is_checkout(): bool
        {
            return false;
        }
    }
}

namespace Automattic\WooCommerce\Utilities {
    if (! class_exists(FeaturesUtil::class)) {
        class FeaturesUtil
        {
            /** @var array<string, bool> */
            public static array $declared = [];

            public static function declare_compatibility(string $feature_id, string $plugin_file, bool $positive_compatibility = true): bool
            {
                self::$declared[$feature_id] = $positive_compatibility;

                return true;
            }
        }
    }
}
