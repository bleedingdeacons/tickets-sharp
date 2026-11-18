<?php

declare(strict_types=1);

namespace TicketsSharp\Commerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Checks requested ticket quantities against what is left. Pure.
 *
 * Two limits apply. Each ticket has its own availability (Event Tickets'
 * `Ticket_Object::available()`, already the lower of its own capacity and the
 * event's shared capacity). And every ticket drawing on an event's shared
 * capacity draws on the same pool, so their quantities together must also fit
 * in what the event has left — a check no single ticket can make alone.
 */
final class CartCapacity
{
    public const UNLIMITED = -1;

    /**
     * @param array<int, int> $requested Product ID => quantity wanted in total (cart plus new).
     * @param array<int, array{available: int, shared: bool, event: int, name: string}> $tickets
     * @param array<int, int> $eventRemaining Event ID => shared seats left (-1: unlimited).
     * @return list<array{product: int, event: int, allowed: int, reason: string, name: string}>
     */
    public static function problems(array $requested, array $tickets, array $eventRemaining): array
    {
        $problems = [];
        $sharedByEvent = [];

        foreach ($requested as $productId => $quantity) {
            if ($quantity <= 0 || ! isset($tickets[$productId])) {
                continue;
            }
            $ticket = $tickets[$productId];

            if ($ticket['available'] !== self::UNLIMITED && $quantity > $ticket['available']) {
                $problems[] = [
                    'product' => $productId,
                    'event' => $ticket['event'],
                    'allowed' => max(0, $ticket['available']),
                    'reason' => 'ticket',
                    'name' => $ticket['name'],
                ];
                continue;
            }

            if ($ticket['shared']) {
                $sharedByEvent[$ticket['event']][$productId] = $quantity;
            }
        }

        foreach ($sharedByEvent as $eventId => $quantities) {
            $remaining = $eventRemaining[$eventId] ?? self::UNLIMITED;
            if ($remaining === self::UNLIMITED || array_sum($quantities) <= $remaining) {
                continue;
            }

            foreach (array_keys($quantities) as $productId) {
                $problems[] = [
                    'product' => $productId,
                    'event' => $eventId,
                    'allowed' => max(0, $remaining),
                    'reason' => 'shared',
                    'name' => $tickets[$productId]['name'],
                ];
            }
        }

        return $problems;
    }
}
