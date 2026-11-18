<?php

declare(strict_types=1);

use TicketsSharp\Commerce\Availability;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Commerce\TicketProducts;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Stock\SharedCapacity;
use TicketsSharp\Tests\Support\Make;

beforeEach(function (): void {
    $this->provider = Mockery::mock(Provider::class);
    $this->products = Mockery::mock(TicketProducts::class);
    $this->shared = Mockery::mock(SharedCapacity::class);
    $this->shop = Mockery::mock(Shop::class);
    $this->cart = new WC_Cart();
    $this->notices = [];

    $this->shop->shouldReceive('cart')->andReturn($this->cart)->byDefault();
    $this->shop->shouldReceive('notice')->andReturnUsing(function (string $message): void {
        $this->notices[] = $message;
    });
    $this->products->shouldReceive('isTicket')->andReturnUsing(static fn (int $id): bool => in_array($id, [5, 6], true));
    $this->products->shouldReceive('eventFor')->andReturn(10);
    $this->provider->shouldReceive('get_tickets_ids')->with(10)->andReturn([5, 6]);
    $this->shared->shouldReceive('isShared')->andReturn(false)->byDefault();
    $this->shared->shouldReceive('remaining')->andReturn(-1)->byDefault();

    $this->availability = new Availability($this->provider, $this->products, $this->shared, $this->shop);
});

function stockTicket(int $available, string $name = 'Adult', bool $inRange = true): Tribe__Tickets__Ticket_Object
{
    $ticket = Mockery::mock(Tribe__Tickets__Ticket_Object::class)->makePartial();
    $ticket->name = $name;
    $ticket->shouldReceive('available')->andReturn($available);
    $ticket->shouldReceive('date_in_range')->andReturn($inRange);

    return $ticket;
}

it('hooks into every point WooCommerce checks stock', function (): void {
    $this->availability->register();

    $this->assertFilterAdded('woocommerce_add_to_cart_validation');
    $this->assertFilterAdded('woocommerce_update_cart_validation');
    $this->assertActionAdded('woocommerce_check_cart_items');
    $this->assertActionAdded('woocommerce_store_api_cart_errors');
    $this->assertFilterAdded('woocommerce_is_purchasable');
    $this->assertFilterAdded('woocommerce_product_is_in_stock');
});

it('lets non-ticket products and already-failed validation through untouched', function (): void {
    expect($this->availability->validateAddToCart(true, 99, 5))->toBeTrue()
        ->and($this->availability->validateAddToCart(false, 5, 1))->toBeFalse()
        ->and($this->availability->validateCartUpdate(true, 'k', ['product_id' => 99], 3))->toBeTrue();
});

it('allows adding what is available, counting what is already in the cart', function (): void {
    $this->cart->cart_contents = ['a' => ['product_id' => 5, 'quantity' => 2], 'b' => ['product_id' => 99, 'quantity' => 7], 'c' => 'junk'];
    $this->provider->shouldReceive('get_ticket')->with(0, 5)->andReturn(stockTicket(5));

    expect($this->availability->validateAddToCart(true, '5', 3))->toBeTrue()
        ->and($this->notices)->toBe([]);
});

it('refuses adding more than is available', function (): void {
    $this->cart->cart_contents = ['a' => ['product_id' => 5, 'quantity' => 2]];
    $this->provider->shouldReceive('get_ticket')->with(0, 5)->andReturn(stockTicket(3, 'Adult'));

    expect($this->availability->validateAddToCart(true, 5, 2))->toBeFalse()
        ->and($this->notices)->toBe(['Sorry, only 3 of "Adult" are left. Please reduce the quantity in your basket.']);
});

it('reports a sold-out ticket plainly', function (): void {
    $this->provider->shouldReceive('get_ticket')->andReturn(stockTicket(0, 'Adult'));

    expect($this->availability->validateAddToCart(true, 5, 1))->toBeFalse()
        ->and($this->notices)->toBe(['Sorry, "Adult" is sold out.']);
});

it('treats a ticket it cannot load as unavailable', function (): void {
    $this->provider->shouldReceive('get_ticket')->andReturn(null);

    expect($this->availability->validateAddToCart(true, 5, 1))->toBeFalse();
});

it('checks a quantity change without double-counting the changed line', function (): void {
    $this->cart->cart_contents = ['a' => ['product_id' => 5, 'quantity' => 2]];
    $this->provider->shouldReceive('get_ticket')->andReturn(stockTicket(4));

    expect($this->availability->validateCartUpdate(true, 'a', ['product_id' => 5], 4))->toBeTrue()
        ->and($this->availability->validateCartUpdate(true, 'a', ['product_id' => 5], 5))->toBeFalse();
});

it('checks shared tickets against the event when checking the cart', function (): void {
    $this->cart->cart_contents = ['a' => ['product_id' => 5, 'quantity' => 3], 'b' => ['product_id' => 6, 'quantity' => 3]];
    $this->provider->shouldReceive('get_ticket')->with(0, 5)->andReturn(stockTicket(10, 'Adult'));
    $this->provider->shouldReceive('get_ticket')->with(0, 6)->andReturn(stockTicket(10, 'Child'));
    $this->shared->shouldReceive('isShared')->andReturn(true);
    $this->shared->shouldReceive('remaining')->with(10, [5, 6])->andReturn(4);

    $this->availability->checkCartItems();

    expect($this->notices)->toHaveCount(2)
        ->and($this->notices[0])->toContain('only 4 tickets are left for this event');
});

it('adds Store API errors for the block checkout', function (): void {
    $this->cart->cart_contents = ['a' => ['product_id' => 5, 'quantity' => 3]];
    $this->provider->shouldReceive('get_ticket')->andReturn(stockTicket(1));
    $errors = Mockery::mock(WP_Error::class);
    $errors->shouldReceive('add')->once()->with('tsharp_ticket_unavailable', Mockery::type('string'));

    $this->availability->storeApiErrors($errors);
    $this->availability->storeApiErrors('not an error object');
});

it('closes a ticket outside its sale window', function (): void {
    $this->provider->shouldReceive('get_ticket')->with(0, 5)->andReturn(stockTicket(5, inRange: false));
    $this->provider->shouldReceive('get_ticket')->with(0, 6)->andReturn(stockTicket(5));

    expect($this->availability->isPurchasable(true, Make::product(5)))->toBeFalse()
        ->and($this->availability->isPurchasable(true, Make::product(6)))->toBeTrue()
        ->and($this->availability->isPurchasable(false, Make::product(6)))->toBeFalse()
        ->and($this->availability->isPurchasable(true, Make::product(99)))->toBeTrue();
});

it('reports stock from Event Tickets', function (): void {
    $this->provider->shouldReceive('get_ticket')->with(0, 5)->andReturn(stockTicket(0));
    $this->provider->shouldReceive('get_ticket')->with(0, 6)->andReturn(stockTicket(-1));

    expect($this->availability->isInStock(true, Make::product(5)))->toBeFalse()
        ->and($this->availability->isInStock(false, Make::product(6)))->toBeTrue()
        ->and($this->availability->isInStock(false, Make::product(99)))->toBeFalse()
        ->and($this->availability->isInStock(true, null))->toBeTrue();
});

it('has nothing to count without a cart', function (): void {
    $this->shop->shouldReceive('cart')->andReturn(null);

    expect($this->availability->cartQuantities())->toBe([]);
});
