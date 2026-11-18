<?php

declare(strict_types=1);

namespace TicketsSharp\Admin;

use TicketsSharp\Commerce\Provider;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Orders\Mailer;
use WC_Order;
use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A "Tickets" box on the WooCommerce order screen: the order's attendees, their
 * status and check-in, and a button to send the tickets again.
 *
 * Registered for both order screens — the legacy `shop_order` post screen and
 * the HPOS one — and reads the order through WooCommerce, never as a post.
 */
class OrderMetaBox
{
    public const ACTION = 'tickets_sharp_resend';

    public function __construct(
        private readonly Provider $provider,
        private readonly Mailer $mailer,
        private readonly Shop $shop,
    ) {
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox'], 30);
        add_action('admin_post_' . self::ACTION, [$this, 'handleResend']);
    }

    public function addMetaBox(): void
    {
        $screens = ['shop_order'];
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }

        foreach (array_unique($screens) as $screen) {
            add_meta_box('tickets-sharp-attendees', __('Tickets', 'tickets-sharp'), [$this, 'render'], $screen, 'normal', 'default');
        }
    }

    /**
     * @param mixed $postOrOrder WP_Post on the legacy screen, WC_Order under HPOS.
     */
    public function render($postOrOrder): void
    {
        $order = $postOrOrder instanceof WC_Order
            ? $postOrOrder
            : $this->shop->order($postOrOrder instanceof WP_Post ? $postOrOrder->ID : 0);

        if ($order instanceof WC_Order) {
            echo $this->html($order); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
        }
    }

    public function html(WC_Order $order): string
    {
        $attendees = $this->provider->get_attendees_by_order_id($order->get_id());
        if ($attendees === []) {
            return '<p>' . esc_html__('This order has no event tickets.', 'tickets-sharp') . '</p>';
        }

        $rows = '';
        foreach ($attendees as $attendee) {
            $fields = '';
            foreach ((array) ($attendee['attendee_meta'] ?? []) as $key => $value) {
                if (is_scalar($value) && (string) $value !== '') {
                    $fields .= '<br><small>' . esc_html((string) $key) . ': ' . esc_html((string) $value) . '</small>';
                }
            }

            $rows .= '<tr>'
                . '<td>' . esc_html((string) ($attendee['ticket_name'] ?? '')) . '</td>'
                . '<td>' . esc_html((string) ($attendee['holder_name'] ?? '')) . '<br><small>' . esc_html((string) ($attendee['holder_email'] ?? '')) . '</small>' . $fields . '</td>'
                . '<td><code>' . esc_html((string) ($attendee['security_code'] ?? '')) . '</code></td>'
                . '<td>' . esc_html((string) ($attendee['order_status_label'] ?? '')) . '</td>'
                . '<td>' . (empty($attendee['check_in']) ? '—' : esc_html__('Checked in', 'tickets-sharp')) . '</td>'
                . '</tr>';
        }

        $resend = wp_nonce_url(
            add_query_arg(['action' => self::ACTION, 'order_id' => $order->get_id()], admin_url('admin-post.php')),
            self::ACTION . '_' . $order->get_id()
        );

        return '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__('Ticket', 'tickets-sharp') . '</th>'
            . '<th>' . esc_html__('Attendee', 'tickets-sharp') . '</th>'
            . '<th>' . esc_html__('Security code', 'tickets-sharp') . '</th>'
            . '<th>' . esc_html__('Status', 'tickets-sharp') . '</th>'
            . '<th>' . esc_html__('Check-in', 'tickets-sharp') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p><a class="button" href="' . esc_url($resend) . '">' . esc_html__('Send tickets again', 'tickets-sharp') . '</a></p>';
    }

    public function handleResend(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in resend().
        $url = $this->resend(wp_unslash($_GET));
        wp_safe_redirect($url);
        exit;
    }

    /**
     * @param array<mixed, mixed> $query
     * @return string Where to go afterwards.
     */
    public function resend(array $query): string
    {
        $orderId = is_numeric($query['order_id'] ?? null) ? (int) $query['order_id'] : 0;
        $nonce = is_string($query['_wpnonce'] ?? null) ? $query['_wpnonce'] : '';

        if ($orderId <= 0 || ! wp_verify_nonce($nonce, self::ACTION . '_' . $orderId) || ! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('You are not allowed to do that.', 'tickets-sharp'), '', ['response' => 403]);
        }

        $order = $this->shop->order($orderId);
        $sent = $order instanceof WC_Order && $this->mailer->sendForOrder($order, true);

        if ($order instanceof WC_Order) {
            $order->add_order_note($sent
                ? __('Event tickets sent again.', 'tickets-sharp')
                : __('Event tickets could not be sent: the order has no paid tickets.', 'tickets-sharp'));
        }

        $back = $order instanceof WC_Order ? $order->get_edit_order_url() : admin_url('edit.php?post_type=shop_order');

        return add_query_arg('tsharp_resent', $sent ? '1' : '0', $back);
    }
}
