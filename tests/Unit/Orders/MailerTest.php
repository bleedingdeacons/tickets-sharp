<?php

declare(strict_types=1);

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Actions;
use TicketsSharp\Attendees\FieldSet;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Orders\Mailer;
use TicketsSharp\Orders\StatusMap;
use TicketsSharp\Support\Keys;
use TicketsSharp\Support\Settings;
use TicketsSharp\Tests\Support\Make;

beforeEach(function (): void {
    $this->provider = Mockery::mock(Provider::class);
    $this->mailer = new Mailer($this->provider, new StatusMap(new Settings()));
    $this->sends = [];
    $this->provider->shouldReceive('send_tickets_email_for_attendee')->andReturnUsing(function (string $to, array $tickets, array $args): bool {
        $this->sends[] = [$to, array_column($tickets, 'attendee_id'), $args['post_id']];

        return true;
    })->byDefault();

    // Ticket 5 requires each attendee's email; ticket 6 only asks for it; ticket 7 asks nothing.
    WpState::$postMeta[5][Keys::PRODUCT_FIELDS] = FieldSet::fromText("Name | name | required\nEmail | email | required");
    WpState::$postMeta[6][Keys::PRODUCT_FIELDS] = FieldSet::fromText("Name | name | required\nEmail | email | optional");
});

function ticketFor(int $id, int $product, string $email, int $event = 10, string $status = 'completed'): array
{
    return ['attendee_id' => $id, 'product_id' => $product, 'event_id' => $event, 'order_status' => $status, 'holder_email' => $email];
}

it('sends the buyer every ticket, and each attendee of a ticket requiring email their own', function (): void {
    $order = Make::order(900);
    $this->provider->shouldReceive('get_attendees_by_order_id')->with(900)->andReturn([
        ticketFor(1, 5, 'pat@example.test'),
        ticketFor(2, 5, 'pat@example.test'),
        ticketFor(3, 5, 'ann@example.test'),
        ticketFor(4, 6, 'bo@example.test'),
        ticketFor(5, 7, 'pat@example.test', 11),
        ticketFor(6, 5, 'cy@example.test', 10, 'refunded'),
    ]);
    Actions\expectDone('tickets_sharp/tickets_sent')->once()->with(900);

    expect($this->mailer->sendForOrder($order))->toBeTrue()
        ->and($this->sends)->toBe([
            ['pat@example.test', [1, 2, 3, 4], 10],
            ['pat@example.test', [1], 10],
            ['pat@example.test', [2], 10],
            ['ann@example.test', [3], 10],
            ['pat@example.test', [5], 11],
        ])
        ->and($order->get_meta(Keys::ORDER_TICKETS_SENT))->toBe('yes')
        ->and($this->mailer->sendForOrder($order))->toBeFalse();
});

it('skips an attendee address that is not an email', function (): void {
    $this->provider->shouldReceive('get_attendees_by_order_id')->andReturn([ticketFor(1, 5, 'not an address'), ticketFor(2, 5, '')]);

    $this->mailer->sendForOrder(Make::order(900));

    expect($this->sends)->toBe([['pat@example.test', [1, 2], 10]]);
});

it('sends each attendee their own ticket when the order has no buyer address', function (): void {
    $this->provider->shouldReceive('get_attendees_by_order_id')->andReturn([ticketFor(1, 7, 'ann@example.test'), ticketFor(2, 7, 'bo@example.test')]);

    $this->mailer->sendForOrder(Make::order(900, ['billing_email' => '']));

    expect($this->sends)->toBe([['ann@example.test', [1], 10], ['bo@example.test', [2], 10]]);
});

it('knows which tickets require each attendee\'s email', function (): void {
    expect($this->mailer->requiresEmail(['product_id' => 5]))->toBeTrue()
        ->and($this->mailer->requiresEmail(['product_id' => '6']))->toBeFalse()
        ->and($this->mailer->requiresEmail(['product_id' => 7]))->toBeFalse()
        ->and($this->mailer->requiresEmail([]))->toBeFalse();
});

it('counts the order as sent when any e-mail went out', function (): void {
    $this->provider->shouldReceive('get_attendees_by_order_id')->andReturn([ticketFor(1, 5, 'ann@example.test')]);
    $this->provider->shouldReceive('send_tickets_email_for_attendee')->twice()->andReturn(false, true);

    expect($this->mailer->sendForOrder(Make::order(900)))->toBeTrue();
});

it('sends again when forced', function (): void {
    $order = Make::order(900);
    $order->meta[Keys::ORDER_TICKETS_SENT] = 'yes';
    $this->provider->shouldReceive('get_attendees_by_order_id')->andReturn([ticketFor(1, 7, 'pat@example.test')]);

    expect($this->mailer->sendForOrder($order, true))->toBeTrue()
        ->and($this->sends)->toHaveCount(1);
});

it('sends nothing without paid tickets', function (): void {
    $order = Make::order(900);
    $this->provider->shouldReceive('get_attendees_by_order_id')->andReturn([ticketFor(1, 5, 'ann@example.test', 10, 'pending')]);

    expect($this->mailer->sendForOrder($order))->toBeFalse()
        ->and($this->sends)->toBe([])
        ->and($order->get_meta(Keys::ORDER_TICKETS_SENT))->toBe('');
});

it('does not mark the order when nothing was sent', function (): void {
    $order = Make::order(900);
    $this->provider->shouldReceive('get_attendees_by_order_id')->andReturn([ticketFor(1, 7, 'pat@example.test')]);
    $this->provider->shouldReceive('send_tickets_email_for_attendee')->andReturn(false);

    expect($this->mailer->sendForOrder($order))->toBeFalse()
        ->and($order->saves)->toBe(0);
});
