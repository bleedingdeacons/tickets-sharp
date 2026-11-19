<?php

declare(strict_types=1);

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use TicketsSharp\Attendees\DetailsPage;
use TicketsSharp\Attendees\FieldSet;
use TicketsSharp\Commerce\Urls;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Support\Keys;

beforeEach(function (): void {
    $this->shop = Mockery::mock(Shop::class);
    $this->urls = Mockery::mock(Urls::class);
    $this->cart = new WC_Cart();
    $this->notices = [];

    $this->shop->shouldReceive('cart')->andReturn($this->cart)->byDefault();
    $this->shop->shouldReceive('checkoutUrl')->andReturn('https://example.test/checkout/');
    $this->shop->shouldReceive('cartUrl')->andReturn('https://example.test/cart/');
    $this->shop->shouldReceive('notice')->andReturnUsing(function (string $message): void {
        $this->notices[] = $message;
    });
    $this->urls->shouldReceive('detailsPage')->andReturn('https://example.test/attendee-details/')->byDefault();

    WpState::$postMeta[5][Keys::PRODUCT_FIELDS] = FieldSet::fromText("Name | name | required\nEmail | email\nDiet | select | optional | Meat, Veg\nNotes | textarea\nAgree | checkbox\nPhone | tel");
    WpState::addPost(5, ['post_title' => 'Adult']);
    $this->cart->cart_contents = [
        'k1' => ['product_id' => 5, 'quantity' => 2, Keys::CART_EVENT => 10],
        'k2' => ['product_id' => 6, 'quantity' => 1, Keys::CART_EVENT => 10],
        'k3' => ['product_id' => 7, 'quantity' => 1],
    ];

    $this->page = new DetailsPage($this->shop, $this->urls);
});

it('registers the shortcode and the cart hooks', function (): void {
    $this->page->register();

    expect(WpState::$shortcodes)->toHaveKey(DetailsPage::SHORTCODE);
    $this->assertActionAdded('woocommerce_checkout_create_order_line_item');
    $this->assertActionAdded('woocommerce_check_cart_items');
    $this->assertFilterAdded('woocommerce_get_item_data');
    $this->assertActionAdded('woocommerce_remove_cart_item');
    $this->assertActionAdded('woocommerce_add_to_cart');
    $this->assertActionAdded('woocommerce_cart_emptied');
});

it('finds the tickets that ask for attendee details', function (): void {
    $items = $this->page->itemsNeedingFields();

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray(['key' => 'k1', 'product_id' => 5, 'name' => 'Adult', 'quantity' => 2, 'answers' => []])
        ->and($this->page->isIncomplete())->toBeTrue();
});

it('has nothing to ask without a cart', function (): void {
    $this->shop->shouldReceive('cart')->andReturn(null);

    expect($this->page->itemsNeedingFields())->toBe([])
        ->and($this->page->save([]))->toBe(['Your basket could not be found. Please try again.']);
});

it('stores valid answers in the cart', function (): void {
    $errors = $this->page->save(['k1' => [['name' => 'Ann', 'diet' => 'Veg'], ['name' => 'Bo']]]);

    expect($errors)->toBe([])
        ->and($this->cart->cart_contents['k1'][Keys::CART_ATTENDEES][0])->toMatchArray(['name' => 'Ann', 'diet' => 'Veg'])
        ->and($this->cart->sessionSaves)->toBe(1)
        ->and($this->page->isIncomplete())->toBeFalse();
});

it('reports which attendee is missing what', function (): void {
    $errors = $this->page->save(['k1' => [['name' => 'Ann', 'email' => 'nope']]]);

    expect($errors)->toBe([
        'Adult, attendee 1: Email must be an email address.',
        'Adult, attendee 2: Name is required.',
    ])->and($this->page->isIncomplete())->toBeTrue();
});

it('asks again when the quantity grows', function (): void {
    $this->page->save(['k1' => [['name' => 'Ann'], ['name' => 'Bo']]]);
    $this->cart->cart_contents['k1']['quantity'] = 3;

    expect($this->page->isIncomplete())->toBeTrue();
});

