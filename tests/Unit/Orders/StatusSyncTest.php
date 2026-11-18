<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use TicketsSharp\Attendees\AttendeeStore;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Orders\AttendeeGenerator;
use TicketsSharp\Orders\Mailer;
use TicketsSharp\Orders\StatusMap;
use TicketsSharp\Orders\StatusSync;
use TicketsSharp\Support\Keys;
use TicketsSharp\Support\Settings;
use TicketsSharp\Tests\Support\Make;
use TicketsSharp\Tests\Support\TestPost;

beforeEach(function (): void {
    $this->store = Mockery::mock(AttendeeStore::class)->makePartial();
    $this->generator = Mockery::mock(AttendeeGenerator::class);
    $this->mailer = Mockery::mock(Mailer::class);
    $this->shop = Mockery::mock(Shop::class);
    $this->sync = new StatusSync($this->store, $this->generator, new StatusMap(new Settings()), $this->mailer, $this->shop);

    $this->a = TestPost::attendee(81, [Keys::ATTENDEE_STATUS => 'pending', Keys::ATTENDEE_EVENT => 10, Keys::ATTENDEE_ORDER_ITEM => 1]);
    $this->b = TestPost::attendee(82, [Keys::ATTENDEE_STATUS => 'refunded', Keys::ATTENDEE_EVENT => 10, Keys::ATTENDEE_ORDER_ITEM => 1]);
    $this->c = TestPost::attendee(83, [Keys::ATTENDEE_STATUS => 'pending', Keys::ATTENDEE_EVENT => 11, Keys::ATTENDEE_ORDER_ITEM => 1, Keys::ATTENDEE_CHECKIN => '1']);
    $this->store->shouldReceive('byOrder')->with(900)->andReturn([$this->a, $this->b, $this->c])->byDefault();
});

it('hooks order status changes and refunds', function (): void {
    $this->sync->register();

    $this->assertActionAdded('woocommerce_order_status_changed');
    $this->assertActionAdded('woocommerce_order_refunded');
});

it('copies a paid status to the attendees and sends the tickets', function (): void {
    $order = Make::order(900, ['status' => 'completed']);
    $this->generator->shouldReceive('generate')->once()->with($order);
    $this->mailer->shouldReceive('sendForOrder')->once()->with($order);
    Actions\expectDone('tickets_sharp/attendees_changed')->once()->with([10, 11]);

    $this->sync->onStatusChanged(900, 'pending', 'completed', $order);

    expect(get_post_meta(81, Keys::ATTENDEE_STATUS, true))->toBe('completed')
        ->and(get_post_meta(82, Keys::ATTENDEE_STATUS, true))->toBe('refunded')
        ->and(get_post_meta(83, Keys::ATTENDEE_STATUS, true))->toBe('completed');
});

it('voids every attendee, refunded or not, when the order is cancelled', function (): void {
    $this->mailer->shouldReceive('sendForOrder')->never();

    $this->sync->apply(Make::order(900), 'cancelled');

    expect(get_post_meta(81, Keys::ATTENDEE_STATUS, true))->toBe('cancelled')
        ->and(get_post_meta(82, Keys::ATTENDEE_STATUS, true))->toBe('cancelled');
});

it('changes nothing for a draft or an unchanged status', function (): void {
    Actions\expectDone('tickets_sharp/attendees_changed')->never();
    $this->mailer->shouldReceive('sendForOrder')->never();

    $this->sync->apply(Make::order(900), 'checkout-draft');
    $this->sync->apply(Make::order(900), 'pending');
});

it('looks the order up when WooCommerce does not pass it', function (): void {
    $order = Make::order(900);
    $this->shop->shouldReceive('order')->with(900)->andReturn($order);
    $this->shop->shouldReceive('order')->with(0)->andReturn(null);
    $this->generator->shouldReceive('generate')->once();

    $this->sync->onStatusChanged('900', 'pending', 'wc-on-hold');
    $this->sync->onStatusChanged('x', 'pending', 'on-hold');
    $this->sync->onStatusChanged(900, 'pending', null, $order);

    expect(get_post_meta(81, Keys::ATTENDEE_STATUS, true))->toBe('on-hold');
});

it('marks refunded seats on a partial refund, sparing those checked in', function (): void {
    $order = Make::order(900, ['qty_refunded' => [1 => -2]], [Make::item(1, 5, 3), Make::item(2, 5, 1), new WC_Order_Item()]);
    $this->shop->shouldReceive('order')->with(900)->andReturn($order);
    $this->store->shouldReceive('byOrderItem')->with(1)->andReturn([$this->a, $this->b, $this->c]);
    Actions\expectDone('tickets_sharp/attendee_refunded')->once()->with(81, 900);
    Actions\expectDone('tickets_sharp/attendees_changed')->once()->with([10]);

    $this->sync->onRefunded(900, 55);

    expect(get_post_meta(81, Keys::ATTENDEE_STATUS, true))->toBe('refunded')
        ->and(get_post_meta(83, Keys::ATTENDEE_STATUS, true))->toBe('pending');
});

it('ignores a refund for an order it cannot find', function (): void {
    $this->shop->shouldReceive('order')->andReturn(null);
    Actions\expectDone('tickets_sharp/attendees_changed')->never();

    $this->sync->onRefunded(900);
});
