<?php

declare(strict_types=1);

use TicketsSharp\Attendees\AttendeeStore;
use TicketsSharp\Attendees\Ledger;
use TicketsSharp\Orders\StatusMap;
use TicketsSharp\Support\Keys;
use TicketsSharp\Support\Settings;
use TicketsSharp\Tests\Support\TestPost;

beforeEach(function (): void {
    $this->store = Mockery::mock(AttendeeStore::class)->makePartial();
    $this->ledger = new Ledger($this->store, new StatusMap(new Settings()));
    $this->now = gmdate('Y-m-d H:i:s');
    $this->old = gmdate('Y-m-d H:i:s', time() - 7200);
});

it('counts a ticket\'s attendees by what their status means', function (): void {
    $attendees = [
        TestPost::attendee(1, [Keys::ATTENDEE_STATUS => 'completed', Keys::ATTENDEE_EVENT => 10], $this->old),
        TestPost::attendee(2, [Keys::ATTENDEE_STATUS => 'processing'], $this->old),
        TestPost::attendee(3, [Keys::ATTENDEE_STATUS => 'pending'], $this->now),
        TestPost::attendee(4, [Keys::ATTENDEE_STATUS => 'pending'], $this->old),
        TestPost::attendee(5, [Keys::ATTENDEE_STATUS => 'on-hold'], $this->old),
        TestPost::attendee(6, [Keys::ATTENDEE_STATUS => 'cancelled'], $this->now),
        TestPost::attendee(7, [Keys::ATTENDEE_STATUS => 'failed'], $this->now),
        TestPost::attendee(8, [Keys::ATTENDEE_STATUS => 'refunded'], $this->now),
    ];
    $this->store->shouldReceive('byTicket')->with(5)->andReturn($attendees);

    expect($this->ledger->countsForTicket(5))->toBe(['paid' => 2, 'pending' => 2, 'cancelled' => 2, 'refunded' => 1, 'held' => 4])
        ->and(array_map(static fn ($p) => $p->ID, $this->ledger->seatHolders(5)))->toBe([1, 2, 3, 5])
        ->and($this->ledger->eventOf($attendees[0]))->toBe(10);
});

it('works out seat holding from Event Tickets\' attendee arrays', function (): void {
    expect($this->ledger->arrayHoldsSeat(['order_status' => 'completed']))->toBeTrue()
        ->and($this->ledger->arrayHoldsSeat(['order_status' => 'pending', 'tsharp_created' => time() - 60]))->toBeTrue()
        ->and($this->ledger->arrayHoldsSeat(['order_status' => 'pending', 'tsharp_created' => time() - 7200]))->toBeFalse()
        ->and($this->ledger->arrayHoldsSeat(['order_status' => 'cancelled']))->toBeFalse()
        ->and($this->ledger->arrayHoldsSeat([]))->toBeTrue();
});

it('reads the creation time in UTC, and treats a missing one as now', function (): void {
    expect($this->ledger->createdAt(TestPost::attendee(1, [], '2026-07-24 12:00:00')))->toBe(strtotime('2026-07-24 12:00:00 UTC'))
        ->and($this->ledger->createdAt(TestPost::attendee(2, [], '0000-00-00 00:00:00')))->toBeGreaterThan(time() - 5)
        ->and($this->ledger->createdAt(TestPost::attendee(3)))->toBeGreaterThan(time() - 5);
});
