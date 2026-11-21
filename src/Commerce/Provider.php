<?php

declare(strict_types=1);

namespace TicketsSharp\Commerce;

use TicketsSharp\Attendees\AttendeeStore;
use TicketsSharp\Attendees\Ledger;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Orders\StatusMap;
use TicketsSharp\Support\Keys;
use Tribe__Tickets__Ticket_Object;
use Tribe__Tickets__Tickets;
use Tribe__Tickets__Tickets_View;
use WC_Product;
use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Event Tickets commerce provider for tickets sold through WooCommerce.
 *
 * Event Tickets finds providers by constructing them: the base constructor
 * adds the class to `Tribe__Tickets__Tickets::$active_modules`. The method
 * names below are Event Tickets' own and are called by it, which is why they
 * are snake_case. Behaviour lives in the services passed in; this class
 * translates between them and Event Tickets' arrays and ticket objects.
 */
class Provider extends Tribe__Tickets__Tickets
{
    // Read by Event Tickets' Data_API with constant(), so they must be constants.
    public const ATTENDEE_OBJECT = Keys::ATTENDEE_POST_TYPE;
    public const ORDER_OBJECT = 'shop_order';
    public const ATTENDEE_EVENT_KEY = Keys::ATTENDEE_EVENT;
    public const ATTENDEE_PRODUCT_KEY = Keys::ATTENDEE_PRODUCT;
    public const ATTENDEE_ORDER_KEY = Keys::ATTENDEE_ORDER;
    public const ATTENDEE_OPTOUT_KEY = Keys::ATTENDEE_OPTOUT;

    /** @var string */
    public $orm_provider = Keys::ORM_PROVIDER;
    /** @var string */
    public $ticket_object = 'product';
    /** @var string */
    public $order_object = self::ORDER_OBJECT;
    /** @var string */
    public $attendee_object = Keys::ATTENDEE_POST_TYPE;
    /** @var string */
    public $attendee_event_key = Keys::ATTENDEE_EVENT;
    /** @var string */
    public $attendee_product_key = Keys::ATTENDEE_PRODUCT;
    /** @var string */
    public $attendee_optout_key = Keys::ATTENDEE_OPTOUT;
    /** @var string */
    public $attendee_ticket_sent = Keys::ATTENDEE_TICKET_SENT;
    /** @var string */
    public $attendee_activity_log = Keys::ATTENDEE_ACTIVITY_LOG;
    /** @var string */
    public $attendee_user_id = Keys::ATTENDEE_USER_ID;
    /** @var string */
    public $full_name = Keys::ATTENDEE_FULL_NAME;
    /** @var string */
    public $email = Keys::ATTENDEE_EMAIL;
    /** @var string */
    public $security_code = Keys::ATTENDEE_SECURITY_CODE;
    /** @var string */
    public $price_paid = Keys::ATTENDEE_PRICE_PAID;
    /** @var string */
    public $price_currency = Keys::ATTENDEE_CURRENCY;
    /** @var string */
    public $checkin_key = Keys::ATTENDEE_CHECKIN;
    /** @var string */
    public $event_key = Keys::TICKET_EVENT;
    /** @var string Event Tickets' PayPal provider names its order key this; get_key() maps to it. */
    public $order_key = Keys::ATTENDEE_ORDER;

    /** Untranslated: the constructor runs before `init`, too early for __(). */
    public const NAME = 'WooCommerce';

    public function __construct(
        private readonly Shop $shop,
        private readonly TicketProducts $products,
        private readonly AttendeeStore $store,
        private readonly Ledger $ledger,
        private readonly Urls $urls,
        private readonly StatusMap $statuses,
    ) {
        $this->plugin_name = self::NAME;

        parent::__construct();

        add_action('init', [$this, 'translateName']);
    }

    /**
     * Event Tickets calls this statically (`call_user_func([$class, 'get_instance'])`).
     */
    public static function get_instance()
    {
        /** @var static $instance */
        $instance = tribe(static::class);

        return $instance;
    }

