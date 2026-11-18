<?php

declare(strict_types=1);

namespace TicketsSharp\Orders;

use TicketsSharp\Attendees\AttendeeStore;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Support\Keys;
use TicketsSharp\Support\Settings;
use WC_Order;
use WC_Order_Item_Product;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Keeps attendees in step with their WooCommerce order.
 *
 * - Every status change is copied to the order's attendees, so cancelled,
 *   failed and refunded orders stop holding seats and stop passing check-in.
 * - Reaching a paid status e-mails the tickets, once.
 * - A partial refund marks that many attendees of the refunded line
 *   `refunded` ({@see RefundAllocator}), and they keep that status whatever the
 *   order does next, unless the whole order is voided.
 */
class StatusSync
{
    public function __construct(
        private readonly AttendeeStore $store,
        private readonly AttendeeGenerator $generator,
        private readonly StatusMap $statuses,
        private readonly Mailer $mailer,
        private readonly Shop $shop,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_order_status_changed', [$this, 'onStatusChanged'], 20, 4);
        add_action('woocommerce_order_refunded', [$this, 'onRefunded'], 20, 2);
    }

    /**
     * @param mixed $orderId
     * @param mixed $from
     * @param mixed $to
     * @param mixed $order
     */
    public function onStatusChanged($orderId, $from, $to, $order = null): void
    {
        $order = $order instanceof WC_Order ? $order : $this->shop->order(is_numeric($orderId) ? (int) $orderId : 0);
        if (! $order instanceof WC_Order || ! is_string($to)) {
            return;
        }

        // An order created in the admin never passed through checkout.
        $this->generator->generate($order);
        $this->apply($order, Settings::stripPrefix($to));
    }

    public function apply(WC_Order $order, string $status): void
    {
        if ($status === StatusMap::DRAFT) {
            return;
        }

        $events = [];
        $orderVoid = $this->statuses->isVoid($status);

        foreach ($this->store->byOrder($order->get_id()) as $attendee) {
            $current = $this->store->status($attendee->ID);

            // A partially refunded seat stays refunded unless the order as a whole is void.
            if ($current === StatusMap::REFUNDED && ! $orderVoid) {
                continue;
            }
            if ($current === $status) {
                continue;
            }

            $this->store->setStatus($attendee->ID, $status);
            $eventId = $this->store->metaInt($attendee->ID, Keys::ATTENDEE_EVENT);
            $events[$eventId] = $eventId;
        }

        if ($events !== []) {
            do_action('tickets_sharp/attendees_changed', array_values($events));
        }

        if ($this->statuses->isPaid($status)) {
            $this->mailer->sendForOrder($order);
        }
    }

    /**
     * @param mixed $orderId
     * @param mixed $refundId
     */
    public function onRefunded($orderId, $refundId = null): void
    {
        $order = $this->shop->order(is_numeric($orderId) ? (int) $orderId : 0);
        if (! $order instanceof WC_Order) {
            return;
        }

        $events = [];

        foreach ($order->get_items('line_item') as $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }

            $itemId = (int) $item->get_id();
            $refunded = abs((int) $order->get_qty_refunded_for_item($itemId));
            if ($refunded === 0) {
                continue;
            }

            $rows = [];
            foreach ($this->store->byOrderItem($itemId) as $attendee) {
                $rows[] = [
                    'id' => $attendee->ID,
                    'checkedIn' => $this->store->isCheckedIn($attendee->ID),
                    'refunded' => $this->store->status($attendee->ID) === StatusMap::REFUNDED,
                ];
            }

            foreach (RefundAllocator::pick($rows, $refunded) as $attendeeId) {
                $this->store->setStatus($attendeeId, StatusMap::REFUNDED);
                $eventId = $this->store->metaInt($attendeeId, Keys::ATTENDEE_EVENT);
                $events[$eventId] = $eventId;
                do_action('tickets_sharp/attendee_refunded', $attendeeId, $order->get_id());
            }
        }

        if ($events !== []) {
            do_action('tickets_sharp/attendees_changed', array_values($events));
        }
    }
}
