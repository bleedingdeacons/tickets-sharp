<?php

declare(strict_types=1);

namespace TicketsSharp\Emails;

use TicketsSharp\CheckIn\QrCode;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Orders\StatusMap;
use WC_Email;
use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The tickets, with their QR codes and each event's ticket image, inside
 * WooCommerce's own customer e-mails.
 *
 * Event Tickets sends its ticket e-mail separately, and that is the one most
 * likely to be missed: it is a second message, from a different address, and
 * often filed as promotional. Buyers read their order e-mail ("Your order is
 * complete"), so the tickets go there too — once the order is paid, and only
 * in e-mails to the customer.
 */
class OrderEmailTickets
{
    /** WooCommerce customer e-mails that confirm a paid order. */
    public const EMAILS = ['customer_processing_order', 'customer_completed_order', 'customer_invoice'];

    public function __construct(
        private readonly Provider $provider,
        private readonly StatusMap $statuses,
        private readonly QrCode $qr,
        private readonly TicketImage $image,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_email_after_order_table', [$this, 'render'], 20, 4);
    }

    /**
     * @param mixed $order
     * @param mixed $sentToAdmin
     * @param mixed $plainText
     * @param mixed $email
     */
    public function render($order, $sentToAdmin = false, $plainText = false, $email = null): void
    {
        if (! $order instanceof WC_Order || $sentToAdmin) {
            return;
        }
        if ($email instanceof WC_Email && ! in_array($email->id, self::EMAILS, true)) {
            return;
        }

        $byEvent = $this->paidTicketsByEvent($order);
        if ($byEvent === []) {
            return;
        }

        echo $plainText ? $this->text($byEvent) : $this->html($byEvent); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }

    /**
     * @return array<int, list<array<string, mixed>>> Event ID => its paid attendees.
     */
    public function paidTicketsByEvent(WC_Order $order): array
    {
        $byEvent = [];
        foreach ($this->provider->get_attendees_by_order_id($order->get_id()) as $attendee) {
            $status = is_string($attendee['order_status'] ?? null) ? $attendee['order_status'] : '';
            if (! $this->statuses->isPaid($status)) {
                continue;
            }
            $eventId = is_numeric($attendee['event_id'] ?? null) ? (int) $attendee['event_id'] : 0;
            $byEvent[$eventId][] = $attendee;
        }

        return $byEvent;
    }

    /**
     * @param array<int, list<array<string, mixed>>> $byEvent
     */
    public function html(array $byEvent): string
    {
        $html = '<div class="tsharp-order-tickets" style="margin:0 0 40px;">'
            . '<h2>' . esc_html__('Your tickets', 'tickets-sharp') . '</h2>'
            . '<p>' . esc_html__('Show the QR code for each ticket at the door.', 'tickets-sharp') . '</p>';

        foreach ($byEvent as $eventId => $attendees) {
            $html .= '<h3 style="margin:24px 0 8px;">' . esc_html((string) get_the_title($eventId)) . '</h3>';

            $image = $this->image->imageTag($eventId);
            if ($image !== '') {
                $html .= '<div style="margin:0 0 12px;">' . $image . '</div>';
            }

            $html .= '<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;border-color:#e5e5e5;">';
            foreach ($attendees as $attendee) {
                $html .= '<tr>'
                    . '<td style="vertical-align:middle;">'
                    . '<strong>' . esc_html($this->field($attendee, 'ticket_name')) . '</strong><br />'
                    . esc_html($this->field($attendee, 'holder_name')) . '<br />'
                    . '<small>' . esc_html__('Security code', 'tickets-sharp') . ': <code>' . esc_html($this->field($attendee, 'security_code')) . '</code></small>'
                    . '</td>'
                    . '<td style="width:160px;text-align:center;vertical-align:middle;">' . $this->qr->imageTag($attendee, 140) . '</td>'
                    . '</tr>';
            }
            $html .= '</table>';
        }

        return $html . '</div>';
    }

    /**
     * @param array<int, list<array<string, mixed>>> $byEvent
     */
    public function text(array $byEvent): string
    {
        $text = "\n" . strtoupper(__('Your tickets', 'tickets-sharp')) . "\n\n";

        foreach ($byEvent as $eventId => $attendees) {
            $text .= wp_strip_all_tags((string) get_the_title($eventId)) . "\n";
            foreach ($attendees as $attendee) {
                $text .= sprintf(
                    "- %s: %s (%s %s)\n",
                    $this->field($attendee, 'ticket_name'),
                    $this->field($attendee, 'holder_name'),
                    __('security code', 'tickets-sharp'),
                    $this->field($attendee, 'security_code')
                );
            }
            $text .= "\n";
        }

        return $text . __('Your QR codes are in the HTML version of this e-mail and in your ticket e-mail.', 'tickets-sharp') . "\n\n";
    }

    /**
     * @param array<string, mixed> $attendee
     */
    private function field(array $attendee, string $key): string
    {
        $value = $attendee[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
