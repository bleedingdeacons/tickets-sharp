<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Orders\Mailer;
use TicketsSharp\Orders\StatusMap;
use TicketsSharp\Support\Keys;
use TicketsSharp\Support\Settings;
use TicketsSharp\Tests\Support\Make;

beforeEach(function (): void {
    $this->provider = Mockery::mock(Provider::class);
    $this->mailer = new Mailer($this->provider, new StatusMap(new Settings()));
});

it('sends each paid ticket as its own e-mail, even when attendees share an address', function (): void {
    $order = Make::order(900);
    $paid = [
        ['attendee_id' => 1, 'event_id' => 10, 'order_status' => 'completed', 'holder_email' => 'pat@example.test'],
        ['attendee_id' => 2, 'event_id' => 10, 'order_status' => 'completed', 'holder_email' => 'pat@example.test'],
        ['attendee_id' => 3, 'event_id' => '11', 'order_status' => 'processing', 'holder_email' => 'ann@example.test'],
    ];
    $this->provider->shouldReceive('get_attendees_by_order_id')->with(900)->andReturn([
        ...$paid,
        ['attendee_id' => 4, 'event_id' => 10, 'order_status' => 'refunded'],
        ['attendee_id' => 5, 'event_id' => 'x', 'order_status' => null],
    ]);
    $sends = [];
    $this->provider->shouldReceive('send_tickets_email_for_attendees')->times(3)
        ->andReturnUsing(function (array $attendees, array $args) use (&$sends): bool {
            $sends[] = [$attendees, $args];

            return true;
        });
    Actions\expectDone('tickets_sharp/tickets_sent')->once()->with(900);

    expect($this->mailer->sendForOrder($order))->toBeTrue()
        ->and($sends)->toBe([
            [[$paid[0]], ['post_id' => 10, 'order_id' => 900, 'send_purchaser_all' => false]],
            [[$paid[1]], ['post_id' => 10, 'order_id' => 900, 'send_purchaser_all' => false]],
            [[$paid[2]], ['post_id' => 11, 'order_id' => 900, 'send_purchaser_all' => false]],
        ])
        ->and($order->get_meta(Keys::ORDER_TICKETS_SENT))->toBe('yes')
        ->and($this->mailer->sendForOrder($order))->toBeFalse();
});

it('counts the order as sent when any ticket went out', function (): void {
    $order = Make::order(900);
    $this->provider->shouldReceive('get_attendees_by_order_id')->andReturn([
        ['event_id' => 10, 'order_status' => 'completed'],
        ['event_id' => 10, 'order_status' => 'completed'],
    ]);
    $this->provider->shouldReceive('send_tickets_email_for_attendees')->twice()->andReturn(false, true);

    expect($this->mailer->sendForOrder($order))->toBeTrue();
});

it('sends again when forced', function (): void {
    $order = Make::order(900);
    $order->meta[Keys::ORDER_TICKETS_SENT] = 'yes';
    $this->provider->shouldReceive('get_attendees_by_order_id')->andReturn([['event_id' => 10, 'order_status' => 'processing']]);
    $this->provider->shouldReceive('send_tickets_email_for_attendees')->once()->andReturn(true);

    expect($this->mailer->sendForOrder($order, true))->toBeTrue();
});

it('sends nothing without paid tickets', function (): void {
    $order = Make::order(900);
    $this->provider->shouldReceive('get_attendees_by_order_id')->andReturn([['event_id' => 10, 'order_status' => 'pending']]);
    $this->provider->shouldReceive('send_tickets_email_for_attendees')->never();

    expect($this->mailer->sendForOrder($order))->toBeFalse()
        ->and($order->get_meta(Keys::ORDER_TICKETS_SENT))->toBe('');
});

it('does not mark the order when nothing was sent', function (): void {
    $order = Make::order(900);
    $this->provider->shouldReceive('get_attendees_by_order_id')->andReturn([['event_id' => 10, 'order_status' => 'completed']]);
    $this->provider->shouldReceive('send_tickets_email_for_attendees')->andReturn(false);

    expect($this->mailer->sendForOrder($order))->toBeFalse()
        ->and($order->saves)->toBe(0);
});
