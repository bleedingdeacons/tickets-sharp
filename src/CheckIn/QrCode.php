<?php

declare(strict_types=1);

namespace TicketsSharp\CheckIn;

use TicketsSharp\Commerce\Provider;
use TicketsSharp\Integration\EventTickets;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Puts a check-in QR code on each Tickets Sharp ticket in Event Tickets'
 * ticket e-mail.
 *
 * The code encodes Event Tickets' own check-in URL. Scanning it with a phone
 * opens the site, where Event Tickets' QR observer checks the attendee in if
 * the person scanning is logged in with permission to manage the event — it
 * resolves the attendee through the provider, which works once the status
 * manager knows this one ({@see \TicketsSharp\Integration\StatusManager}).
 * The Event Tickets mobile app reads the same codes.
 *
 * The free Event Tickets e-mail has no QR code of its own, so this appends one
 * after the security code, only for this provider's tickets.
 */
class QrCode
{
    /** Event Tickets' "Tickets Emails" template for one ticket's security code. */
    public const HOOK = 'tribe_template_after_include:tickets/emails/template-parts/body/ticket/security-code';

    public function __construct(private readonly EventTickets $eventTickets)
    {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'renderForTemplate'], 10, 3);
    }

    /**
     * @param mixed $file
     * @param mixed $name
     * @param mixed $template A Tribe__Template, whose local `ticket` is the attendee array.
     */
    public function renderForTemplate($file, $name, $template): void
    {
        if (! is_object($template) || ! method_exists($template, 'get')) {
            return;
        }

        $ticket = $template->get('ticket');
        if (is_array($ticket)) {
            echo $this->cell($ticket); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
        }
    }

    /**
     * The table cell holding the QR image, or '' for any other provider's ticket.
     *
     * @param array<string, mixed> $ticket Event Tickets' attendee array.
     */
    public function cell(array $ticket): string
    {
        if (($ticket['provider'] ?? '') !== Provider::class) {
            return '';
        }

        $url = $this->imageUrl($ticket);
        if ($url === '') {
            return '';
        }

        return '<td class="tec-tickets__email-table-content-ticket-qr tsharp-qr" style="padding:8px 0;text-align:center;">'
            . '<img src="' . esc_url($url) . '" width="160" height="160" alt="' . esc_attr__('Check-in QR code', 'tickets-sharp') . '" style="display:block;margin:0 auto;"/>'
            . '</td>';
    }

    /**
     * @param array<string, mixed> $ticket
     */
    public function imageUrl(array $ticket): string
    {
        $attendeeId = is_numeric($ticket['qr_ticket_id'] ?? null) ? (int) $ticket['qr_ticket_id'] : 0;
        $eventId = is_numeric($ticket['event_id'] ?? null) ? (int) $ticket['event_id'] : 0;
        $code = is_string($ticket['security_code'] ?? null) ? $ticket['security_code'] : '';

        if ($attendeeId <= 0 || $eventId <= 0 || $code === '') {
            return '';
        }

        $link = $this->eventTickets->qrCheckinUrl($attendeeId, $eventId, $code);

        return $link === '' ? '' : $this->eventTickets->qrImageUrl($link);
    }
}