    /**
     * Event Tickets' PayPal provider exposes this; `get_attendee_optout_key()`
     * calls it on any provider that has it.
     *
     * @param mixed $key
     * @return string
     */
    public static function get_key($key)
    {
        $map = [
            'attendee_event_key' => Keys::ATTENDEE_EVENT,
            'attendee_product_key' => Keys::ATTENDEE_PRODUCT,
            'attendee_order_key' => Keys::ATTENDEE_ORDER,
            'attendee_optout_key' => Keys::ATTENDEE_OPTOUT,
            'event_key' => Keys::TICKET_EVENT,
            'checkin_key' => Keys::ATTENDEE_CHECKIN,
            'order_key' => Keys::ATTENDEE_ORDER,
            'security_code' => Keys::ATTENDEE_SECURITY_CODE,
            'full_name' => Keys::ATTENDEE_FULL_NAME,
            'email' => Keys::ATTENDEE_EMAIL,
        ];

        return $map[strtolower(is_string($key) ? $key : '')] ?? '';
    }

    public function translateName(): void
    {
        $this->plugin_name = __('WooCommerce', 'tickets-sharp');
        self::$active_modules[static::class] = $this->plugin_name;
    }

    // ------------------------------------------------------------------
    // Tickets
    // ------------------------------------------------------------------

    /**
     * @param int|WP_Post|null $post_id
     * @param int|string       $ticket_id
     * @return Tribe__Tickets__Ticket_Object|null
     */
    public function get_ticket($post_id, $ticket_id)
    {
        $productId = is_numeric($ticket_id) ? (int) $ticket_id : 0;
        $eventId = $this->products->eventFor($productId);
        if ($eventId <= 0) {
            return null;
        }

        $product = $this->shop->product($productId);
        if (! $product instanceof WC_Product) {
            return null;
        }

        $ticket = new Tribe__Tickets__Ticket_Object();
        $ticket->ID = $productId;
        $ticket->name = $product->get_name();
        $ticket->description = $product->get_short_description();
        $ticket->menu_order = $product->get_menu_order();
        $ticket->post_type = 'product';
        $ticket->price = $product->get_price();
        $ticket->regular_price = $product->get_regular_price();
        $ticket->on_sale = $product->is_on_sale();
        $ticket->provider_class = static::class;
        $ticket->admin_link = (string) get_edit_post_link($productId, 'raw');
        $ticket->frontend_link = (string) get_permalink($eventId);
        $ticket->sku = (string) $product->get_sku();
        $ticket->start_date = $this->meta($productId, '_ticket_start_date');
        $ticket->end_date = $this->meta($productId, '_ticket_end_date');
        $ticket->start_time = $this->meta($productId, '_ticket_start_time');
        $ticket->end_time = $this->meta($productId, '_ticket_end_time');

        // Read the real meta, not the filtered "view" value WooCommerce sees.
        $manages = $this->meta($productId, '_manage_stock') === 'yes';
        $ticket->manage_stock($manages);
        $ticket->stock($manages ? (int) $this->meta($productId, '_stock') : -1);

        $mode = $this->meta($productId, \Tribe__Tickets__Global_Stock::TICKET_STOCK_MODE);
        $ticket->global_stock_mode($mode);
        $cap = $this->meta($productId, \Tribe__Tickets__Global_Stock::TICKET_STOCK_CAP);
        if ($cap !== '') {
            $ticket->global_stock_cap((int) $cap);
        }

        $counts = $this->ledger->countsForTicket($productId);
        $ticket->qty_sold($counts['paid']);
        $ticket->qty_pending($counts['pending']);
        $ticket->qty_cancelled($counts['cancelled']);
        $ticket->qty_refunded($counts['refunded']);
        $ticket->show_description = $ticket->show_description();

        /**
         * Filters a Tickets Sharp ticket object.
         *
         * @param Tribe__Tickets__Ticket_Object $ticket
         * @param int|WP_Post|null              $post_id
         * @param int                           $productId
         */
        $filtered = apply_filters('tickets_sharp/ticket', $ticket, $post_id, $productId);

        return $filtered instanceof Tribe__Tickets__Ticket_Object ? $filtered : $ticket;
    }

