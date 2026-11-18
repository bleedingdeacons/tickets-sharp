<?php

declare(strict_types=1);

namespace TicketsSharp\Stock;

use TicketsSharp\Attendees\Ledger;
use TicketsSharp\Integration\EventTickets;
use Tribe__Tickets__Global_Stock;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What an event's shared capacity has left, from attendees rather than from
 * running totals: capacity minus every seat held by a shared-mode ticket.
 */
class SharedCapacity
{
    public function __construct(
        private readonly EventTickets $eventTickets,
        private readonly Ledger $ledger,
    ) {
    }

    public function isShared(int $productId): bool
    {
        $mode = get_post_meta($productId, Tribe__Tickets__Global_Stock::TICKET_STOCK_MODE, true);

        return in_array($mode, [Tribe__Tickets__Global_Stock::GLOBAL_STOCK_MODE, Tribe__Tickets__Global_Stock::CAPPED_STOCK_MODE], true);
    }

    public function isEnabled(int $eventId): bool
    {
        return $eventId > 0 && $this->eventTickets->globalStock($eventId)->is_enabled();
    }

    /**
     * @param list<int> $ticketIds Every ticket of the event.
     * @return int Seats left, or -1 when the event has no shared capacity.
     */
    public function remaining(int $eventId, array $ticketIds): int
    {
        if (! $this->isEnabled($eventId)) {
            return -1;
        }

        $capacity = $this->eventTickets->eventCapacity($eventId);
        if ($capacity < 0) {
            return -1;
        }

        $held = 0;
        foreach ($ticketIds as $ticketId) {
            if ($this->isShared($ticketId)) {
                $held += count($this->ledger->seatHolders($ticketId));
            }
        }

        return max(0, $capacity - $held);
    }

    /**
     * Writes the remaining shared seats to Event Tickets' global stock level.
     *
     * @param list<int> $ticketIds
     */
    public function sync(int $eventId, array $ticketIds): void
    {
        $remaining = $this->remaining($eventId, $ticketIds);
        if ($remaining < 0) {
            return;
        }

        $this->eventTickets->globalStock($eventId)->set_stock_level($remaining, true);
        update_post_meta($eventId, Tribe__Tickets__Global_Stock::GLOBAL_STOCK_LEVEL, $remaining);
        do_action('tickets_sharp/shared_capacity_synced', $eventId, $remaining);
    }
}
