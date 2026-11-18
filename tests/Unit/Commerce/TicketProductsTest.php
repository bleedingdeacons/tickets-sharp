<?php

declare(strict_types=1);

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use TicketsSharp\Attendees\Ledger;
use TicketsSharp\Commerce\TicketProducts;
use TicketsSharp\Integration\EventTickets;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Support\Keys;
use TicketsSharp\Tests\Support\Make;

beforeEach(function (): void {
    $this->shop = Mockery::mock(Shop::class);
    $this->eventTickets = Mockery::mock(EventTickets::class);
    $this->ledger = Mockery::mock(Ledger::class);
    $this->globalStock = Mockery::mock(Tribe__Tickets__Global_Stock::class);
    $this->products = new TicketProducts($this->shop, $this->eventTickets, $this->ledger);

    $this->eventTickets->shouldReceive('globalStock')->with(10)->andReturn($this->globalStock)->byDefault();
    $this->eventTickets->shouldReceive('eventCapacity')->with(10)->andReturn(-1)->byDefault();
    $this->eventTickets->shouldReceive('showDescriptionKey')->andReturn('_tribe_ticket_show_description');
    $this->eventTickets->shouldReceive('capacityKey')->andReturn('_tribe_ticket_capacity');
    $this->globalStock->shouldReceive('is_enabled')->andReturn(false)->byDefault();
});

function ticketObject(array $props = []): Tribe__Tickets__Ticket_Object
{
    $ticket = new Tribe__Tickets__Ticket_Object();
    $ticket->name = 'Adult';
    $ticket->description = 'Entry to the event';
    $ticket->price = '12.50';
    $ticket->menu_order = 1;
    $ticket->start_date = '2026-12-01';
    foreach ($props as $key => $value) {
        $ticket->{$key} = $value;
    }

    return $ticket;
}

it('tells WooCommerce that ticket products do not manage stock', function (): void {
    $this->products->register();
    $this->assertFilterAdded('woocommerce_product_get_manage_stock');

    Make::ticketMeta(5, 10);

    expect($this->products->filterManageStock(true, Make::product(5)))->toBeFalse()
        ->and($this->products->filterManageStock(true, Make::product(6)))->toBeTrue()
        ->and($this->products->filterManageStock(true, 'not a product'))->toBeTrue();
});

it('knows a ticket product by its event link', function (): void {
    Make::ticketMeta(5, 10);

    expect($this->products->isTicket(5))->toBeTrue()
        ->and($this->products->eventFor(5))->toBe(10)
        ->and($this->products->isTicket(6))->toBeFalse()
        ->and($this->products->eventFor(0))->toBe(0);
});

it('creates a hidden virtual product with its own capacity', function (): void {
    $product = Make::product(0, ['name' => '']);
    $this->shop->shouldReceive('newTicketProduct')->once()->andReturn($product);
    $ticket = ticketObject();
    $this->eventTickets->shouldReceive('setTicketCapacity')->once()->with($ticket, 50);

    $id = $this->products->save(10, $ticket, ['tribe-ticket' => ['mode' => 'own', 'capacity' => '50'], 'ticket_type' => 'default']);

    expect($id)->toBe(501)
        ->and($ticket->ID)->toBe(501)
        ->and($product->props)->toMatchArray([
            'name' => 'Adult',
            'short_description' => 'Entry to the event',
            'status' => 'publish',
            'catalog_visibility' => 'hidden',
            'virtual' => true,
            'regular_price' => '12.50',
            'price' => '12.50',
            'menu_order' => 1,
            'manage_stock' => true,
            'stock_quantity' => 50,
            'stock_status' => 'instock',
            'sku' => 'TS-10-501',
        ])
        ->and(WpState::$postMeta[501])->toMatchArray([
            Keys::TICKET_EVENT => 10,
            '_type' => 'default',
            '_tribe_ticket_show_description' => 'yes',
            '_ticket_start_date' => '2026-12-01',
            '_global_stock_mode' => 'own',
        ])
        ->and(WpState::$postMeta[501])->not->toHaveKey('_ticket_end_date');
});

