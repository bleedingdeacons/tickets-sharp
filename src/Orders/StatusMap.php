<?php

declare(strict_types=1);

namespace TicketsSharp\Orders;

use TicketsSharp\Support\Settings;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What a WooCommerce order status means for a ticket.
 *
 * An attendee carries its order's status (without the `wc-` prefix), except
 * that a partial refund marks individual attendees `refunded` while the order
 * stays `completed` or `processing`.
 */
final class StatusMap
{
    public const PENDING = 'pending';
    public const ON_HOLD = 'on-hold';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';
    public const REFUNDED = 'refunded';
    public const DRAFT = 'checkout-draft';

    public function __construct(private readonly Settings $settings)
    {
    }

    /** Tickets are issued (e-mailed, checkable) for these. */
    public function isPaid(string $status): bool
    {
        return in_array(Settings::stripPrefix($status), $this->settings->paidStatuses(), true);
    }

    /** No seat, no ticket: the order will not be paid, or was paid back. */
    public function isVoid(string $status): bool
    {
        return in_array(Settings::stripPrefix($status), [self::CANCELLED, self::FAILED, self::REFUNDED], true);
    }

    /**
     * Whether an attendee in this status still occupies capacity.
     *
     * Paid and on-hold (e.g. awaiting a bank transfer) always do. Pending only
     * does for the hold window, so an abandoned checkout releases its seats
     * even if WooCommerce never cancels the order.
     *
     * @param int $ageSeconds How long ago the attendee was created.
     */
    public function holdsSeat(string $status, int $ageSeconds): bool
    {
        $status = Settings::stripPrefix($status);

        if ($this->isVoid($status) || $status === self::DRAFT) {
            return false;
        }

        if ($status === self::PENDING) {
            return $ageSeconds <= $this->settings->pendingHoldMinutes() * 60;
        }

        return true;
    }

    /**
     * Statuses in Event Tickets' vocabulary, for its status manager.
     *
     * @return array<string, array{name: string, flags: array<string, bool>}>
     */
    public function definitions(): array
    {
        $paid = [
            'trigger_option' => true,
            'attendee_generation' => true,
            'attendee_dispatch' => true,
            'stock_reduced' => true,
            'count_attendee' => true,
            'count_sales' => true,
            'count_completed' => true,
        ];
        $unpaid = [
            'incomplete' => true,
            'count_incomplete' => true,
            'count_attendee' => true,
            'stock_reduced' => true,
        ];

        $definitions = [
            self::PENDING => ['name' => 'Pending payment', 'flags' => $unpaid],
            self::ON_HOLD => ['name' => 'On hold', 'flags' => $unpaid],
            self::PROCESSING => ['name' => 'Processing', 'flags' => $unpaid],
            self::COMPLETED => ['name' => 'Completed', 'flags' => $unpaid],
            self::CANCELLED => ['name' => 'Cancelled', 'flags' => ['warning' => true, 'count_canceled' => true]],
            self::FAILED => ['name' => 'Failed', 'flags' => ['warning' => true, 'count_canceled' => true]],
            self::REFUNDED => ['name' => 'Refunded', 'flags' => ['warning' => true, 'count_refunded' => true]],
        ];

        foreach (array_keys($definitions) as $status) {
            if ($this->isPaid($status)) {
                $definitions[$status]['flags'] = $paid;
            }
        }

        return $definitions;
    }
}
