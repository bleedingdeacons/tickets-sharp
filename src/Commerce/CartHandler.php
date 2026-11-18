<?php

declare(strict_types=1);

namespace TicketsSharp\Commerce;

use TicketsSharp\Attendees\DetailsPage;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Support\Keys;
use WC_Cart;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Receives Event Tickets' "Get tickets" form and turns it into a WooCommerce
 * cart.
 *
 * The form posts to {@see Provider::get_cart_url()}. Its script serialises the
 * chosen quantities into `tribe_tickets_ar_data` as JSON:
 * `{"tribe_tickets_post_id": 12, "tribe_tickets_tickets": [{"ticket_id": 34, "quantity": 2}]}`.
 * Each ticket is added to the cart (WooCommerce's add-to-cart validation —
 * including {@see Availability} — runs as usual), and the buyer goes on to the
 * attendee details page if a ticket needs it, otherwise straight to checkout.
 *
 * There is no nonce: the form is rendered into event pages that are often
 * cached, and adding tickets to one's own basket is not a privileged action.
 * Every ticket ID is checked against the posted event before anything is added.
 */
class CartHandler
{
    public function __construct(
        private readonly Shop $shop,
        private readonly TicketProducts $products,
        private readonly Urls $urls,
        private readonly DetailsPage $details,
    ) {
    }

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeHandle'], 5);
        add_filter('tribe_tickets_cart_urls', [$this, 'cartUrls']);
        add_filter('tribe_tickets_checkout_urls', [$this, 'checkoutUrls']);
        add_filter('tribe_template_file', [$this, 'fieldsTemplate'], 10, 3);
    }

    public function maybeHandle(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- see the class comment.
        if (empty($_GET[Urls::CART_QUERY_VAR])) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $url = $method === 'POST' ? $this->handle(wp_unslash($_POST)) : $this->shop->cartUrl();
        // phpcs:enable

        wp_safe_redirect($url);
        exit;
    }

    /**
     * @param array<mixed, mixed> $post
     * @return string Where to send the buyer.
     */
    public function handle(array $post): string
    {
        $request = self::decode($post);
        $eventId = $request['event'];
        $back = $eventId > 0 ? (string) get_permalink($eventId) : home_url('/');

        $cart = $this->shop->cart();
        if (! $cart instanceof WC_Cart) {
            $this->shop->notice(__('Your basket could not be opened. Please try again.', 'tickets-sharp'));
            return $back;
        }

        $added = 0;
        foreach ($request['tickets'] as $productId => $quantity) {
            if ($eventId <= 0 || $this->products->eventFor($productId) !== $eventId) {
                continue;
            }

            $key = $cart->add_to_cart($productId, $quantity, 0, [], [Keys::CART_EVENT => $eventId]);
            if (is_string($key) && $key !== '') {
                $added += $quantity;
            }
        }

        if ($added === 0) {
            if ($request['tickets'] === []) {
                $this->shop->notice(__('Please choose at least one ticket.', 'tickets-sharp'));
            }
            return $back;
        }

        do_action('tickets_sharp/added_to_cart', $eventId, $request['tickets']);

        $details = $this->urls->detailsPage();

        return $details !== '' && $this->details->isIncomplete($cart) ? $details : $this->shop->checkoutUrl();
    }

    /**
     * The tickets form's script looks these up by provider class.
     *
     * @param mixed $urls
     * @return mixed
     */
    public function cartUrls($urls)
    {
        if (is_array($urls)) {
            $urls[Provider::class] = $this->urls->cartHandler();
        }

        return $urls;
    }

    /**
     * @param mixed $urls
     * @return mixed
     */
    public function checkoutUrls($urls)
    {
        if (is_array($urls)) {
            $urls[Provider::class] = $this->shop->checkoutUrl();
        }

        return $urls;
    }

    /**
     * Event Tickets includes `v2/tickets/commerce/fields/{orm_provider}` inside
     * the form; supply ours from this plugin's views/.
     *
     * @param mixed $file
     * @param mixed $name
     * @param mixed $template
     * @return mixed
     */
    public function fieldsTemplate($file, $name, $template = null)
    {
        $path = is_array($name) ? implode('/', $name) : (is_string($name) ? $name : '');
        if ($path !== 'v2/tickets/commerce/fields/' . Keys::ORM_PROVIDER) {
            return $file;
        }

        return TICKETS_SHARP_DIR . 'views/tickets-form-fields.php';
    }

    /**
     * @param array<mixed, mixed> $post
     * @return array{event: int, tickets: array<int, int>}
     */
    public static function decode(array $post): array
    {
        $json = is_string($post['tribe_tickets_ar_data'] ?? null) ? $post['tribe_tickets_ar_data'] : '';
        $data = $json !== '' ? json_decode($json, true) : null;
        $data = is_array($data) ? $data : [];

        $event = $data['tribe_tickets_post_id'] ?? ($post['tribe_tickets_post_id'] ?? 0);
        $event = is_numeric($event) ? (int) $event : 0;

        $tickets = [];
        $list = is_array($data['tribe_tickets_tickets'] ?? null) ? $data['tribe_tickets_tickets'] : [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = is_numeric($row['ticket_id'] ?? null) ? (int) $row['ticket_id'] : 0;
            $quantity = is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0;
            if ($id > 0 && $quantity > 0) {
                $tickets[$id] = ($tickets[$id] ?? 0) + min($quantity, 1000);
            }
        }

        return ['event' => max(0, $event), 'tickets' => $tickets];
    }
}
