<?php

declare(strict_types=1);

namespace TicketsSharp\Admin;

use TicketsSharp\Attendees\FieldSet;
use TicketsSharp\Commerce\TicketProducts;
use TicketsSharp\Support\Keys;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The "Attendee information" tab on a ticket product's edit screen.
 *
 * It lives on the WooCommerce product rather than in Event Tickets' ticket
 * editor because the block editor's ticket form is a React component with no
 * extension point for extra fields; the product screen is the same in either
 * editor. Event Tickets' classic ticket form links here.
 */
class ProductFieldsTab
{
    public const TAB = 'tsharp_attendees';
    public const INPUT = 'tsharp_attendee_fields';

    public function __construct(private readonly TicketProducts $products)
    {
    }

    public function register(): void
    {
        add_filter('woocommerce_product_data_tabs', [$this, 'addTab']);
        add_action('woocommerce_product_data_panels', [$this, 'renderPanel']);
        add_action('woocommerce_process_product_meta', [$this, 'save']);
        add_action('tribe_events_tickets_metabox_edit_advanced', [$this, 'ticketEditorLink'], 20, 2);
    }

    /**
     * @param mixed $tabs
     * @return mixed
     */
    public function addTab($tabs)
    {
        if (! is_array($tabs) || ! $this->products->isTicket($this->currentProductId())) {
            return $tabs;
        }

        $tabs[self::TAB] = [
            'label' => __('Attendee information', 'tickets-sharp'),
            'target' => self::TAB . '_panel',
            'class' => [],
            'priority' => 65,
        ];

        return $tabs;
    }

    public function renderPanel(): void
    {
        $productId = $this->currentProductId();
        if (! $this->products->isTicket($productId)) {
            return;
        }

        echo $this->panelHtml($productId); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }

    public function panelHtml(int $productId): string
    {
        $fields = FieldSet::parse(get_post_meta($productId, Keys::PRODUCT_FIELDS, true));

        return '<div id="' . esc_attr(self::TAB) . '_panel" class="panel woocommerce_options_panel hidden">'
            . '<div class="options_group"><p class="form-field">'
            . '<label for="' . esc_attr(self::INPUT) . '">' . esc_html__('Fields', 'tickets-sharp') . '</label>'
            . '<textarea id="' . esc_attr(self::INPUT) . '" name="' . esc_attr(self::INPUT) . '" rows="8" style="width:100%;font-family:monospace;" placeholder="'
            . esc_attr("Name | name | required\nEmail | email | required\nDietary needs | select | optional | None, Vegetarian, Vegan") . '">'
            . esc_textarea(FieldSet::toText($fields)) . '</textarea></p>'
            . '<p class="description" style="padding:0 12px;">'
            . esc_html__('One field per line: Label | type | required or optional | options (for a select, comma-separated). Types: name, email, text, tel, textarea, select, checkbox. The first "name" field is printed on the ticket and the first "email" field receives it; without them the buyer\'s billing details are used. Leave empty to ask nothing.', 'tickets-sharp')
            . '</p></div></div>';
    }

    /**
     * WooCommerce has verified its own nonce and the user's capability before
     * firing `woocommerce_process_product_meta`.
     *
     * @param mixed $productId
     */
    public function save($productId): void
    {
        $productId = is_numeric($productId) ? (int) $productId : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
        if (! $this->products->isTicket($productId) || ! isset($_POST[self::INPUT])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed field by field.
        $text = is_string($_POST[self::INPUT]) ? wp_unslash($_POST[self::INPUT]) : '';
        $this->saveText($productId, $text);
    }

    public function saveText(int $productId, string $text): void
    {
        $fields = FieldSet::fromText($text);

        if ($fields === []) {
            delete_post_meta($productId, Keys::PRODUCT_FIELDS);
            return;
        }

        update_post_meta($productId, Keys::PRODUCT_FIELDS, $fields);
    }

    /**
     * A pointer from Event Tickets' classic ticket form to this tab.
     *
     * @param mixed $postId
     * @param mixed $ticketId
     */
    public function ticketEditorLink($postId, $ticketId = null): void
    {
        $ticketId = is_numeric($ticketId) ? (int) $ticketId : 0;
        if (! $this->products->isTicket($ticketId)) {
            return;
        }

        $url = get_edit_post_link($ticketId, 'raw');
        if (! is_string($url) || $url === '') {
            return;
        }

        printf(
            '<p class="tsharp-ticket-editor-link"><a href="%s" target="_blank" rel="noopener">%s</a></p>',
            esc_url($url . '#' . self::TAB),
            esc_html__('Attendee information fields and other WooCommerce settings for this ticket', 'tickets-sharp')
        );
    }

    private function currentProductId(): int
    {
        global $post;

        return is_object($post) && isset($post->ID) && is_numeric($post->ID) ? (int) $post->ID : 0;
    }
}
