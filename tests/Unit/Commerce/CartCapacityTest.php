<?php

declare(strict_types=1);

use TicketsSharp\Commerce\CartCapacity;

function ticketInfo(int $available, bool $shared = false, int $event = 10, string $name = 'Adult'): array
{
    return ['available' => $available, 'shared' => $shared, 'event' => $event, 'name' => $name];
}

it('allows quantities within each ticket', function (): void {
    expect(CartCapacity::problems([1 => 2], [1 => ticketInfo(5)], []))->toBe([]);
});

it('treats -1 as unlimited', function (): void {
    expect(CartCapacity::problems([1 => 999], [1 => ticketInfo(-1)], []))->toBe([]);
});

it('reports a ticket asked for beyond what it has', function (): void {
    $problems = CartCapacity::problems([1 => 6], [1 => ticketInfo(5, name: 'Adult')], []);

    expect($problems)->toBe([[
        'product' => 1, 'event' => 10, 'allowed' => 5, 'reason' => 'ticket', 'name' => 'Adult',
    ]]);
});

it('reports a sold-out ticket as zero allowed', function (): void {
    $problems = CartCapacity::problems([1 => 1], [1 => ticketInfo(0)], []);

    expect($problems[0]['allowed'])->toBe(0);
});

it('checks shared tickets together against the event', function (): void {
    $tickets = [1 => ticketInfo(10, true, name: 'Adult'), 2 => ticketInfo(10, true, name: 'Child')];

    $problems = CartCapacity::problems([1 => 4, 2 => 4], $tickets, [10 => 6]);

    expect($problems)->toHaveCount(2)
        ->and($problems[0]['reason'])->toBe('shared')
        ->and($problems[0]['allowed'])->toBe(6)
        ->and(array_column($problems, 'product'))->toBe([1, 2]);
});

it('lets shared tickets through when they fit together', function (): void {
    $tickets = [1 => ticketInfo(10, true), 2 => ticketInfo(10, true)];

    expect(CartCapacity::problems([1 => 3, 2 => 3], $tickets, [10 => 6]))->toBe([]);
});

it('ignores own-capacity tickets in the shared sum', function (): void {
    $tickets = [1 => ticketInfo(10, true), 2 => ticketInfo(10, false)];

    expect(CartCapacity::problems([1 => 3, 2 => 9], $tickets, [10 => 3]))->toBe([]);
});

it('skips the shared check when the event has no limit', function (): void {
    $tickets = [1 => ticketInfo(10, true)];

    expect(CartCapacity::problems([1 => 9], $tickets, [10 => -1]))->toBe([])
        ->and(CartCapacity::problems([1 => 9], $tickets, []))->toBe([]);
});

it('skips zero quantities and unknown tickets', function (): void {
    expect(CartCapacity::problems([1 => 0, 7 => 3], [1 => ticketInfo(0)], []))->toBe([]);
});
