<?php

declare(strict_types=1);

use TicketsSharp\Orders\RefundAllocator;

function seat(int $id, bool $checkedIn = false, bool $refunded = false): array
{
    return ['id' => $id, 'checkedIn' => $checkedIn, 'refunded' => $refunded];
}

it('picks nobody when nothing is refunded', function (): void {
    expect(RefundAllocator::pick([seat(1), seat(2)], 0))->toBe([]);
});

it('prefers attendees who have not checked in', function (): void {
    expect(RefundAllocator::pick([seat(1), seat(2, checkedIn: true), seat(3)], 2))->toBe([3, 1]);
});

it('falls back to checked-in attendees when it must', function (): void {
    expect(RefundAllocator::pick([seat(1, checkedIn: true), seat(2)], 2))->toBe([2, 1]);
});

it('takes the most recent first', function (): void {
    expect(RefundAllocator::pick([seat(5), seat(9), seat(7)], 1))->toBe([9]);
});

it('only adds what is not already refunded', function (): void {
    expect(RefundAllocator::pick([seat(1, refunded: true), seat(2), seat(3)], 2))->toBe([3])
        ->and(RefundAllocator::pick([seat(1, refunded: true), seat(2)], 1))->toBe([]);
});

it('never picks more attendees than exist', function (): void {
    expect(RefundAllocator::pick([seat(1)], 5))->toBe([1]);
});
