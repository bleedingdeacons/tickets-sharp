<?php

declare(strict_types=1);

namespace TicketsSharp\Orders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Chooses which attendees a partial refund cancels. Pure.
 *
 * WooCommerce records a partial refund as a quantity per order line, not per
 * person, so the plugin has to pick. It picks people who have not checked in
 * before people who have (a refunded seat that was already used is the least
 * likely intent), and the most recently created first.
 */
final class RefundAllocator
{
    private function __construct()
    {
    }

    /**
     * @param list<array{id: int, checkedIn: bool, refunded: bool}> $attendees Attendees of one order line.
     * @param int $refundedQuantity Total quantity refunded on that line so far.
     * @return list<int> Attendee IDs to mark refunded now.
     */
    public static function pick(array $attendees, int $refundedQuantity): array
    {
        $alreadyRefunded = count(array_filter($attendees, static fn (array $a): bool => $a['refunded']));
        $needed = $refundedQuantity - $alreadyRefunded;
        if ($needed <= 0) {
            return [];
        }

        $candidates = array_values(array_filter($attendees, static fn (array $a): bool => ! $a['refunded']));

        usort(
            $candidates,
            static fn (array $a, array $b): int => [$a['checkedIn'], -$a['id']] <=> [$b['checkedIn'], -$b['id']]
        );

        return array_map(
            static fn (array $a): int => $a['id'],
            array_slice($candidates, 0, $needed)
        );
    }
}
