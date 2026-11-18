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

it('sends paid tickets once, grouped by event', function (): void {
    $order = Make::order(900);
    $this->provider->shouldReceive('get_attendees_by_order_id')->with(900)->andReturn([
        ['attendee_id' => 1, 'event_id' => 10, 'order_status' => 'completed'],
        ['attendee_id' => 2, 'event_id' => 11, 'order_status' => 'completed'],
        ['attendee_id' => 3, 'event_id' => 10, 'order_status' => 'refunded'],
        ['attendee_id' => 4, 'event_id' => 'x', 'order_status' => null],
    ]);
    $this->provider->shouldReceive('send_tickets_email_for_attendees')->once()
        ->with([['attendee_id' => 1, 'event_id' => 10, 'order_status' => 'completed']], ['post_id' => 10, 'order_id' => 900, 'send_purchaser_all' => true])
        ->andReturn(true);
    $this->provider->shouldReceive('send_tickets_email_for_attendees')->once()
        ->with(Mockery::any(), Mockery::on(static fn (array $args): bool => $args['post_id'] === 11))
        ->andReturn(false);
    Actions\expectDone('tickets_sharp/tickets_sent')->once()->with(900);

    expect($this->mailer->sendForOrder($order))->toBeTrue()
        ->and($order->get_meta(Keys::ORDER_TICKETS_SENT))->toBe('yes')
        ->and($this->mailer->sendForOrder($order))->toBeFalse();
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