    /**
     * @param int|WP_Post $post
     * @return list<int>|false
     */
    public function get_tickets_ids($post = 0, ?string $context = null)
    {
        $eventId = $post instanceof WP_Post ? $post->ID : (is_numeric($post) ? (int) $post : 0);
        if ($eventId <= 0) {
            return [];
        }

        $ids = get_posts(
            [
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'orderby' => ['menu_order' => 'ASC', 'ID' => 'ASC'],
                'no_found_rows' => true,
                'meta_query' => [
                    [
                        'key' => Keys::TICKET_EVENT,
                        'value' => $eventId,
                    ],
                ],
            ]
        );

        return array_values(array_map('intval', array_filter($ids, 'is_numeric')));
    }

    /**
     * @param int|string                    $post_id
     * @param Tribe__Tickets__Ticket_Object $ticket
     * @param array<string, mixed>          $raw_data
     * @return int|false
     */
    public function save_ticket($post_id, $ticket, $raw_data = [])
    {
        parent::save_ticket($post_id, $ticket, $raw_data);

        $eventId = is_numeric($post_id) ? (int) $post_id : 0;
        if ($eventId <= 0 || ! $ticket instanceof Tribe__Tickets__Ticket_Object) {
            return false;
        }

        $isUpdate = is_numeric($ticket->ID) && (int) $ticket->ID > 0;
        $raw = is_array($raw_data) ? $raw_data : [];

        $productId = $this->products->save($eventId, $ticket, $raw);
        if ($productId <= 0) {
            return false;
        }

        wp_cache_delete((string) $productId, 'tec_tickets');

        $saveType = $isUpdate ? 'update' : 'create';
        do_action('event_tickets_after_' . $saveType . '_ticket', $eventId, $ticket, $raw, static::class);
        do_action('event_tickets_after_save_ticket', $eventId, $ticket, $raw, static::class);
        do_action('tickets_sharp/ticket_saved', $eventId, $productId, $saveType);

        return $productId;
    }

    /**
     * @param int|string $post_id
     * @param int|string $ticket_id
     * @return bool
     */
    public function delete_ticket($post_id, $ticket_id)
    {
        $productId = is_numeric($ticket_id) ? (int) $ticket_id : 0;
        $eventId = is_numeric($post_id) ? (int) $post_id : 0;

        if ($eventId <= 0) {
            $eventId = $this->products->eventFor($productId);
        }

        $deleted = $this->products->trash($productId);

        parent::delete_ticket($eventId, $productId);
        wp_cache_delete((string) $productId, 'tec_tickets');

        return $deleted;
    }

    /**
     * The capacity field in Event Tickets' classic ticket form.
     *
     * The classic form has no capacity field of its own: each provider prints
     * one here, and only when it is the event's (or the ticket's) provider —
     * Tickets Commerce follows the same rule — so exactly one is ever printed.
     * The block editor has its own capacity control and never calls this.
     *
     * @param int|string $post_id
     * @param int|string $ticket_id
     * @return void
     */
    public function do_metabox_capacity_options($post_id, $ticket_id)
    {
        /** @var \Tribe__Tickets__Tickets_Handler $handler */
        $handler = tribe('tickets.handler');
        $ticketId = is_numeric($ticket_id) ? (int) $ticket_id : 0;
        $subject = $ticketId > 0 ? $ticketId : (is_numeric($post_id) ? (int) $post_id : 0);

        if ($subject <= 0 || ! $handler->is_correct_provider($subject, $this)) {
            return;
        }

        $capacity = '';
        if ($ticketId > 0) {
            $stored = tribe_tickets_get_capacity($ticketId);
            $capacity = is_numeric($stored) && (int) $stored >= 0 ? (string) (int) $stored : '';
        }

        echo $this->capacityFieldHtml($capacity); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }

