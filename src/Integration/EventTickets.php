<?php

declare(strict_types=1);

namespace TicketsSharp\Integration;

use Tribe__Tickets__Global_Stock;
use Tribe__Tickets__Ticket_Object;
use Tribe__Tickets__Tickets;
use Tribe__Tickets__Tickets_Handler;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The one place Tickets Sharp reaches into Event Tickets' globals and helpers.
 *
 * Every call to `tribe()`, a `tribe_tickets_*()` template tag or an Event
 * Tickets class constructor goes through here, so the services can be tested
 * with this class mocked rather than with Event Tickets installed.
 */
class EventTickets
{
    public function globalStock(int $eventId): Tribe__Tickets__Global_Stock
    {
        return new Tribe__Tickets__Global_Stock($eventId);
    }

    public function handler(): Tribe__Tickets__Tickets_Handler
    {
        /** @var Tribe__Tickets__Tickets_Handler $handler */
        $handler = tribe('tickets.handler');

        return $handler;
    }

    /** Event (and ticket) capacity meta key: `_tribe_ticket_capacity`. */
    public function capacityKey(): string
    {
        return (string) $this->handler()->key_capacity;
    }

    public function showDescriptionKey(): string
    {
        return (string) $this->handler()->key_show_description;
    }

    public function eventCapacity(int $eventId): int
    {
        $capacity = tribe_tickets_get_capacity($eventId);

        return is_numeric($capacity) ? (int) $capacity : -1;
    }

    public function setTicketCapacity(Tribe__Tickets__Ticket_Object $ticket, int $capacity): void
    {
        tribe_tickets_update_capacity($ticket, $capacity);
    }

    /** The provider class chosen for an event (`_tribe_default_ticket_provider`). */
    public function eventProvider(int $eventId): string
    {
        $provider = Tribe__Tickets__Tickets::get_event_ticket_provider($eventId);

        return is_string($provider) ? $provider : '';
    }

    public function clearTicketCaches(int $eventId): void
    {
        $provider = Tribe__Tickets__Tickets::get_event_ticket_provider_object($eventId);
        if ($provider instanceof Tribe__Tickets__Tickets) {
            $provider->clear_ticket_cache_for_post($eventId);
            $provider->clear_attendees_cache($eventId);
        }
        wp_cache_delete((string) $eventId, 'tec_tickets');
    }

    /**
     * The URL a ticket's QR code encodes; scanning it checks the attendee in
     * through Event Tickets' own QR observer.
     */
    public function qrCheckinUrl(int $attendeeId, int $eventId, string $securityCode): string
    {
        if (! class_exists(\TEC\Tickets\QR\Connector::class)) {
            return '';
        }

        /** @var \TEC\Tickets\QR\Connector $connector */
        $connector = tribe(\TEC\Tickets\QR\Connector::class);

        return $connector->get_checkin_url($attendeeId, $eventId, $securityCode);
    }

    /**
     * Writes a PNG QR code of $link under uploads/tec-tickets-qr/ and returns
     * its URL, or '' when Event Tickets' QR library is absent.
     *
     * A hosted image rather than a `data:` URI, because most mail clients
     * block those. Generated through Event Tickets' own QR library, but on a
     * copy of its shared instance at twice its default module size: the
     * default makes an image so small it blurs when an e-mail shows it at a
     * scannable size. `wp_upload_bits()` never overwrites, so a caller should
     * keep the URL rather than ask again ({@see \TicketsSharp\CheckIn\QrCode}).
     */
    public function qrImageUrl(string $link, string $name): string
    {
        if ($link === '' || ! class_exists(\TEC\Common\QR\QR::class)) {
            return '';
        }

        /** @var \TEC\Common\QR\QR $shared */
        $shared = tribe(\TEC\Common\QR\QR::class);
        $qr = clone $shared;
        $upload = $qr->size(6)->margin(2)->get_png_as_file($link, $name, 'tec-tickets-qr');

        return empty($upload['error']) && is_string($upload['url'] ?? null) ? $upload['url'] : '';
    }

    public function isEvent(int $postId): bool
    {
        return function_exists('tribe_is_event') && tribe_is_event($postId);
    }
}
