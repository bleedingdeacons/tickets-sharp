<?php

declare(strict_types=1);

namespace TicketsSharp\Attendees;

use TicketsSharp\Orders\StatusMap;
use TicketsSharp\Support\Keys;
use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Counts attendees by what their status means: paid, pending, void.
 *
 * Event Tickets measures a ticket's remaining inventory as its capacity minus
 * the attendees that "decrease inventory", so these counts — not WooCommerce's
 * stock arithmetic — are what decide availability.
 */
class Ledger
{
    public function __construct(
        private readonly AttendeeStore $store,
        private readonly StatusMap $statuses,
    ) {
    }

    /**
     * @return array{paid: int, pending: int, cancelled: int, refunded: int, held: int}
     */
    public function countsForTicket(int $productId): array
    {
        $counts = ['paid' => 0, 'pending' => 0, 'cancelled' => 0, 'refunded' => 0, 'held' => 0];

        foreach ($this->store->byTicket($productId) as $attendee) {
            $status = $this->store->status($attendee->ID);

            if ($this->holdsSeat($attendee)) {
                ++$counts['held'];
            }

            if ($status === StatusMap::REFUNDED) {
                ++$counts['refunded'];
            } elseif ($this->statuses->isVoid($status)) {
                ++$counts['cancelled'];
            } elseif ($this->statuses->isPaid($status)) {
                ++$counts['paid'];
            } elseif ($this->holdsSeat($attendee)) {
                ++$counts['pending'];
            }
        }

        return $counts;
    }

    public function holdsSeat(WP_Post $attendee): bool
    {
        return $this->statuses->holdsSeat($this->store->status($attendee->ID), $this->ageInSeconds($attendee));
    }

    /**
     * @param array<string, mixed> $attendee Event Tickets' attendee array.
     */
    public function arrayHoldsSeat(array $attendee): bool
    {
        $status = is_string($attendee['order_status'] ?? null) ? $attendee['order_status'] : '';
        $created = is_numeric($attendee['tsharp_created'] ?? null) ? (int) $attendee['tsharp_created'] : time();

        return $this->statuses->holdsSeat($status, max(0, time() - $created));
    }

    public function createdAt(WP_Post $attendee): int
    {
        $gmt = $attendee->post_date_gmt;
        $timestamp = $gmt !== '' && $gmt !== '0000-00-00 00:00:00'
            ? strtotime($gmt . ' UTC')
            : false;

        return $timestamp === false ? time() : $timestamp;
    }

    private function ageInSeconds(WP_Post $attendee): int
    {
        return max(0, time() - $this->createdAt($attendee));
    }

    /**
     * Attendees of one ticket that still hold a seat, newest last.
     *
     * @return list<WP_Post>
     */
    public function seatHolders(int $productId): array
    {
        return array_values(array_filter(
            $this->store->byTicket($productId),
            fn (WP_Post $attendee): bool => $this->holdsSeat($attendee)
        ));
    }

    public function eventOf(WP_Post $attendee): int
    {
        return $this->store->metaInt($attendee->ID, Keys::ATTENDEE_EVENT);
    }
}