it('keeps the answers of attendees left out when the quantity went down', function (): void {
    $this->cart->cart_contents['k1'][Keys::CART_ATTENDEES] = [['name' => 'Ann'], ['name' => 'Bo'], ['name' => 'Cy']];
    $this->cart->cart_contents['k1']['quantity'] = 1;

    $this->page->save(['k1' => [['name' => 'Annie']]]);
    $this->cart->cart_contents['k1']['quantity'] = 3;

    $answers = $this->cart->cart_contents['k1'][Keys::CART_ATTENDEES];

    expect($answers)->toHaveCount(3)
        ->and($answers[0])->toMatchArray(['name' => 'Annie'])
        ->and(array_slice($answers, 1))->toBe([['name' => 'Bo'], ['name' => 'Cy']])
        ->and($this->page->isIncomplete())->toBeFalse();
});

it('sets a removed ticket\'s answers aside and gives them back when it returns', function (): void {
    $session = [];
    $this->shop->shouldReceive('sessionGet')->andReturnUsing(static function (string $key) use (&$session) {
        return $session[$key] ?? null;
    });
    $this->shop->shouldReceive('sessionSet')->andReturnUsing(static function (string $key, $value) use (&$session): void {
        $session[$key] = $value;
    });
    $this->cart->cart_contents['k1'][Keys::CART_ATTENDEES] = [['name' => 'Ann'], ['name' => 'Bo']];

    $this->page->setAside('k1', $this->cart);
    unset($this->cart->cart_contents['k1']);
    $this->page->setAside('k2', $this->cart);
    $this->page->setAside('gone', $this->cart);

    expect($session[Keys::SESSION_SET_ASIDE])->toBe([5 => [['name' => 'Ann'], ['name' => 'Bo']]]);

    $this->cart->cart_contents['new'] = ['product_id' => 5, 'quantity' => 1, Keys::CART_EVENT => 10];
    $this->page->restore('new', 5);

    expect($this->cart->cart_contents['new'][Keys::CART_ATTENDEES])->toBe([['name' => 'Ann'], ['name' => 'Bo']])
        ->and($session[Keys::SESSION_SET_ASIDE])->toBeNull();
});

it('does not overwrite answers a returning ticket already has, and forgets them at checkout', function (): void {
    $session = [Keys::SESSION_SET_ASIDE => [5 => [['name' => 'Old']], 6 => [['name' => 'Six']]]];
    $this->shop->shouldReceive('sessionGet')->andReturnUsing(static function (string $key) use (&$session) {
        return $session[$key] ?? null;
    });
    $this->shop->shouldReceive('sessionSet')->andReturnUsing(static function (string $key, $value) use (&$session): void {
        $session[$key] = $value;
    });
    $this->cart->cart_contents['k1'][Keys::CART_ATTENDEES] = [['name' => 'New']];

    $this->page->restore('k1', 5);
    $this->page->restore('k3', 7);
    $this->page->restore('missing', 6);

    expect($this->cart->cart_contents['k1'][Keys::CART_ATTENDEES])->toBe([['name' => 'New']])
        ->and($session[Keys::SESSION_SET_ASIDE])->toBe([6 => [['name' => 'Six']]]);

    $this->page->forgetSetAside();

    expect($session[Keys::SESSION_SET_ASIDE])->toBeNull();
});

it('goes to checkout after a valid post', function (): void {
    Functions\when('wp_verify_nonce')->justReturn(1);

    expect($this->page->handlePost([DetailsPage::NONCE => 'n', DetailsPage::INPUT => ['k1' => [['name' => 'Ann'], ['name' => 'Bo']]]]))
        ->toBe('https://example.test/checkout/');
});

it('comes back with notices after an invalid post', function (): void {
    Functions\when('wp_verify_nonce')->justReturn(1);

    expect($this->page->handlePost([DetailsPage::NONCE => 'n', DetailsPage::INPUT => 'nope']))->toBe('https://example.test/attendee-details/')
        ->and($this->notices)->toHaveCount(2);
});

it('refuses an expired form', function (): void {
    Functions\when('wp_verify_nonce')->justReturn(false);
    $this->urls->shouldReceive('detailsPage')->andReturn('');

    expect($this->page->handlePost([]))->toBe('https://example.test/cart/')
        ->and($this->notices)->toBe(['That form has expired. Please enter the attendee details again.']);
});

it('does nothing unless its form was posted', function (): void {
    Functions\expect('wp_safe_redirect')->never();

    $this->page->maybeSave();
});