it('updates an existing ticket, setting up shared capacity', function (): void {
    $product = Make::product(5, ['sku' => 'KEEP']);
    $this->shop->shouldReceive('product')->with(5)->andReturn($product);
    $this->ledger->shouldReceive('countsForTicket')->with(5)->andReturn(['paid' => 2, 'pending' => 1, 'cancelled' => 0, 'refunded' => 0, 'held' => 3]);
    $this->globalStock->shouldReceive('enable')->once();
    $this->globalStock->shouldReceive('set_stock_level')->once()->with(100, true);
    $ticket = ticketObject(['ID' => 5, 'show_description' => 'no']);
    $this->eventTickets->shouldReceive('setTicketCapacity')->once()->with($ticket, 40);

    $this->products->save(10, $ticket, ['tribe-ticket' => ['mode' => 'capped', 'capacity' => '40', 'event_capacity' => '100']]);

    expect($product->props['stock_quantity'])->toBe(37)
        ->and($product->props['sku'])->toBe('KEEP')
        ->and(WpState::$postMeta[10])->toBe(['_tribe_ticket_capacity' => 100, '_tribe_ticket_use_global_stock' => 1])
        ->and(WpState::$postMeta[5])->toMatchArray([
            '_global_stock_mode' => 'capped',
            '_global_stock_cap' => 40,
            '_tribe_ticket_show_description' => 'no',
        ]);
});

it('makes an unlimited ticket that does not manage stock', function (): void {
    $product = Make::product(5);
    $this->shop->shouldReceive('product')->with(5)->andReturn($product);
    $this->ledger->shouldReceive('countsForTicket')->andReturn(['paid' => 0, 'pending' => 0, 'cancelled' => 0, 'refunded' => 0, 'held' => 0]);
    WpState::$postMeta[5] = ['_global_stock_mode' => 'own', '_global_stock_cap' => 9];
    $ticket = ticketObject(['ID' => 5]);
    $this->eventTickets->shouldReceive('setTicketCapacity')->once()->with($ticket, -1);

    $this->products->save(10, $ticket, ['tribe-ticket' => ['mode' => '']]);

    expect($product->props['manage_stock'])->toBeFalse()
        ->and($product->props['stock_status'])->toBe('instock')
        ->and(WpState::$postMeta[5])->not->toHaveKey('_global_stock_mode')
        ->and(WpState::$postMeta[5])->not->toHaveKey('_global_stock_cap');
});

it('uses a requested SKU and keeps going when WooCommerce refuses it', function (): void {
    $product = new class (5) extends WC_Product_Simple {
        public function set_sku($sku): void
        {
            throw new WC_Data_Exception('product_invalid_sku', 'Duplicate SKU');
        }
    };
    $this->shop->shouldReceive('product')->with(5)->andReturn($product);
    $this->ledger->shouldReceive('countsForTicket')->andReturn(['paid' => 0, 'pending' => 0, 'cancelled' => 0, 'refunded' => 0, 'held' => 0]);
    $this->eventTickets->shouldReceive('setTicketCapacity');

    expect($this->products->save(10, ticketObject(['ID' => 5]), ['ticket_sku' => 'DUP', 'tribe-ticket' => ['capacity' => '5']]))->toBe(5);
});

it('treats a missing or negative price as free', function (): void {
    $product = Make::product(0);
    $this->shop->shouldReceive('newTicketProduct')->andReturn($product);
    $this->eventTickets->shouldReceive('setTicketCapacity');

    $this->products->save(10, ticketObject(['price' => '-4']), []);
    expect($product->props['price'])->toBe('0');

    $this->products->save(10, ticketObject(['price' => 'free']), []);
    expect($product->props['price'])->toBe('0');
});

it('gives up when there is no product to save', function (): void {
    $this->shop->shouldReceive('product')->with(5)->andReturn(null);

    expect($this->products->save(10, ticketObject(['ID' => 5]), []))->toBe(0);
});

it('gives up when WooCommerce does not save the product', function (): void {
    $product = Mockery::mock(WC_Product_Simple::class)->shouldIgnoreMissing();
    $product->shouldReceive('save')->andReturn(0);
    $this->shop->shouldReceive('newTicketProduct')->andReturn($product);

    expect($this->products->save(10, ticketObject(), []))->toBe(0);
});

it('trashes a ticket product and remembers its event', function (): void {
    Make::ticketMeta(5, 10);
    Functions\expect('wp_trash_post')->once()->with(5)->andReturn(new WP_Post(['ID' => 5]));

    expect($this->products->trash(5))->toBeTrue()
        ->and(WpState::$postMeta[5]['_tsharp_deleted_from_event'])->toBe(10)
        ->and($this->products->trash(6))->toBeFalse();
});
