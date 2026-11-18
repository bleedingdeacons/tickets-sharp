<?php

declare(strict_types=1);

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Actions;
use TicketsSharp\Attendees\Ledger;
use TicketsSharp\Integration\EventTickets;
use TicketsSharp\Stock\SharedCapacity;
use TicketsSharp\Tests\Support\TestPost;

beforeEach(function (): void {
    $this->eventTickets = Mockery::mock(EventTickets::class);
    $this->ledger = Mockery::mock(Ledger::class);
    $this->globalStock = Mockery::mock(Tribe__Tickets__Global_Stock::class);
    $this->eventTickets->shouldReceive('globalStock')->with(10)->andReturn($this->globalStock);
    $this->shared = new SharedCapacity($this->eventTickets, $this->ledger);

    WpState::$postMeta[5]['_global_stock_mode'] = 'global';
    WpState::$postMeta[6]['_global_stock_mode'] = 'capped';
    WpState::$postMeta[7]['_global_stock_mode'] = 'own';
});

it('knows which tickets share capacity', function (): void {
    expect($this->shared->isShared(5))->toBeTrue()
        ->and($this->shared->isShared(6))->toBeTrue()
        ->and($this->shared->isShared(7))->toBeFalse()
        ->and($this->shared->isShared(8))->toBeFalse();
});

it('counts what the event has left from the shared tickets\' seats', function (): void {
    $this->globalStock->shouldReceive('is_enabled')->andReturn(true);
    $this->eventTickets->shouldReceive('eventCapacity')->with(10)->andReturn(20);
    $this->ledger->shouldReceive('seatHolders')->with(5)->andReturn([TestPost::attendee(1), TestPost::attendee(2)]);
    $this->ledger->shouldReceive('seatHolders')->with(6)->andReturn([TestPost::attendee(3)]);
    $this->ledger->shouldReceive('seatHolders')->with(7)->never();

    expect($this->shared->remaining(10, [5, 6, 7]))->toBe(17);
});

it('reports -1 without shared capacity', function (): void {
    $this->globalStock->shouldReceive('is_enabled')->andReturn(false);

    expect($this->shared->remaining(10, [5]))->toBe(-1)
        ->and($this->shared->isEnabled(0))->toBeFalse();
});

it('reports -1 for an unlimited event', function (): void {
    $this->globalStock->shouldReceive('is_enabled')->andReturn(true);
    $this->eventTickets->shouldReceive('eventCapacity')->andReturn(-1);

    expect($this->shared->remaining(10, [5]))->toBe(-1);
});

it('never goes below zero, and writes what is left to Event Tickets', function (): void {
    $this->globalStock->shouldReceive('is_enabled')->andReturn(true);
    $this->eventTickets->shouldReceive('eventCapacity')->andReturn(1);
    $this->ledger->shouldReceive('seatHolders')->andReturn([TestPost::attendee(1), TestPost::attendee(2)]);
    $this->globalStock->shouldReceive('set_stock_level')->once()->with(0, true);
    Actions\expectDone('tickets_sharp/shared_capacity_synced')->once()->with(10, 0);

    $this->shared->sync(10, [5]);

    expect(WpState::$postMeta[10]['_tribe_ticket_global_stock_level'])->toBe(0);
});

it('writes nothing for an event without shared capacity', function (): void {
    $this->globalStock->shouldReceive('is_enabled')->andReturn(false);
    $this->globalStock->shouldReceive('set_stock_level')->never();

    $this->shared->sync(10, [5]);
});