it('renders a fieldset per attendee with every field type', function (): void {
    $this->page->save(['k1' => [['name' => 'Ann <x>', 'diet' => 'Veg', 'agree' => '1', 'notes' => 'Hi']]]);

    $html = $this->page->render();

    expect(array_column(WpState::$enqueued, 'handle'))->toContain('tickets-sharp-attendees')
        ->and(is_readable(TICKETS_SHARP_DIR . 'assets/css/attendees.css'))->toBeTrue()
        ->and($html)->toContain('<p class="tsharp-attendees__submit">');

    expect($html)->toContain('<form class="tsharp-attendees"')
        ->toContain('name="tsharp_attendees" value="nonce-tsharp_attendees"')
        ->toContain('<h3>Adult</h3>')
        ->toContain('<legend>Attendee 1</legend>')
        ->toContain('<legend>Attendee 2</legend>')
        ->toContain('name="tsharp_attendee[k1][0][name]" value="Ann"')
        ->toContain('autocomplete="name" required')
        ->toContain('type="email"')
        ->toContain('<option value="Veg" selected>Veg</option>')
        ->toContain('<textarea id="tsharp-k1-0-notes" name="tsharp_attendee[k1][0][notes]">Hi</textarea>')
        ->toContain('value="1" checked>')
        ->toContain('type="tel"')
        ->not->toContain('<x>');
});

it('renders a way on when nothing needs details', function (): void {
    $this->cart->cart_contents = [];

    expect($this->page->render())->toContain('None of the tickets in your basket need attendee details.')
        ->toContain('https://example.test/checkout/');
});

it('stops checkout until details are complete', function (): void {
    $this->page->checkCart();
    $errors = Mockery::mock(WP_Error::class);
    $errors->shouldReceive('add')->once()->with('tsharp_attendee_details', 'Some tickets in your basket still need attendee details. Enter attendee details');
    $this->page->storeApiErrors($errors);

    expect($this->notices[0])->toContain('href="https://example.test/attendee-details/"');

    $this->cart->cart_contents = [];
    $this->notices = [];
    $this->page->checkCart();
    $this->page->storeApiErrors('nope');
    expect($this->notices)->toBe([]);
});

it('omits the link when there is no details page', function (): void {
    $this->urls->shouldReceive('detailsPage')->andReturn('');
    $this->page->checkCart();

    expect($this->notices)->toBe(['Some tickets in your basket still need attendee details.']);
});

it('copies the event and answers onto the order line', function (): void {
    $item = new WC_Order_Item_Product(1);

    $this->page->copyToOrderItem($item, 'k1', [Keys::CART_EVENT => '10', Keys::CART_ATTENDEES => [['name' => 'Ann', 3 => 'x'], 'bad']]);
    $this->page->copyToOrderItem('not an item', 'k1', []);

    expect($item->meta)->toBe([Keys::ITEM_EVENT => 10, Keys::ITEM_ATTENDEES => [['name' => 'Ann'], []]]);
});

it('copies only the attendees bought, not answers kept from a higher quantity', function (): void {
    $item = new WC_Order_Item_Product(1);

    $this->page->copyToOrderItem($item, 'k1', ['quantity' => 1, Keys::CART_ATTENDEES => [['name' => 'Ann'], ['name' => 'Bo']]]);

    expect($item->meta)->toBe([Keys::ITEM_ATTENDEES => [['name' => 'Ann']]])
        ->and($this->page->itemData([], ['product_id' => 5, 'quantity' => 1, Keys::CART_ATTENDEES => [['name' => 'Ann'], ['name' => 'Bo']]]))
        ->toBe([['key' => 'Attendees', 'value' => 'Ann']]);
});

it('shows attendee names in the cart', function (): void {
    $data = $this->page->itemData([], ['product_id' => 5, Keys::CART_ATTENDEES => [['name' => 'Ann'], ['name' => '']]]);

    expect($data)->toBe([['key' => 'Attendees', 'value' => 'Ann']])
        ->and($this->page->itemData([], ['product_id' => 5]))->toBe([])
        ->and($this->page->itemData([], ['product_id' => 5, Keys::CART_ATTENDEES => [['name' => '']]]))->toBe([])
        ->and($this->page->itemData('x', []))->toBe('x');
});
