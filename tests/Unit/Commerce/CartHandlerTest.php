<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
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
    ])))->toBe(['event' => 10, 'tickets' => [5 => 3, 6 => 0, 7 => 1000]]);
});

function basketPost(int $event, array $tickets): array
{
    return formPost($event, $tickets) + ['tsharp_basket' => '1'];
}

function basketLine(WC_Cart $cart, int $productId, int $quantity, array $answers = []): string
{
    $key = 'line-' . $productId;
    $cart->cart_contents[$key] = ['product_id' => $productId, 'quantity' => $quantity, Keys::CART_EVENT => 10]
        + ($answers !== [] ? [Keys::CART_ATTENDEES => $answers] : []);

    return $key;
}

it('reports what the basket holds for one event', function (): void {
    basketLine($this->cart, 5, 2);
    $this->cart->cart_contents['other'] = ['product_id' => 8, 'quantity' => 1, Keys::CART_EVENT => 11];
    $this->cart->cart_contents['plain'] = ['product_id' => 9, 'quantity' => 4];

    expect($this->handler->basketFor(10))->toBe([5 => 2])
        ->and($this->handler->basketFor(11))->toBe([8 => 1])
        ->and($this->handler->basketFor(0))->toBe([]);
});

it('answers the basket request with the event\'s tickets', function (): void {
    basketLine($this->cart, 5, 2);
    $_POST['event'] = '10';
    Functions\expect('wp_send_json_success')->once()->with(Mockery::on(
        static fn ($data): bool => $data['tickets'] == (object) [5 => 2]
    ));

    $this->handler->basketResponse();
    unset($_POST['event']);
});

it('adds attendees when a basket quantity goes up, keeping those already entered', function (): void {
    $key = basketLine($this->cart, 5, 2, [['name' => 'Ann'], ['name' => 'Bob']]);
    $this->details->shouldReceive('itemsNeedingFields')->with($this->cart)->andReturn([['key' => $key]]);
    Actions\expectDone('tickets_sharp/basket_changed')->once()->with(10, [5 => 3]);

    $url = $this->handler->handle(basketPost(10, [['ticket_id' => 5, 'quantity' => 3], ['ticket_id' => 6, 'quantity' => 0]]));

    expect($url)->toBe('https://example.test/attendee-details/')
        ->and($this->cart->cart_contents[$key]['quantity'])->toBe(3)
        ->and($this->cart->cart_contents[$key][Keys::CART_ATTENDEES])->toBe([['name' => 'Ann'], ['name' => 'Bob']])
        ->and($this->cart->added)->toBe([])
        ->and($this->cart->sessionSaves)->toBe(1);
});

it('leaves out the last attendees when a basket quantity goes down, keeping their answers', function (): void {
    $key = basketLine($this->cart, 5, 3, [['name' => 'Ann'], ['name' => 'Bob'], ['name' => 'Cat']]);
    $this->details->shouldReceive('itemsNeedingFields')->andReturn([['key' => $key]]);

    expect($this->handler->handle(basketPost(10, [['ticket_id' => 5, 'quantity' => 1]])))->toBe('https://example.test/attendee-details/')
        ->and($this->cart->cart_contents[$key]['quantity'])->toBe(1)
        ->and($this->cart->cart_contents[$key][Keys::CART_ATTENDEES])->toBe([['name' => 'Ann'], ['name' => 'Bob'], ['name' => 'Cat']]);
});

it('takes a ticket out of the basket at zero and adds one that was not there', function (): void {
    basketLine($this->cart, 5, 2);
    $this->details->shouldReceive('itemsNeedingFields')->andReturn([]);
    Actions\expectDone('tickets_sharp/basket_changed')->once()->with(10, [5 => 0, 6 => 1]);

    expect($this->handler->handle(basketPost(10, [['ticket_id' => 5, 'quantity' => 0], ['ticket_id' => 6, 'quantity' => 1]])))->toBe('https://example.test/checkout/')
        ->and($this->cart->cart_contents)->not->toHaveKey('line-5')
        ->and($this->cart->added)->toBe([[6, 1, [Keys::CART_EVENT => 10]]]);
});

