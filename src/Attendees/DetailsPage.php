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

    public function __construct(
        private readonly Shop $shop,
        private readonly Urls $urls,
    ) {
    }

    public function register(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'render']);
        add_action('template_redirect', [$this, 'maybeSave'], 6);
        add_action('woocommerce_check_cart_items', [$this, 'checkCart']);
        add_action('woocommerce_store_api_cart_errors', [$this, 'storeApiErrors'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'copyToOrderItem'], 10, 4);
        add_filter('woocommerce_get_item_data', [$this, 'itemData'], 10, 2);
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
            $posted = is_array($input[$item['key']] ?? null) ? array_values($input[$item['key']]) : [];
            $answers = [];

            for ($i = 0; $i < $item['quantity']; $i++) {
                $result = FieldSet::validate($item['fields'], is_array($posted[$i] ?? null) ? $posted[$i] : []);
                $answers[] = $result['values'];
                foreach ($result['errors'] as $error) {
                    /* translators: 1: ticket name, 2: attendee number, 3: error message. */
                    $errors[] = sprintf(__('%1$s, attendee %2$d: %3$s', 'tickets-sharp'), $item['name'], $i + 1, $error);
                }
            }

            $cart->cart_contents[$item['key']][Keys::CART_ATTENDEES] = $answers;
        }

        $cart->set_session();

        return $errors;
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
        $items = $this->itemsNeedingFields();
        if ($items === []) {
            return '<div class="tsharp-attendees tsharp-attendees--empty"><p>'
                . esc_html__('None of the tickets in your basket need attendee details.', 'tickets-sharp')
                . '</p><p><a class="button" href="' . esc_url($this->shop->checkoutUrl()) . '">'
                . esc_html__('Continue to checkout', 'tickets-sharp') . '</a></p></div>';
        }

        $html = '<form class="tsharp-attendees" method="post" action="">';
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

        $html .= '<p><button type="submit" class="button alt">' . esc_html__('Continue to checkout', 'tickets-sharp') . '</button></p>';

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
            $item->add_meta_data(Keys::ITEM_ATTENDEES, self::answers($values[Keys::CART_ATTENDEES]), true);
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
        foreach (self::answers($cartItem[Keys::CART_ATTENDEES]) as $answers) {
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
