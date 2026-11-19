<?php

declare(strict_types=1);

namespace TicketsSharp\Attendees;

use TicketsSharp\Commerce\Urls;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Support\Keys;
use WC_Cart;
use WC_Order_Item_Product;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Collects attendee information between the tickets form and the checkout.
 *
 * The page (shortcode `[tickets_sharp_attendees]`) shows one set of fields per
 * ticket in the cart. Answers are kept as cart item data, so they follow the
 * cart through either checkout (classic or block), and are copied to the order
 * line when the order is created. Checkout is refused until every ticket has
 * valid answers — if a quantity changes in the cart, the buyer is sent back.
 *
 * Nothing a buyer enters is thrown away while they change their mind. The
 * page saves what has been typed as it goes (`assets/js/attendees.js`, through
 * {@see draftResponse()}), so leaving it for the event page keeps it. When a
 * quantity goes down, the answers of the attendees left out stay on the cart
 * line (unused, and not copied to the order) and come back if it goes up
 * again. When a ticket leaves the basket its answers are set aside in the
 * WooCommerce session and restored if it is added again; checking out clears
 * them.
 *
 * Doing this before checkout, rather than as checkout fields, is what makes it
 * work with the block checkout, whose extension API has no repeating fields.
 *
 * @phpstan-import-type Field from FieldSet
 */
class DetailsPage
{
    public const SHORTCODE = 'tickets_sharp_attendees';
    public const NONCE = 'tsharp_attendees';
    public const INPUT = 'tsharp_attendee';
    public const DRAFT_ACTION = 'tsharp_attendee_draft';

    public function __construct(
        private readonly Shop $shop,
        private readonly Urls $urls,
    ) {
    }