    public function capacityFieldHtml(string $capacity): string
    {
        return '<div class="input_block ticket_advanced_TicketsSharp_Commerce_Provider tribe-dependent"'
            . ' data-depends="#tec_tickets_ticket_provider" data-condition-not="Tribe__Tickets__RSVP">'
            . '<label for="TicketsSharp_capacity" class="ticket_form_label ticket_form_left">' . esc_html__('Capacity:', 'tickets-sharp') . '</label>'
            . '<input type="text" id="TicketsSharp_capacity" name="tribe-ticket[capacity]" class="ticket_field ticket_form_right" size="7" value="' . esc_attr($capacity) . '" />'
            . '<span class="tribe_soft_note ticket_form_right">' . esc_html__('Leave blank for unlimited', 'tickets-sharp') . '</span>'
            . '</div>';
    }

    /**
     * A ticket's price as a number, for Event Tickets' screens.
     *
     * Event Tickets' ticket list in the event editor calls this when it exists
     * and shows "Free" when it does not — or when it is 0.
     *
     * @param int|WP_Post|WC_Product $product
     * @return string|false The price, or false for something that is not a ticket product.
     */
    public function get_price_value($product)
    {
        $product = $this->productOf($product);

        return $product instanceof WC_Product ? $product->get_price() : false;
    }

    /**
     * A ticket's price formatted by WooCommerce (currency, decimals, a struck-
     * through regular price during a sale).
     *
     * @param int|WP_Post|WC_Product $product
     * @param array<string, mixed>|false $attendee
     * @return string
     */
    public function get_price_html($product, $attendee = false)
    {
        $product = $this->productOf($product);

        return $product instanceof WC_Product ? (string) $product->get_price_html() : '';
    }

    /**
     * WooCommerce's currency code; Event Tickets' default is `USD`.
     *
     * @return string
     */
    public function get_currency()
    {
        $code = $this->shop->currency();

        return $code !== '' ? $code : parent::get_currency();
    }

    /**
     * @param mixed $product
     */
    private function productOf($product): ?WC_Product
    {
        if ($product instanceof WC_Product) {
            return $product;
        }
        $id = $product instanceof WP_Post ? $product->ID : (is_numeric($product) ? (int) $product : 0);

        return $id > 0 && $this->products->eventFor($id) > 0 ? $this->shop->product($id) : null;
    }

    /**
     * Shared capacity ("global stock") is supported.
     *
     * @return bool
     */
    public function supports_global_stock()
    {
        return true;
    }

    // ------------------------------------------------------------------
    // Attendees
    // ------------------------------------------------------------------

    /**
     * @param int|WP_Post $attendee
     * @param int         $post_id
     * @return array<string, mixed>|false
     */
    public function get_attendee($attendee, $post_id = 0)
    {
        $post = $attendee instanceof WP_Post ? $attendee : $this->store->get(is_numeric($attendee) ? (int) $attendee : 0);
        if (! $post instanceof WP_Post || $post->post_type !== Keys::ATTENDEE_POST_TYPE) {
            return false;
        }

        $id = $post->ID;
        $productId = $this->store->metaInt($id, Keys::ATTENDEE_PRODUCT);
        $orderId = $this->store->metaInt($id, Keys::ATTENDEE_ORDER);
        $status = $this->store->status($id);
        $product = $this->shop->product($productId);
        $ticketName = $product instanceof WC_Product ? $product->get_name() : __('(deleted ticket)', 'tickets-sharp');
        $security = $this->store->metaString($id, Keys::ATTENDEE_SECURITY_CODE);
        $holderEmail = $this->store->metaString($id, Keys::ATTENDEE_EMAIL);
        $purchaserEmail = $this->store->metaString($id, Keys::ATTENDEE_PURCHASER_EMAIL);
        $created = $this->ledger->createdAt($post);

        $data = [
            'ID' => $id,
            'attendee_id' => $id,
            'event_id' => $this->store->metaInt($id, Keys::ATTENDEE_EVENT),
            'product_id' => $productId,
            'ticket' => $ticketName,
            'ticket_name' => $ticketName,
            'ticket_id' => $id,
            'qr_ticket_id' => $id,
            'security' => $security,
            'security_code' => $security,
            'check_in' => $this->store->isCheckedIn($id),
            'order_id' => $orderId,
            'order_id_display' => $orderId,
            'order_status' => $status,
            'order_status_label' => $this->statusLabel($status),
            'optout' => $this->store->metaString($id, Keys::ATTENDEE_OPTOUT) === '1',
            'user_id' => $this->store->metaInt($id, Keys::ATTENDEE_USER_ID),
            'ticket_sent' => $this->store->metaInt($id, Keys::ATTENDEE_TICKET_SENT),
            'holder_name' => $this->store->metaString($id, Keys::ATTENDEE_FULL_NAME),
            'holder_email' => $holderEmail,
            'purchaser_name' => $this->store->metaString($id, Keys::ATTENDEE_PURCHASER_NAME),
            'purchaser_email' => $purchaserEmail,
            'purchase_time' => gmdate('Y-m-d H:i:s', $created),
            'price_paid' => $this->store->metaString($id, Keys::ATTENDEE_PRICE_PAID),
            'provider' => static::class,
            'provider_slug' => Keys::PROVIDER_SLUG,
            'attendee_meta' => $this->store->fields($id),
            'is_subscribed' => false,
            'is_purchaser' => $holderEmail !== '' && $holderEmail === $purchaserEmail,
            'post_title' => $post->post_title,
            'tsharp_created' => $created,
        ];

        /** This filter is Event Tickets' own, applied by every provider. */
        $data = apply_filters('tribe_tickets_attendee_data', $data, Keys::PROVIDER_SLUG, $post, $post_id);

        return is_array($data) ? $data : false;
    }

