<?php

declare(strict_types=1);

namespace TicketsSharp\Integration\Repositories;

use TicketsSharp\Support\Keys;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * `tribe_tickets('tickets-sharp')`: Tickets Sharp's ticket products only.
 */
final class TicketRepository extends DefaultTicketRepository
{
    /** @return array<string, string> */
    public function ticket_types()
    {
        return [Keys::ORM_PROVIDER => 'product'];
    }

    /** @return array<string, string> */
    public function ticket_to_event_keys()
    {
        return [Keys::ORM_PROVIDER => Keys::TICKET_EVENT];
    }
}
