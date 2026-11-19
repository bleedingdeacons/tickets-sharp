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
 * Coming back to the event page shows what the basket already holds:
 * `assets/js/basket.js` asks {@see basketFor()} (through admin-ajax, since the
 * page itself may be cached) and fills the quantities in. A form filled that
 * way posts `tsharp_basket=1`, and its quantities then *replace* the basket's
 * for this event rather than adding to it, so the buyer can add or remove
 * tickets and the attendee details page gains or loses attendees to match.
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

    public const BASKET_ACTION = 'tsharp_basket';
    public const FIELDS_TEMPLATE = 'v2/tickets/commerce/fields/' . Keys::ORM_PROVIDER;

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeHandle'], 5);
        add_action('wp_ajax_' . self::BASKET_ACTION, [$this, 'basketResponse']);
        add_action('wp_ajax_nopriv_' . self::BASKET_ACTION, [$this, 'basketResponse']);
        add_filter('tribe_tickets_cart_urls', [$this, 'cartUrls']);
        add_filter('tribe_tickets_checkout_urls', [$this, 'checkoutUrls']);
        add_filter('tribe_template_path_list', [$this, 'templateFolders']);
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

        if (($post['tsharp_basket'] ?? '') === '1' && $eventId > 0) {
            return $this->replace($cart, $eventId, $request['tickets'], $back);
        }

        $chosen = array_filter($request['tickets'], static fn (int $quantity): bool => $quantity > 0);
        $added = 0;
        foreach ($chosen as $productId => $quantity) {
            if ($eventId <= 0 || $this->products->eventFor($productId) !== $eventId) {
                continue;
            }

            if ($this->add($cart, $productId, $quantity, $eventId)) {
                $added += $quantity;
            }
        }

        if ($added === 0) {
            if ($chosen === []) {
                $this->shop->notice(__('Please choose at least one ticket.', 'tickets-sharp'));
            }
            return $back;
        }

        do_action('tickets_sharp/added_to_cart', $eventId, $chosen);

        $details = $this->urls->detailsPage();

        return $details !== '' && $this->details->isIncomplete($cart) ? $details : $this->shop->checkoutUrl();
    }

    /**
     * Sets the basket's quantities for one event's tickets to what the form
     * posted: more adds attendees, fewer leaves the last ones out, and zero
     * takes the ticket out of the basket. Answers already entered are never
     * lost. Tickets the form did not post are left alone.
     *
     * @param array<int, int> $quantities Product ID => quantity wanted.
     */
    private function replace(WC_Cart $cart, int $eventId, array $quantities, string $back): string
    {
        $changed = [];
        foreach ($quantities as $productId => $quantity) {
            if ($this->products->eventFor($productId) !== $eventId) {
                continue;
            }

            $key = self::lineFor($cart, $productId, $eventId);
            $done = $key === null
                ? $quantity > 0 && $this->add($cart, $productId, $quantity, $eventId)
                : $this->setQuantity($cart, $key, $quantity);
            if ($done) {
                $changed[$productId] = $quantity;
            }
        }

        $cart->set_session();

        if ($changed !== []) {
            /**
             * Fires after the event page's form changed the basket.
             *
             * @param int             $eventId
             * @param array<int, int> $changed Product ID => new quantity (0: removed).
             */
            do_action('tickets_sharp/basket_changed', $eventId, $changed);
        }

        if ($this->basketFor($eventId, $cart) === []) {
            return $back;
        }

        $details = $this->urls->detailsPage();

        return $details !== '' && $this->details->itemsNeedingFields($cart) !== [] ? $details : $this->shop->checkoutUrl();
    }

    private function add(WC_Cart $cart, int $productId, int $quantity, int $eventId): bool
    {
        $key = $cart->add_to_cart($productId, $quantity, 0, [], [Keys::CART_EVENT => $eventId]);

        return is_string($key) && $key !== '';
    }

    /**
     * Changes one basket line's quantity through the same validation as
     * WooCommerce's own basket page (so capacity is checked). Attendee
     * answers are left alone: {@see DetailsPage} keeps those beyond the
     * quantity, and sets a removed line's aside, for if they come back.
     *
     * @return bool Whether the line changed.
     */
    private function setQuantity(WC_Cart $cart, string $key, int $quantity): bool
    {
        $item = $cart->cart_contents[$key];
        $current = is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 0;
        if ($quantity === $current) {
            return false;
        }

        if ($quantity <= 0) {
            return (bool) $cart->remove_cart_item($key);
        }

        if (! apply_filters('woocommerce_update_cart_validation', true, $key, $item, $quantity)) {
            return false;
        }

        $cart->set_quantity($key, $quantity, true);

        return true;
    }

    private static function lineFor(WC_Cart $cart, int $productId, int $eventId): ?string
    {
        foreach ($cart->get_cart() as $key => $item) {
            if (
                is_array($item)
                && self::int($item['product_id'] ?? null) === $productId
                && self::int($item[Keys::CART_EVENT] ?? null) === $eventId
            ) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * What the basket holds of one event's tickets.
     *
     * @return array<int, int> Product ID => quantity.
     */
    public function basketFor(int $eventId, ?WC_Cart $cart = null): array
    {
        $cart ??= $this->shop->cart();
        if ($eventId <= 0 || ! $cart instanceof WC_Cart) {
            return [];
        }

        $tickets = [];
        foreach ($cart->get_cart() as $item) {
            if (! is_array($item) || self::int($item[Keys::CART_EVENT] ?? null) !== $eventId) {
                continue;
            }
            $productId = self::int($item['product_id'] ?? null);
            $quantity = self::int($item['quantity'] ?? null);
            if ($productId > 0 && $quantity > 0) {
                $tickets[$productId] = ($tickets[$productId] ?? 0) + $quantity;
            }
        }

        return $tickets;
    }

    /**
     * admin-ajax: the basket's tickets for the event in `event`, for
     * `assets/js/basket.js`. Read-only and only ever the requester's own
     * basket, so, like the form itself, it takes no nonce.
     */
    public function basketResponse(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see the method comment.
        $eventId = isset($_POST['event']) && is_numeric($_POST['event']) ? (int) $_POST['event'] : 0;

        wp_send_json_success(['tickets' => (object) $this->basketFor($eventId)]);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
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
     * Lets Event Tickets find this plugin's templates in views/event-tickets.
     *
     * Event Tickets includes `v2/tickets/commerce/fields/{orm_provider}` inside
     * the form, but only from a file it finds in its template folders —
     * `tribe_template_file` is applied to a file already found, never to a
     * missing one — so the folder has to be on its list.
     *
     * @param mixed $folders
     * @return mixed
     */
    public function templateFolders($folders)
    {
        if (! is_array($folders)) {
            return $folders;
        }

        $folders[Keys::ORM_PROVIDER] = [
            'id' => Keys::ORM_PROVIDER,
            'priority' => 25,
            'path' => TICKETS_SHARP_DIR . 'views/event-tickets',
        ];

        return $folders;
    }

    /**
     * Loads the script that fills the form from the basket whenever the form
     * includes this provider's fields (the file found is ours, or a theme's
     * override of it).
     *
     * @param mixed $file
     * @param mixed $name
     * @param mixed $template
     * @return mixed
     */
    public function fieldsTemplate($file, $name, $template = null)
    {
        $path = is_array($name) ? implode('/', $name) : (is_string($name) ? $name : '');
        if ($path === self::FIELDS_TEMPLATE) {
            wp_enqueue_script('tickets-sharp-basket', TICKETS_SHARP_URL . 'assets/js/basket.js', ['jquery'], TICKETS_SHARP_VERSION, true);
        }

        return $file;
    }

    /**
     * @param array<mixed, mixed> $post
     * @return array{event: int, tickets: array<int, int>} Zero quantities are
     *         kept: they matter when the form replaces the basket.
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
            if ($id > 0 && $quantity >= 0) {
                $tickets[$id] = min(($tickets[$id] ?? 0) + $quantity, 1000);
            }
        }

        return ['event' => max(0, $event), 'tickets' => $tickets];
    }
}
