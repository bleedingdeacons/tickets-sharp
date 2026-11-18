<?php

declare(strict_types=1);

namespace TicketsSharp\Commerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Works out what a ticket save does to stock and capacity, from the ticket
 * form's `tribe-ticket` fields. Pure: no WordPress, no I/O.
 *
 * Event Tickets' ticket form sends:
 *  - `mode`: `own` (this ticket's own capacity), `global` (draws on the event's
 *    shared capacity), `capped` (shared, but at most `capacity`), or `''` for
 *    unlimited;
 *  - `capacity`: the ticket's capacity;
 *  - `event_capacity`: the event's shared capacity, for shared modes;
 *  - `stock`: rarely sent; defaults to the capacity.
 *
 * The rules follow the ones Event Tickets' own PayPal provider applies on save
 * (`Tribe__Tickets__Commerce__PayPal__Main::save_ticket()`), so a ticket
 * behaves the same whichever provider sells it.
 */
final class CapacityPlan
{
    public const UNLIMITED = -1;

    private function __construct(
        public readonly string $mode,
        public readonly bool $manageStock,
        public readonly int $stock,
        public readonly ?int $capacity,
        public readonly bool $sharedCapacityOn,
        public readonly ?int $eventCapacity,
        public readonly bool $setEventCapacity,
    ) {
    }

    /**
     * @param array<string, mixed> $data           The `tribe-ticket` form fields.
     * @param bool                 $sharedEnabled  Whether the event already uses shared capacity.
     * @param int                  $sharedCapacity The event's current shared capacity.
     * @param bool                 $isUpdate       Editing an existing ticket rather than creating one.
     * @param int                  $alreadyTaken   Sold plus pending, for an existing ticket.
     */
    public static function fromForm(
        array $data,
        bool $sharedEnabled,
        int $sharedCapacity,
        bool $isUpdate,
        int $alreadyTaken
    ): self {
        $sharedOn = $sharedEnabled;
        $eventCapacity = null;
        $setEventCapacity = false;

        if (! $sharedEnabled) {
            if (array_key_exists('event_capacity', $data)) {
                $raw = trim(self::scalar($data['event_capacity']));
                $eventCapacity = $raw === '' ? self::UNLIMITED : (int) $raw;
                $sharedOn = true;
                $setEventCapacity = true;
            }
        } else {
            $requested = (int) self::scalar($data['event_capacity'] ?? 0);
            if ($requested !== 0 && $requested !== $sharedCapacity) {
                $eventCapacity = $requested;
                $setEventCapacity = true;
            } else {
                $eventCapacity = $sharedCapacity;
            }
        }

        $default = $sharedOn && $eventCapacity !== null ? $eventCapacity : 0;

        $capacity = null;
        $rawCapacity = trim(self::scalar($data['capacity'] ?? (string) $default));
        if ($rawCapacity !== '') {
            $capacity = (int) $rawCapacity < 0 ? self::UNLIMITED : (int) $rawCapacity;
            $default = $capacity;
        }

        $rawStock = trim(self::scalar($data['stock'] ?? (string) $default));
        $stock = $rawStock === '' ? $default : (int) $rawStock;
        if ($stock < 0) {
            $stock = self::UNLIMITED;
        }

        // The classic ticket form sends no mode, only a capacity, and says
        // "leave blank for unlimited": so without a mode, a blank or negative
        // capacity is unlimited and anything else is the ticket's own.
        // With no capacity field at all, it is unlimited too — never 0, which
        // would put the ticket on sale already sold out.
        $noCapacity = ! array_key_exists('capacity', $data) || $capacity === null || $capacity === self::UNLIMITED;
        $mode = array_key_exists('mode', $data)
            ? trim(self::scalar($data['mode']))
            : ($noCapacity && ! $sharedOn ? '' : 'own');

        if ($mode === '') {
            return new self('', false, self::UNLIMITED, self::UNLIMITED, $sharedOn, $eventCapacity, $setEventCapacity);
        }

        if ($isUpdate && $stock !== self::UNLIMITED) {
            $stock = max(0, $stock - $alreadyTaken);
        }

        // A shared ticket can never be allowed more than the event holds.
        if (
            $sharedOn
            && $mode !== 'own'
            && $eventCapacity !== null
            && ($capacity === null || $eventCapacity < $capacity)
        ) {
            $capacity = $eventCapacity;
        }

        return new self($mode, true, $stock, $capacity, $sharedOn, $eventCapacity, $setEventCapacity);
    }

    public function isUnlimited(): bool
    {
        return ! $this->manageStock;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
