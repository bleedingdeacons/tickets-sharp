<?php

declare(strict_types=1);

namespace TicketsSharp\Orders;

use TicketsSharp\Attendees\AttendeeStore;
use TicketsSharp\Attendees\FieldSet;
use TicketsSharp\Commerce\TicketProducts;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Support\Keys;
use WC_Order;
use WC_Order_Item_Product;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Creates one attendee per ticket bought, as soon as an order exists.
 *
 * Attendees are created at checkout, before payment, so that a pending order
 * holds its seats (Event Tickets counts attendees, not sales); their status
 * follows the order from then on ({@see StatusSync}). Tickets are only e-mailed
 * once the order is paid.
 *
 * Idempotent: the order is marked once its attendees exist, and every entry
 * point — the classic checkout, the block checkout's Store API, and any status
 * change (which covers orders created in the admin) — goes through generate().
 */
class AttendeeGenerator
{
    /** @var array<int, true> Orders being generated in this request. */
    private array $inProgress = [];

    public function __construct(
        private readonly AttendeeStore $store,
        private readonly TicketProducts $products,
        private readonly Shop $shop,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_checkout_order_processed', [$this, 'onCheckout'], 20, 3);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'onStoreApi'], 20, 1);
    }

    /**
     * @param mixed $orderId
     * @param mixed $postedData
     * @param mixed $order
     */
    public function onCheckout($orderId, $postedData = null, $order = null): void
    {
        $order = $order instanceof WC_Order ? $order : $this->shop->order(is_numeric($orderId) ? (int) $orderId : 0);
        if ($order instanceof WC_Order) {
            $this->generate($order);
        }
    }

    /**
     * @param mixed $order
     */
    public function onStoreApi($order): void
    {
        if ($order instanceof WC_Order) {
            $this->generate($order);
        }
    }

    /**
     * @return list<int> The attendee IDs created; empty when the order had them already.
     */
    public function generate(WC_Order $order): array
    {
        $orderId = $order->get_id();
        if (
            $orderId <= 0
            || isset($this->inProgress[$orderId])
            || $order->get_meta(Keys::ORDER_ATTENDEES_GENERATED) === 'yes'
            || $order->get_status() === StatusMap::DRAFT
        ) {
            return [];
        }

        $this->inProgress[$orderId] = true;

        try {
            $created = [];
            $events = [];
            $status = $order->get_status();
            $purchaserName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $purchaserEmail = $order->get_billing_email();

            foreach ($order->get_items('line_item') as $item) {
                if (! $item instanceof WC_Order_Item_Product) {
                    continue;
                }

                $productId = $item->get_product_id();
                $eventId = $this->products->eventFor($productId);
                if ($eventId <= 0) {
                    continue;
                }

                $quantity = max(0, (int) $item->get_quantity());
                $fields = FieldSet::parse(get_post_meta($productId, Keys::PRODUCT_FIELDS, true));
                $answers = self::answers($item->get_meta(Keys::ITEM_ATTENDEES));
                $unitPrice = $quantity > 0 ? (float) $item->get_total() / $quantity : 0.0;

                for ($i = 0; $i < $quantity; $i++) {
                    $values = $answers[$i] ?? [];
                    $name = FieldSet::holderName($fields, $values);
                    $email = FieldSet::holderEmail($fields, $values);

                    $attendeeId = $this->store->create([
                        'event_id' => $eventId,
                        'product_id' => $productId,
                        'order_id' => $orderId,
                        'order_item_id' => (int) $item->get_id(),
                        'status' => $status,
                        'full_name' => $name !== '' ? $name : $purchaserName,
                        'email' => $email !== '' ? $email : $purchaserEmail,
                        'purchaser_name' => $purchaserName,
                        'purchaser_email' => $purchaserEmail,
                        'user_id' => (int) $order->get_customer_id(),
                        'price_paid' => number_format($unitPrice, 2, '.', ''),
                        'currency' => $order->get_currency(),
                        'optout' => false,
                        'fields' => $values,
                        'security_code' => self::securityCode(),
                    ]);

                    if ($attendeeId > 0) {
                        $created[] = $attendeeId;
                        $events[$eventId] = $eventId;
                    }
                }
            }

            $order->update_meta_data(Keys::ORDER_ATTENDEES_GENERATED, 'yes');
            $order->save();

            if ($created !== []) {
                do_action('tickets_sharp/attendees_generated', $orderId, $created);
                do_action('tickets_sharp/attendees_changed', array_values($events));
            }

            return $created;
        } finally {
            unset($this->inProgress[$orderId]);
        }
    }

    /**
     * A ticket's security code: what its QR code carries and what check-in
     * compares. Ten characters from a CSPRNG.
     */
    public static function securityCode(): string
    {
        return substr(bin2hex(random_bytes(8)), 0, 10);
    }

    /**
     * @return list<array<string, string>>
     */
    private static function answers(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $answers = [];
        foreach (array_values($raw) as $set) {
            $values = [];
            if (is_array($set)) {
                foreach ($set as $key => $value) {
                    if (is_string($key) && is_scalar($value)) {
                        $values[$key] = (string) $value;
                    }
                }
            }
            $answers[] = $values;
        }

        return $answers;
    }
}
