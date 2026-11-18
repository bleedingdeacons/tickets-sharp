<?php

declare(strict_types=1);

namespace TicketsSharp\Admin;

use TicketsSharp\Attendees\FieldSet;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Support\Keys;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * "Attendee information" in Event Tickets' ticket form, under Capacity.
 *
 * What a ticket asks of each attendee is set here, on the ticket itself. The
 * classic ticket form posts every input in its panel, so the textarea arrives
 * with the ticket's save as `tsharp_attendee_fields`, which
 * {@see \TicketsSharp\Commerce\TicketProducts::save()} stores. A new ticket
 * starts by asking for each attendee's name.
 *
 * Shown only when the event (or the ticket) is sold through Tickets Sharp —
 * Event Tickets' own providers print their capacity fields by the same rule.
 * The block editor's ticket form has no place for it; a ticket created there
 * gets the default, and can be edited in the classic form.
 */
class TicketFormFields
{
    public const INPUT = 'tsharp_attendee_fields';

    /** What a new ticket asks of each attendee. */
    public const DEFAULT_TEXT = 'Name | name | required';

    public function __construct(private readonly Provider $provider)
    {
    }

    public function register(): void
    {
        // After the capacity field, which Event Tickets prints at priority 11.
        add_action('tribe_events_tickets_metabox_edit_main', [$this, 'render'], 12, 2);
    }

    /**
     * @param mixed $postId
     * @param mixed $ticketId
     */
    public function render($postId, $ticketId = null): void
    {
        $ticketId = is_numeric($ticketId) ? (int) $ticketId : 0;
        $subject = $ticketId > 0 ? $ticketId : (is_numeric($postId) ? (int) $postId : 0);

        /** @var \Tribe__Tickets__Tickets_Handler $handler */
        $handler = tribe('tickets.handler');
        if ($subject <= 0 || ! $handler->is_correct_provider($subject, $this->provider)) {
            return;
        }

        echo $this->html($this->currentText($ticketId)); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }

    /**
     * The fields as editable text: the ticket's own, or the default for a new one.
     */
    public function currentText(int $ticketId): string
    {
        if ($ticketId <= 0) {
            return self::DEFAULT_TEXT;
        }

        return FieldSet::toText(FieldSet::parse(get_post_meta($ticketId, Keys::PRODUCT_FIELDS, true)));
    }

    public function html(string $text): string
    {
        return '<div class="input_block ticket_advanced_TicketsSharp_Commerce_Provider tribe-dependent tsharp-attendee-fields"'
            . ' data-depends="#tec_tickets_ticket_provider" data-condition-not="Tribe__Tickets__RSVP">'
            . '<label for="' . esc_attr(self::INPUT) . '" class="ticket_form_label ticket_form_left">' . esc_html__('Attendee information:', 'tickets-sharp') . '</label>'
            . '<textarea id="' . esc_attr(self::INPUT) . '" name="' . esc_attr(self::INPUT) . '" class="ticket_field ticket_form_right" rows="4" style="font-family:monospace;width:100%;max-width:32em;">'
            . esc_textarea($text) . '</textarea>'
            . '<span class="tribe_soft_note ticket_form_right">'
            . esc_html__('What to ask each attendee, one field per line: Label | type | required or optional | options for a select, comma-separated. Types: name, email, text, tel, textarea, select, checkbox. The first name field is printed on the ticket and the first email field receives it. Leave empty to ask nothing.', 'tickets-sharp')
            . '</span></div>';
    }
}
