<?php

declare(strict_types=1);

namespace TicketsSharp\Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reads the single `tickets_sharp_settings` option, with defaults.
 */
final class Settings
{
    /** WooCommerce statuses (without `wc-`) that issue tickets. */
    public const DEFAULT_PAID_STATUSES = ['processing', 'completed'];

    /** Minutes a pending (unpaid) order keeps its seats. */
    public const DEFAULT_PENDING_HOLD_MINUTES = 60;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = get_option(Keys::OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * @return list<string>
     */
    public function paidStatuses(): array
    {
        $value = $this->all()['paid_statuses'] ?? null;
        if (! is_array($value)) {
            return self::DEFAULT_PAID_STATUSES;
        }

        $statuses = [];
        foreach ($value as $status) {
            if (is_string($status) && $status !== '') {
                $statuses[] = self::stripPrefix($status);
            }
        }

        return $statuses === [] ? self::DEFAULT_PAID_STATUSES : array_values(array_unique($statuses));
    }

    public function pendingHoldMinutes(): int
    {
        $value = $this->all()['pending_hold_minutes'] ?? null;

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : self::DEFAULT_PENDING_HOLD_MINUTES;
    }

    public function detailsPageId(): int
    {
        $value = $this->all()['details_page_id'] ?? 0;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(array $values): void
    {
        update_option(Keys::OPTION, array_merge($this->all(), $values));
    }

    public static function stripPrefix(string $status): string
    {
        return str_starts_with($status, 'wc-') ? substr($status, 3) : $status;
    }
}
