<?php

declare(strict_types=1);

use BleedingDeacons\WpMocks\WpState;
use TicketsSharp\Attendees\Ledger;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Integration\EventTickets;
use TicketsSharp\Stock\SharedCapacity;
use TicketsSharp\Stock\StockSync;
use TicketsSharp\Tests\Support\TestPost;

beforeEach(function (): void {
    $this->provider = Mockery::mock(Provider::class);
    $this->ledger = Mockery::mock(Ledger::class);
    $this->shared = Mockery::mock(SharedCapacity::class);
    $this->eventTickets = Mockery::mock(EventTickets::class);
    $this->eventTickets->shouldReceive('capacityKey')->andReturn('_tribe_ticket_capacity');
    $this->sync = new StockSync($this->provider, $this->ledger, $this->shared, $this->eventTickets);

    WpState::$postMeta[5] = ['_manage_stock' => 'yes', '_tribe_ticket_capacity' => '10'];
    WpState::$postMeta[6] = ['_manage_stock' => 'yes', '_tribe_ticket_capacity' => '10'];
    WpState::$postMeta[7] = ['_manage_stock' => 'no'];
    WpState::$postMeta[8] = ['_manage_stock' => 'yes', '_tribe_ticket_capacity' => '-1'];
});

it('listens for attendee and ticket changes', function (): void {
    $this->sync->register();

    $this->assertActionAdded('tickets_sharp/attendees_changed');
    $this->assertActionAdded('tickets_sharp/ticket_saved');
});

it('recomputes stock from seats held, within the shared pool', function (): void {
    $this->provider->shouldReceive('get_tickets_ids')->with(10)->andReturn([5, 6, 7, 8]);
    $this->shared->shouldReceive('sync')->once()->with(10, [5, 6, 7, 8]);
    $this->shared->shouldReceive('remaining')->with(10, [5, 6, 7, 8])->andReturn(4);
    $this->shared->shouldReceive('isShared')->with(5)->andReturn(false);
    $this->shared->shouldReceive('isShared')->with(6)->andReturn(true);
    $this->ledger->shouldReceive('seatHolders')->with(5)->andReturn([TestPost::attendee(1), TestPost::attendee(2), TestPost::attendee(3)]);
    $this->ledger->shouldReceive('seatHolders')->with(6)->andReturn([]);
    $this->eventTickets->shouldReceive('clearTicketCaches')->once()->with(10);

    $this->sync->syncEvents([10, '10']);

    expect(WpState::$postMeta[5])->toMatchArray(['_stock' => 7, '_stock_status' => 'instock'])
        ->and(WpState::$postMeta[6])->toMatchArray(['_stock' => 4, '_stock_status' => 'instock'])
        ->and(WpState::$postMeta[7])->not->toHaveKey('_stock')
        ->and(WpState::$postMeta[8])->not->toHaveKey('_stock');
});

it('marks a ticket sold out', function (): void {
    $this->ledger->shouldReceive('seatHolders')->with(5)->andReturn(array_map(static fn (int $i) => TestPost::attendee($i), range(1, 12)));

    $this->sync->syncTicket(5);

    expect(WpState::$postMeta[5])->toMatchArray(['_stock' => 0, '_stock_status' => 'outofstock']);
});

it('ignores a missing event and a non-array ticket list', function (): void {
    $this->provider->shouldReceive('get_tickets_ids')->with(11)->andReturn(false);
    $this->shared->shouldReceive('sync')->once()->with(11, []);
    $this->shared->shouldReceive('remaining')->andReturn(-1);
    $this->eventTickets->shouldReceive('clearTicketCaches')->once();

    $this->sync->syncEvent('x');
    $this->sync->syncEvents(11);
});
