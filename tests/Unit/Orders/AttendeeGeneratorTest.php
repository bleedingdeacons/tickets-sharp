<?php

declare(strict_types=1);

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Actions;
use TicketsSharp\Attendees\AttendeeStore;
use TicketsSharp\Attendees\FieldSet;
use TicketsSharp\Commerce\TicketProducts;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Orders\AttendeeGenerator;
use TicketsSharp\Support\Keys;
use TicketsSharp\Tests\Support\Make;

beforeEach(function (): void {
    $this->store = Mockery::mock(AttendeeStore::class);
    $this->shop = Mockery::mock(Shop::class);
    $this->products = Mockery::mock(TicketProducts::class);
    $this->products->shouldReceive('eventFor')->andReturnUsing(static fn (int $id): int => $id === 5 ? 10 : 0);
    $this->created = [];
    $this->store->shouldReceive('create')->andReturnUsing(function (array $data): int {
        $this->created[] = $data;

        return 700 + count($this->created);
    })->byDefault();

    $this->generator = new AttendeeGenerator($this->store, $this->products, $this->shop);
});

it('hooks both checkouts', function (): void {
    $this->generator->register();

    $this->assertActionAdded('woocommerce_checkout_order_processed');
    $this->assertActionAdded('woocommerce_store_api_checkout_order_processed');
});

it('creates one attendee per ticket bought, from the billing details', function (): void {
    $order = Make::order(900, [], [Make::item(1, 5, 2, '25.00'), Make::item(2, 99, 1)]);
    Actions\expectDone('tickets_sharp/attendees_generated')->once()->with(900, [701, 702]);
    Actions\expectDone('tickets_sharp/attendees_changed')->once()->with([10]);

    expect($this->generator->generate($order))->toBe([701, 702])
        ->and($this->created)->toHaveCount(2)
        ->and($this->created[0])->toMatchArray([
            'event_id' => 10,
            'product_id' => 5,
            'order_id' => 900,
            'order_item_id' => 1,
            'status' => 'pending',
            'full_name' => 'Pat Buyer',
            'email' => 'pat@example.test',
            'purchaser_email' => 'pat@example.test',
            'user_id' => 3,
            'price_paid' => '12.50',
            'currency' => 'GBP',
            'fields' => [],
        ])
        ->and($this->created[0]['security_code'])->toMatch('/^[0-9a-f]{10}$/')
        ->and($this->created[0]['security_code'])->not->toBe($this->created[1]['security_code'])
        ->and($order->get_meta(Keys::ORDER_ATTENDEES_GENERATED))->toBe('yes')
        ->and($order->saves)->toBe(1);
});

it('names attendees from their answers', function (): void {
    WpState::$postMeta[5][Keys::PRODUCT_FIELDS] = FieldSet::fromText("Name | name\nEmail | email\nDiet | text");
    $answers = [['name' => 'Ann', 'email' => 'ann@example.test', 'diet' => 'Veg'], ['name' => '', 'email' => '', 'diet' => '']];
    $order = Make::order(900, ['status' => 'processing'], [Make::item(1, 5, 2, '20.00', [Keys::ITEM_ATTENDEES => $answers])]);

    $this->generator->generate($order);

    expect($this->created[0])->toMatchArray(['full_name' => 'Ann', 'email' => 'ann@example.test', 'fields' => $answers[0], 'status' => 'processing'])
        ->and($this->created[1])->toMatchArray(['full_name' => 'Pat Buyer', 'email' => 'pat@example.test']);
});

it('does it only once per order', function (): void {
    $order = Make::order(900, [], [Make::item(1, 5, 1)]);
    $order->meta[Keys::ORDER_ATTENDEES_GENERATED] = 'yes';

    expect($this->generator->generate($order))->toBe([])->and($this->created)->toBe([]);
});

it('skips draft orders from the block checkout', function (): void {
    expect($this->generator->generate(Make::order(900, ['status' => 'checkout-draft'], [Make::item(1, 5, 1)])))->toBe([]);
});

it('marks an order without tickets as done, quietly', function (): void {
    Actions\expectDone('tickets_sharp/attendees_changed')->never();
    $order = Make::order(900, [], [Make::item(1, 99, 1), new WC_Order_Item()]);

    expect($this->generator->generate($order))->toBe([])
        ->and($order->get_meta(Keys::ORDER_ATTENDEES_GENERATED))->toBe('yes');
});

it('skips an attendee the store could not create', function (): void {
    $this->store->shouldReceive('create')->andReturn(0);

    expect($this->generator->generate(Make::order(900, [], [Make::item(1, 5, 1)])))->toBe([]);
});

it('generates from either checkout entry point', function (): void {
    $order = Make::order(900, [], [Make::item(1, 5, 1)]);
    $this->shop->shouldReceive('order')->with(900)->andReturn($order);

    $this->generator->onCheckout(900);
    $this->generator->onStoreApi(Make::order(901, [], [Make::item(2, 5, 1)]));
    $this->generator->onStoreApi('not an order');
    $this->generator->onCheckout(1, [], Make::order(902));

    expect(array_column($this->created, 'order_id'))->toBe([900, 901]);
});

it('ignores a checkout whose order cannot be found', function (): void {
    $this->shop->shouldReceive('order')->with(0)->andReturn(null);

    $this->generator->onCheckout('x');

    expect($this->created)->toBe([]);
});

it('makes ten-character security codes', function (): void {
    expect(AttendeeGenerator::securityCode())->toMatch('/^[0-9a-f]{10}$/');
});