    /**
     * @param int|string  $post_id
     * @param string|null $post_type
     * @return list<array<string, mixed>>
     */
    public function get_attendees_by_id($post_id, $post_type = null)
    {
        $id = is_numeric($post_id) ? (int) $post_id : 0;
        if ($id <= 0) {
            return [];
        }

        $type = is_string($post_type) && $post_type !== '' ? $post_type : (string) get_post_type($id);

        if ($type === Keys::ATTENDEE_POST_TYPE) {
            $posts = array_filter([$this->store->get($id)]);
        } elseif ($type === 'product') {
            $posts = $this->store->byTicket($id);
        } elseif ($type === 'shop_order' || $type === 'shop_order_placehold' || ($type === '' && $this->shop->order($id) !== null)) {
            // With HPOS on, an order is not a post, so get_post_type() says nothing.
            $posts = $this->store->byOrder($id);
        } else {
            $posts = $this->store->byEvent($id);
        }

        return $this->toAttendees($posts, $id);
    }

    /**
     * Event Tickets passes a ticket ID as an optional second argument.
     *
     * @param int|string $order_id
     * @return list<array<string, mixed>>
     */
    public function get_attendees_by_order_id($order_id)
    {
        $posts = $this->store->byOrder(is_numeric($order_id) ? (int) $order_id : 0);

        if (func_num_args() > 1 && is_numeric(func_get_arg(1)) && (int) func_get_arg(1) > 0) {
            $ticketId = (int) func_get_arg(1);
            $posts = array_values(array_filter(
                $posts,
                fn ($p): bool => $p instanceof WP_Post && $this->store->metaInt($p->ID, Keys::ATTENDEE_PRODUCT) === $ticketId
            ));
        }

        return $this->toAttendees($posts, 0);
    }

    /**
     * Checks an attendee in — only one of ours, and only once paid.
     *
     * Event Tickets' QR observer calls checkin() on every provider with the
     * scanned ID, and the base method would write this provider's check-in
     * meta onto whatever post that is. So anything that is not a Tickets
     * Sharp attendee is refused here, as is an attendee whose order is unpaid,
     * cancelled or refunded.
     *
     * @param int|string $attendee_id
     * @param mixed      $qr
     * @param mixed      $event_id
     * @param array<string, mixed> $details
     * @return bool
     */
    public function checkin($attendee_id, $qr = null, $event_id = null, $details = [])
    {
        $id = is_numeric($attendee_id) ? (int) $attendee_id : 0;
        if (! $this->store->get($id) instanceof WP_Post || ! $this->statuses->isPaid($this->store->status($id))) {
            return false;
        }

        return parent::checkin($id, $qr, $event_id, $details);
    }

