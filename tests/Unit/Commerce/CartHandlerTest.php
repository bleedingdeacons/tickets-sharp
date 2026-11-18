<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use TicketsSharp\Attendees\DetailsPage;
use TicketsSharp\Commerce\CartHandler;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Commerce\TicketProducts;
use TicketsSharp\Commerce\Urls;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Support\Keys;

beforeEach(function (): void {
    $this->shop = Mockery::mock(Shop::class);
    $this->products = Mockery::mock(TicketProducts::class);
    $this->urls = Mockery::mock(Urls::class);
    $this->details = Mockery::mock(DetailsPage::class);
    $this->cart = new WC_Cart();
    $this->notices = [];

    $this->shop->shouldReceive('cart')->andReturn($this->cart)->byDefault();
    $this->shop->shouldReceive('checkoutUrl')->andReturn('https://example.test/checkout/');
    $this->shop->shouldReceive('cartUrl')->andReturn('https://example.test/cart/');
    $this->shop->shouldReceive('notice')->andReturnUsing(function (string $message): void {
        $this->notices[] = $message;
    });
    $this->products->shouldReceive('eventFor')->andReturnUsing(static fn (int $id): int => in_array($id, [5, 6], true) ? 10 : 0);
    $this->urls->shouldReceive('cartHandler')->andReturn('https://example.test/?tsharp_cart=1');
    $this->urls->shouldReceive('detailsPage')->andReturn('https://example.test/attendee-details/')->byDefault();
    $this->details->shouldReceive('isIncomplete')->andReturn(false)->byDefault();

    $this->handler = new CartHandler($this->shop, $this->products, $this->urls, $this->details);
});

function formPost(int $event, array $tickets): array
{
    return ['tribe_tickets_ar_data' => json_encode(['tribe_tickets_post_id' => $event, 'tribe_tickets_tickets' => $tickets])];
}

it('decodes the tickets form', function (): void {
    expect(CartHandler::decode(formPost(10, [
        ['ticket_id' => 5, 'quantity' => '2'],
        ['ticket_id' => '5', 'quantity' => 1],
        ['ticket_id' => 6, 'quantity' => 0],
        ['ticket_id' => 0, 'quantity' => 3],
        ['ticket_id' => 7, 'quantity' => 5000],
        'junk',
    ])))->toBe(['event' => 10, 'tickets' => [5 => 3, 7 => 1000]]);
});

it('decodes an empty or broken post to nothing', function (): void {
    expect(CartHandler::decode([]))->toBe(['event' => 0, 'tickets' => []])
        ->and(CartHandler::decode(['tribe_tickets_ar_data' => '{broken', 'tribe_tickets_post_id' => '4']))->toBe(['event' => 4, 'tickets' => []]);
});

it('adds the tickets to the cart and goes to checkout', function (): void {
    Actions\expectDone('tickets_sharp/added_to_cart')->once()->with(10, [5 => 2, 6 => 1]);

    $url = $this->handler->handle(formPost(10, [['ticket_id' => 5, 'quantity' => 2], ['ticket_id' => 6, 'quantity' => 1]]));

    expect($url)->toBe('https://example.test/checkout/')
        ->and($this->cart->added)->toBe([
            [5, 2, [Keys::CART_EVENT => 10]],
            [6, 1, [Keys::CART_EVENT => 10]],
        ]);
});

it('sends the buyer to the attendee details page when tickets need it', function (): void {
    $this->details->shouldReceive('isIncomplete')->with($this->cart)->andReturn(true);

    expect($this->handler->handle(formPost(10, [['ticket_id' => 5, 'quantity' => 1]])))->toBe('https://example.test/attendee-details/');
});

it('goes to checkout when there is no details page', function (): void {
    $this->urls->shouldReceive('detailsPage')->andReturn('');
    $this->details->shouldReceive('isIncomplete')->never();

    expect($this->handler->handle(formPost(10, [['ticket_id' => 5, 'quantity' => 1]])))->toBe('https://example.test/checkout/');
});

it('ignores tickets that belong to another event', function (): void {
    expect($this->handler->handle(formPost(10, [['ticket_id' => 99, 'quantity' => 1]])))->toBe('https://example.test/?p=10')
        ->and($this->cart->added)->toBe([]);
});

it('asks for a ticket when none was chosen', function (): void {
    expect($this->handler->handle(formPost(10, [])))->toBe('https://example.test/?p=10')
        ->and($this->notices)->toBe(['Please choose at least one ticket.']);
});

it('goes back when WooCommerce refuses the ticket', function (): void {
    $cart = Mockery::mock(WC_Cart::class);
    $cart->shouldReceive('add_to_cart')->andReturn(false);
    $this->shop->shouldReceive('cart')->andReturn($cart);

    expect($this->handler->handle(formPost(10, [['ticket_id' => 5, 'quantity' => 1]])))->toBe('https://example.test/?p=10')
        ->and($this->notices)->toBe([]);
});

it('goes back when there is no cart', function (): void {
    $this->shop->shouldReceive('cart')->andReturn(null);

    expect($this->handler->handle(formPost(0, [])))->toBe('https://example.test/')
        ->and($this->notices)->toHaveCount(1);
});

it('registers its hooks and URLs', function (): void {
    $this->handler->register();

    $this->assertActionAdded('template_redirect');
    expect($this->handler->cartUrls([]))->toBe([Provider::class => 'https://example.test/?tsharp_cart=1'])
        ->and($this->handler->checkoutUrls(['x' => 'y']))->toBe(['x' => 'y', Provider::class => 'https://example.test/checkout/'])
        ->and($this->handler->cartUrls('nope'))->toBe('nope')
        ->and($this->handler->checkoutUrls('nope'))->toBe('nope');
});

it('supplies the provider fields template to the tickets form', function (): void {
    expect($this->handler->fieldsTemplate(false, ['v2', 'tickets', 'commerce', 'fields', 'tickets-sharp']))->toBe(TICKETS_SHARP_DIR . 'views/tickets-form-fields.php')
        ->and($this->handler->fieldsTemplate('/x.php', 'v2/tickets/commerce/fields/tribe-commerce'))->toBe('/x.php')
        ->and($this->handler->fieldsTemplate('/x.php', null))->toBe('/x.php')
        ->and(is_readable(TICKETS_SHARP_DIR . 'views/tickets-form-fields.php'))->toBeTrue();
});

it('does nothing on other requests', function (): void {
    Functions\expect('wp_safe_redirect')->never();

    $this->handler->maybeHandle();
});