    public function register(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'render']);
        add_action('template_redirect', [$this, 'maybeSave'], 6);
        add_action('wp_ajax_' . self::DRAFT_ACTION, [$this, 'draftResponse']);
        add_action('wp_ajax_nopriv_' . self::DRAFT_ACTION, [$this, 'draftResponse']);
        add_action('woocommerce_check_cart_items', [$this, 'checkCart']);
        add_action('woocommerce_store_api_cart_errors', [$this, 'storeApiErrors'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'copyToOrderItem'], 10, 4);
        add_filter('woocommerce_get_item_data', [$this, 'itemData'], 10, 2);
        add_action('woocommerce_remove_cart_item', [$this, 'setAside'], 10, 2);
        add_action('woocommerce_add_to_cart', [$this, 'restore'], 10, 2);
        add_action('woocommerce_cart_emptied', [$this, 'forgetSetAside']);
    }

    /**
     * Keeps the answers of a ticket taken out of the basket.
     *
     * @param mixed $cartItemKey
     * @param mixed $cart
     */
    public function setAside($cartItemKey, $cart = null): void
    {
        $cart = $cart instanceof WC_Cart ? $cart : $this->shop->cart();
        $item = $cart instanceof WC_Cart && is_string($cartItemKey) ? ($cart->cart_contents[$cartItemKey] ?? null) : null;
        if (! is_array($item)) {
            return;
        }

        $productId = is_numeric($item['product_id'] ?? null) ? (int) $item['product_id'] : 0;
        $answers = self::answers($item[Keys::CART_ATTENDEES] ?? []);
        if ($productId <= 0 || $answers === []) {
            return;
        }

        $setAside = $this->setAsideAnswers();
        $setAside[$productId] = $answers;
        $this->shop->sessionSet(Keys::SESSION_SET_ASIDE, $setAside);
    }

    /**
     * Gives a ticket added to the basket the answers set aside when it was
     * taken out, unless its line already has answers of its own.
     *
     * @param mixed $cartItemKey
     * @param mixed $productId
     */
    public function restore($cartItemKey, $productId = 0): void
    {
        $productId = is_numeric($productId) ? (int) $productId : 0;
        $setAside = $this->setAsideAnswers();
        $cart = $this->shop->cart();
        if (! isset($setAside[$productId]) || ! is_string($cartItemKey) || ! $cart instanceof WC_Cart || ! isset($cart->cart_contents[$cartItemKey])) {
            return;
        }

        if (self::answers($cart->cart_contents[$cartItemKey][Keys::CART_ATTENDEES] ?? []) === []) {
            $cart->cart_contents[$cartItemKey][Keys::CART_ATTENDEES] = $setAside[$productId];
        }

        unset($setAside[$productId]);
        $this->shop->sessionSet(Keys::SESSION_SET_ASIDE, $setAside === [] ? null : $setAside);
    }

    public function forgetSetAside(): void
    {
        if ($this->setAsideAnswers() !== []) {
            $this->shop->sessionSet(Keys::SESSION_SET_ASIDE, null);
        }
    }

    /**
     * @return array<int, list<array<string, string>>>
     */
    private function setAsideAnswers(): array
    {
        $raw = $this->shop->sessionGet(Keys::SESSION_SET_ASIDE);
        if (! is_array($raw)) {
            return [];
        }

        $setAside = [];
        foreach ($raw as $productId => $answers) {
            if (is_numeric($productId) && (int) $productId > 0) {
                $setAside[(int) $productId] = self::answers($answers);
            }
        }

        return $setAside;
    }

    /**
     * Cart lines whose ticket asks for attendee information.
     *
     * @return list<array{key: string, product_id: int, name: string, quantity: int, fields: list<Field>, answers: list<array<string, string>>}>
     */
    public function itemsNeedingFields(?WC_Cart $cart = null): array
    {
        $cart ??= $this->shop->cart();
        if (! $cart instanceof WC_Cart) {
            return [];
        }

        $items = [];
        foreach ($cart->get_cart() as $key => $item) {
            if (! is_array($item) || ! isset($item[Keys::CART_EVENT])) {
                continue;
            }
            $productId = is_numeric($item['product_id'] ?? null) ? (int) $item['product_id'] : 0;
            $fields = FieldSet::parse(get_post_meta($productId, Keys::PRODUCT_FIELDS, true));
            if ($fields === []) {
                continue;
            }

            $items[] = [
                'key' => (string) $key,
                'product_id' => $productId,
                'name' => (string) get_the_title($productId),
                'quantity' => is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 0,
                'fields' => $fields,
                'answers' => self::answers($item[Keys::CART_ATTENDEES] ?? []),
            ];
        }

        return $items;
    }

    /** Whether any ticket in the cart still lacks valid attendee information. */
    public function isIncomplete(?WC_Cart $cart = null): bool
    {
        foreach ($this->itemsNeedingFields($cart) as $item) {
            if (count($item['answers']) < $item['quantity']) {
                return true;
            }
            for ($i = 0; $i < $item['quantity']; $i++) {
                if (FieldSet::validate($item['fields'], $item['answers'][$i])['errors'] !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validates and stores posted answers in the cart.
     *
     * @param array<mixed, mixed> $input `tsharp_attendee[cart key][index][field key]`.
     * @return list<string> Error messages; empty when everything was valid.
     */
    public function save(array $input, ?WC_Cart $cart = null): array
    {
        $cart ??= $this->shop->cart();
        if (! $cart instanceof WC_Cart) {
            return [__('Your basket could not be found. Please try again.', 'tickets-sharp')];
        }

        $errors = [];
        foreach ($this->itemsNeedingFields($cart) as $item) {
            // Keyed by attendee number (`[cart key][index]`), whatever order the fields arrive in.
            $posted = is_array($input[$item['key']] ?? null) ? $input[$item['key']] : [];
            $answers = [];

            for ($i = 0; $i < $item['quantity']; $i++) {
                $result = FieldSet::validate($item['fields'], is_array($posted[$i] ?? null) ? $posted[$i] : []);
                $answers[] = $result['values'];
                foreach ($result['errors'] as $error) {
                    /* translators: 1: ticket name, 2: attendee number, 3: error message. */
                    $errors[] = sprintf(__('%1$s, attendee %2$d: %3$s', 'tickets-sharp'), $item['name'], $i + 1, $error);
                }
            }

            // Answers for attendees beyond the quantity wait for it to go up again.
            $answers = array_merge($answers, array_slice($item['answers'], $item['quantity']));

            $cart->cart_contents[$item['key']][Keys::CART_ATTENDEES] = $answers;
        }

        $cart->set_session();

        return $errors;
    }

    /**
     * admin-ajax: stores what has been typed so far, valid or not, so that
     * leaving the page without continuing (the browser's Back button, back
     * to the event page to change the numbers) loses nothing. Continuing to
     * checkout still validates everything, through {@see handlePost()}.
     */
    public function draftResponse(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in draft().
        $post = wp_unslash($_POST);
        // phpcs:enable

        wp_send_json_success(['saved' => $this->draft(is_array($post) ? $post : [])]);
    }

    /**
     * @param array<mixed, mixed> $post
     * @return bool Whether anything was stored.
     */
    public function draft(array $post): bool
    {
        $nonce = is_string($post[self::NONCE] ?? null) ? $post[self::NONCE] : '';
        if (! wp_verify_nonce($nonce, self::NONCE) || ! is_array($post[self::INPUT] ?? null)) {
            return false;
        }

        $this->save($post[self::INPUT]);

        return true;
    }

    public function maybeSave(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below.
        if (! isset($_POST[self::NONCE])) {
            return;
        }

        $url = $this->handlePost(wp_unslash($_POST));
        // phpcs:enable

        wp_safe_redirect($url);
        exit;
    }

    /**
     * @param array<mixed, mixed> $post
     * @return string Where to send the buyer next.
     */
    public function handlePost(array $post): string
    {
        $back = $this->urls->detailsPage() ?: $this->shop->cartUrl();
        $nonce = is_string($post[self::NONCE] ?? null) ? $post[self::NONCE] : '';

        if (! wp_verify_nonce($nonce, self::NONCE)) {
            $this->shop->notice(__('That form has expired. Please enter the attendee details again.', 'tickets-sharp'));
            return $back;
        }

        $errors = $this->save(is_array($post[self::INPUT] ?? null) ? $post[self::INPUT] : []);
        if ($errors === []) {
            return $this->shop->checkoutUrl();
        }

        foreach ($errors as $error) {
            $this->shop->notice($error);
        }

        return $back;
    }

    /**
     * The shortcode.
     *
     * @param mixed $atts
     */
    public function render($atts = []): string
    {
        // Enqueued here, so only pages showing the form load it; WordPress
        // prints a style enqueued this late in the footer.
        wp_enqueue_style('tickets-sharp-attendees', TICKETS_SHARP_URL . 'assets/css/attendees.css', [], TICKETS_SHARP_VERSION);

        $items = $this->itemsNeedingFields();
        if ($items === []) {
            return '<div class="tsharp-attendees tsharp-attendees--empty"><p>'
                . esc_html__('None of the tickets in your basket need attendee details.', 'tickets-sharp')
                . '</p><p><a class="button" href="' . esc_url($this->shop->checkoutUrl()) . '">'
                . esc_html__('Continue to checkout', 'tickets-sharp') . '</a></p></div>';
        }

        wp_enqueue_script('tickets-sharp-attendees', TICKETS_SHARP_URL . 'assets/js/attendees.js', [], TICKETS_SHARP_VERSION, true);

        $html = '<form class="tsharp-attendees" method="post" action="" data-draft-url="' . esc_url(admin_url('admin-ajax.php')) . '">';
        $html .= '<input type="hidden" name="' . esc_attr(self::NONCE) . '" value="' . esc_attr(wp_create_nonce(self::NONCE)) . '">';

        foreach ($items as $item) {
            $html .= '<section class="tsharp-attendees__ticket"><h3>' . esc_html($item['name']) . '</h3>';
            for ($i = 0; $i < $item['quantity']; $i++) {
                $html .= '<fieldset class="tsharp-attendees__attendee"><legend>'
                    /* translators: %d: attendee number. */
                    . esc_html(sprintf(__('Attendee %d', 'tickets-sharp'), $i + 1)) . '</legend>';
                foreach ($item['fields'] as $field) {
                    $html .= $this->renderField($item['key'], $i, $field, $item['answers'][$i][$field['key']] ?? '');
                }
                $html .= '</fieldset>';
            }
            $html .= '</section>';
        }

        $html .= '<p class="tsharp-attendees__submit"><button type="submit" class="button alt">' . esc_html__('Continue to checkout', 'tickets-sharp') . '</button></p>';

        return $html . '</form>';
    }

    public function checkCart(): void
    {
        if ($this->isIncomplete()) {
            $this->shop->notice($this->incompleteMessage());
        }
    }

    /**
     * @param mixed $errors
     * @param mixed $cart
     */
    public function storeApiErrors($errors, $cart = null): void
    {
        if ($errors instanceof WP_Error && $this->isIncomplete()) {
            $errors->add('tsharp_attendee_details', wp_strip_all_tags($this->incompleteMessage()));
        }
    }

    /**
     * Carries the event and the answers from the cart line to the order line.
     *
     * @param mixed $item
     * @param mixed $cartItemKey
     * @param mixed $values
     * @param mixed $order
     */
    public function copyToOrderItem($item, $cartItemKey, $values, $order = null): void
    {
        if (! $item instanceof WC_Order_Item_Product || ! is_array($values)) {
            return;
        }

        if (isset($values[Keys::CART_EVENT]) && is_numeric($values[Keys::CART_EVENT])) {
            $item->add_meta_data(Keys::ITEM_EVENT, (int) $values[Keys::CART_EVENT], true);
        }
        if (isset($values[Keys::CART_ATTENDEES]) && is_array($values[Keys::CART_ATTENDEES])) {
            $item->add_meta_data(Keys::ITEM_ATTENDEES, self::bought($values), true);
        }
    }

    /**
     * Shows attendee names under the ticket in the cart and checkout.
     *
     * @param mixed $data
     * @param mixed $cartItem
     * @return mixed
     */
    public function itemData($data, $cartItem)
    {
        if (! is_array($data) || ! is_array($cartItem) || empty($cartItem[Keys::CART_ATTENDEES])) {
            return $data;
        }

        $productId = is_numeric($cartItem['product_id'] ?? null) ? (int) $cartItem['product_id'] : 0;
        $fields = FieldSet::parse(get_post_meta($productId, Keys::PRODUCT_FIELDS, true));
        $names = [];
        foreach (self::bought($cartItem) as $answers) {
            $name = FieldSet::holderName($fields, $answers);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        if ($names !== []) {
            $data[] = ['key' => __('Attendees', 'tickets-sharp'), 'value' => implode(', ', $names)];
        }

        return $data;
    }

    private function incompleteMessage(): string
    {
        $url = $this->urls->detailsPage();
        $text = __('Some tickets in your basket still need attendee details.', 'tickets-sharp');

        return $url === ''
            ? $text
            : $text . ' <a href="' . esc_url($url) . '">' . esc_html__('Enter attendee details', 'tickets-sharp') . '</a>';
    }

    /**
     * @param Field $field
     */
    private function renderField(string $cartKey, int $index, array $field, string $value): string
    {
        $name = sprintf('%s[%s][%d][%s]', self::INPUT, $cartKey, $index, $field['key']);
        $id = sanitize_key('tsharp-' . $cartKey . '-' . $index . '-' . $field['key']);
        $required = $field['required'] ? ' required' : '';
        $label = esc_html($field['label']) . ($field['required'] ? ' <abbr class="required" title="' . esc_attr__('required', 'tickets-sharp') . '">*</abbr>' : '');

        $html = '<p class="form-row tsharp-attendees__field tsharp-attendees__field--' . esc_attr($field['type']) . '">';

        if ($field['type'] === 'checkbox') {
            return $html . '<label for="' . esc_attr($id) . '"><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1"'
                . ($value !== '' ? ' checked' : '') . $required . '> ' . $label . '</label></p>';
        }

        $html .= '<label for="' . esc_attr($id) . '">' . $label . '</label>';

        if ($field['type'] === 'textarea') {
            return $html . '<textarea id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"' . $required . '>' . esc_textarea($value) . '</textarea></p>';
        }

        if ($field['type'] === 'select') {
            $html .= '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"' . $required . '><option value="">' . esc_html__('Choose…', 'tickets-sharp') . '</option>';
            foreach ($field['options'] as $option) {
                $html .= '<option value="' . esc_attr($option) . '"' . ($option === $value ? ' selected' : '') . '>' . esc_html($option) . '</option>';
            }

            return $html . '</select></p>';
        }

        $type = match ($field['type']) {
            'email' => 'email',
            'tel' => 'tel',
            default => 'text',
        };
        $autocomplete = match ($field['type']) {
            'name' => ' autocomplete="name"',
            'email' => ' autocomplete="email"',
            'tel' => ' autocomplete="tel"',
            default => '',
        };

        return $html . '<input type="' . $type . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . $autocomplete . $required . '></p>';
    }

    /**
     * A cart line's answers for the attendees actually in it, leaving out any
     * kept from when its quantity was higher.
     *
     * @param array<mixed, mixed> $cartItem
     * @return list<array<string, string>>
     */
    private static function bought(array $cartItem): array
    {
        $answers = self::answers($cartItem[Keys::CART_ATTENDEES] ?? []);
        $quantity = is_numeric($cartItem['quantity'] ?? null) ? (int) $cartItem['quantity'] : count($answers);

        return array_slice($answers, 0, max(0, $quantity));
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