    /**
     * @param int|string $attendee_id
     * @param bool       $app
     * @return bool
     */
    public function uncheckin($attendee_id, $app = false)
    {
        $id = is_numeric($attendee_id) ? (int) $attendee_id : 0;
        if (! $this->store->get($id) instanceof WP_Post) {
            return false;
        }

        return parent::uncheckin($id, $app);
    }

    /**
     * Whether an attendee occupies a seat, for Event Tickets' inventory maths.
     *
     * @param array<string, mixed> $attendee
     * @return bool
     */
    public function attendee_decreases_inventory(array $attendee)
    {
        return $this->ledger->arrayHoldsSeat($attendee);
    }

    /**
     * @param array<string, mixed>|int $order_id
     * @return array<string, mixed>
     */
    public function get_order_data($order_id)
    {
        $id = is_numeric($order_id) ? (int) $order_id : 0;
        $order = $this->shop->order($id);

        $data = [
            'order_id' => $id,
            'purchaser_name' => $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : '',
            'purchaser_email' => $order ? $order->get_billing_email() : '',
            'provider' => static::class,
            'provider_slug' => Keys::PROVIDER_SLUG,
            'purchase_time' => $order && $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
        ];

        /** Event Tickets' own filter. */
        $filtered = apply_filters('tribe_tickets_order_data', $data, Keys::PROVIDER_SLUG, $id);

        return is_array($filtered) ? $filtered : $data;
    }

    // ------------------------------------------------------------------
    // Front end
    // ------------------------------------------------------------------

    /**
     * @param string $content
     * @return void
     */
    public function front_end_tickets_form($content)
    {
        $post = get_post();
        if (! $post instanceof WP_Post || $this->get_tickets($post->ID) === []) {
            return;
        }

        Tribe__Tickets__Tickets_View::instance()->get_tickets_block($post->ID);
    }

    /**
     * Where the tickets form posts: Tickets Sharp's own handler, which adds the
     * chosen tickets to the WooCommerce cart.
     *
     * @param int|null $post_id
     * @return string
     */
    public function get_cart_url($post_id = null)
    {
        return $this->urls->cartHandler();
    }

    /**
     * @param int|null $post_id
     * @return string
     */
    public function get_checkout_url($post_id = null)
    {
        return $this->shop->checkoutUrl();
    }

    /**
     * @return bool
     */
    public function is_checkout_page()
    {
        return function_exists('is_checkout') && is_checkout();
    }

    /**
     * @param string $deprecated_or_post_id
     * @return string
     */
    public function get_event_reports_link($deprecated_or_post_id)
    {
        return '';
    }

    /**
     * @param mixed $post_id_deprecated
     * @param int   $ticket_id
     * @return string
     */
    public function get_ticket_reports_link($post_id_deprecated, $ticket_id)
    {
        return '';
    }

    /**
     * @param array<int, array<string, mixed>|int> $attendees
     * @param array<string, mixed>                 $args
     * @return bool
     */
    public function send_tickets_email_for_attendees($attendees, $args = [])
    {
        $args = array_merge(['provider' => Keys::PROVIDER_SLUG], is_array($args) ? $args : []);

        return parent::send_tickets_email_for_attendees($attendees, $args);
    }

    // ------------------------------------------------------------------

    /**
     * @param iterable<WP_Post|null> $posts
     * @return list<array<string, mixed>>
     */
    private function toAttendees(iterable $posts, int $contextId): array
    {
        $attendees = [];
        foreach ($posts as $post) {
            if (! $post instanceof WP_Post) {
                continue;
            }
            $attendee = $this->get_attendee($post, $contextId);
            if (is_array($attendee)) {
                $attendees[] = $attendee;
            }
        }

        return $attendees;
    }

    private function statusLabel(string $status): string
    {
        if ($status === '') {
            return '';
        }
        if (function_exists('wc_get_order_status_name')) {
            return (string) wc_get_order_status_name($status);
        }

        return ucfirst(str_replace('-', ' ', $status));
    }

    private function meta(int $postId, string $key): string
    {
        $value = get_post_meta($postId, $key, true);

        return is_scalar($value) ? (string) $value : '';
    }
}
