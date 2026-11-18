<?php

declare(strict_types=1);

namespace TicketsSharp\CheckIn;

use TicketsSharp\Commerce\Provider;
use TicketsSharp\Integration\EventTickets;
use TicketsSharp\Support\Keys;

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

        $image = $this->imageTag($ticket);
        if ($image === '') {
            return '';
        }

        return '<td class="tec-tickets__email-table-content-ticket-qr tsharp-qr" style="padding:8px 0;text-align:center;">' . $image . '</td>';
    }

    /**
     * The attendee's QR image URL, made once and then kept on the attendee —
     * the same ticket can be e-mailed several times (ticket e-mail, order
     * e-mail, a resend), and each fresh render would otherwise write a new
     * file. A kept URL is only reused while it still encodes the attendee's
     * current security code.
     *
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

        $kept = get_post_meta($attendeeId, Keys::ATTENDEE_QR_IMAGE, true);
        if (is_array($kept) && ($kept['code'] ?? null) === $code && is_string($kept['url'] ?? null) && $kept['url'] !== '') {
            return $kept['url'];
        }

        $link = $this->eventTickets->qrCheckinUrl($attendeeId, $eventId, $code);
        if ($link === '') {
            return '';
        }

        $url = $this->eventTickets->qrImageUrl($link, 'tsharp-qr-' . $attendeeId . '-' . substr(md5($link), 0, 12));
        if ($url !== '') {
            update_post_meta($attendeeId, Keys::ATTENDEE_QR_IMAGE, ['code' => $code, 'url' => $url]);
        }

        return $url;
    }

    /**
     * An `<img>` of the attendee's QR code, or ''.
     *
     * @param array<string, mixed> $ticket
     */
    public function imageTag(array $ticket, int $size = 160): string
    {
        $url = $this->imageUrl($ticket);
        if ($url === '') {
            return '';
        }

        return '<img src="' . esc_url($url) . '" width="' . $size . '" height="' . $size . '" alt="' . esc_attr__('Check-in QR code', 'tickets-sharp') . '" style="display:block;margin:0 auto;" />';
    }
}