it('keeps a basket quantity that capacity refuses', function (): void {
    $key = basketLine($this->cart, 5, 2);
    $this->details->shouldReceive('itemsNeedingFields')->andReturn([]);
    Filters\expectApplied('woocommerce_update_cart_validation')->once()->with(true, $key, Mockery::type('array'), 9)->andReturn(false);
    Actions\expectDone('tickets_sharp/basket_changed')->never();

    $this->handler->handle(basketPost(10, [['ticket_id' => 5, 'quantity' => 9]]));

    expect($this->cart->cart_contents[$key]['quantity'])->toBe(2);
});

it('goes back to the event once nothing of it is left in the basket', function (): void {
    basketLine($this->cart, 5, 1);
    $this->details->shouldReceive('itemsNeedingFields')->never();

    expect($this->handler->handle(basketPost(10, [['ticket_id' => 5, 'quantity' => 0], ['ticket_id' => 99, 'quantity' => 4]])))->toBe('https://example.test/?p=10')
        ->and($this->cart->cart_contents)->toBe([]);
});

it('adds rather than replaces when the form was not filled from the basket', function (): void {
    basketLine($this->cart, 5, 2);

    $this->handler->handle(formPost(10, [['ticket_id' => 5, 'quantity' => 1], ['ticket_id' => 6, 'quantity' => 0]]));

    expect($this->cart->added)->toBe([[5, 1, [Keys::CART_EVENT => 10]]])
        ->and($this->cart->cart_contents['line-5']['quantity'])->toBe(2);
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
    $this->assertActionAdded('wp_ajax_tsharp_basket');
    $this->assertActionAdded('wp_ajax_nopriv_tsharp_basket');
    $this->assertFilterAdded('tribe_template_path_list');
    $this->assertFilterAdded('tribe_template_file');
    expect($this->handler->cartUrls([]))->toBe([Provider::class => 'https://example.test/?tsharp_cart=1'])
        ->and($this->handler->checkoutUrls(['x' => 'y']))->toBe(['x' => 'y', Provider::class => 'https://example.test/checkout/'])
        ->and($this->handler->cartUrls('nope'))->toBe('nope')
        ->and($this->handler->checkoutUrls('nope'))->toBe('nope');
});

it('adds its template folder, where Event Tickets finds the provider fields', function (): void {
    $folders = $this->handler->templateFolders(['tickets' => ['id' => 'tickets', 'priority' => 20, 'path' => '/et/src/views']]);
    $folder = $folders['tickets-sharp'];

    expect($folders)->toHaveKey('tickets')
        ->and($folder['priority'])->toBe(25)
        // Event Tickets joins the folder and the template name, then adds .php.
        ->and(is_readable($folder['path'] . '/' . CartHandler::FIELDS_TEMPLATE . '.php'))->toBeTrue()
        ->and($this->handler->templateFolders('nope'))->toBe('nope');
});

it('loads the basket script with its fields, whichever file supplies them', function (): void {
    Functions\expect('wp_enqueue_script')->once()->with('tickets-sharp-basket', Mockery::type('string'), ['jquery'], Mockery::any(), true);

    expect($this->handler->fieldsTemplate('/theme/tickets-sharp.php', ['v2', 'tickets', 'commerce', 'fields', 'tickets-sharp']))->toBe('/theme/tickets-sharp.php')
        ->and($this->handler->fieldsTemplate('/x.php', 'v2/tickets/commerce/fields/tribe-commerce'))->toBe('/x.php')
        ->and($this->handler->fieldsTemplate('/x.php', null))->toBe('/x.php');
});

it('does nothing on other requests', function (): void {
    Functions\expect('wp_safe_redirect')->never();

    $this->handler->maybeHandle();
});
