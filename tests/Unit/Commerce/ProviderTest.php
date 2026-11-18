<?php

declare(strict_types=1);

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Support\Keys;
use TicketsSharp\Tests\Support\Make;
use TicketsSharp\Tests\Support\TestPost;

it('registers itself with Event Tickets when constructed', function (): void {
    Make::provider();

    expect(Tribe__Tickets__Tickets::modules())->toBe([Provider::class => 'WooCommerce']);
    $this->assertActionAdded('init');
});

it('translates its name on init', function (): void {
    $provider = Make::provider()['provider'];
    $provider->translateName();

    expect($provider->plugin_name)->toBe('WooCommerce')
        ->and(Tribe__Tickets__Tickets::modules()[Provider::class])->toBe('WooCommerce');
});

it('declares the names Event Tickets reads from a provider', function (): void {
    $provider = Make::provider()['provider'];

    expect($provider->ticket_object)->toBe('product')
        ->and($provider->attendee_object)->toBe(Keys::ATTENDEE_POST_TYPE)
        ->and($provider->orm_provider)->toBe(Keys::ORM_PROVIDER)
        ->and($provider->event_key)->toBe(Keys::TICKET_EVENT)
        ->and($provider->checkin_key)->toBe(Keys::ATTENDEE_CHECKIN)
        ->and(Provider::ATTENDEE_EVENT_KEY)->toBe(Keys::ATTENDEE_EVENT)
        ->and(Provider::ORDER_OBJECT)->toBe('shop_order');
});

it('resolves its instance from the container', function (): void {
    $provider = Make::provider()['provider'];
    tribe_singleton(Provider::class, $provider);

    expect(Provider::get_instance())->toBe($provider);
});

it('maps Event Tickets key names', function (): void {
    expect(Provider::get_key('attendee_optout_key'))->toBe(Keys::ATTENDEE_OPTOUT)
        ->and(Provider::get_key('ORDER_KEY'))->toBe(Keys::ATTENDEE_ORDER)
        ->and(Provider::get_key('nonsense'))->toBe('')
        ->and(Provider::get_key(null))->toBe('');
});

describe('get_ticket', function (): void {
    it('returns null for a product that is not a ticket', function (): void {
        $parts = Make::provider();
        $parts['products']->shouldReceive('eventFor')->with(5)->andReturn(0);

        expect($parts['provider']->get_ticket(1, 5))->toBeNull();
    });

    it('returns null when the product is gone', function (): void {
        $parts = Make::provider();
        $parts['products']->shouldReceive('eventFor')->andReturn(10);
        $parts['shop']->shouldReceive('product')->with(5)->andReturn(null);

        expect($parts['provider']->get_ticket(10, '5'))->toBeNull();
    });

    it('builds the ticket from the product, its meta and the attendees', function (): void {
        $parts = Make::provider();
        $parts['products']->shouldReceive('eventFor')->with(5)->andReturn(10);
        $parts['shop']->shouldReceive('product')->with(5)->andReturn(Make::product(5, ['sku' => 'TS-10-5', 'menu_order' => 2, 'short_description' => 'Entry']));
        $parts['ledger']->shouldReceive('countsForTicket')->with(5)->andReturn(['paid' => 3, 'pending' => 1, 'cancelled' => 2, 'refunded' => 1, 'held' => 4]);
        WpState::$postMeta[5] = [
            '_manage_stock' => 'yes',
            '_stock' => '16',
            '_global_stock_mode' => 'capped',
            '_global_stock_cap' => '20',
            '_ticket_start_date' => '2026-12-01',
            '_ticket_end_time' => '18:00:00',
        ];

        $ticket = $parts['provider']->get_ticket(10, 5);

        expect($ticket)->toBeInstanceOf(Tribe__Tickets__Ticket_Object::class)
            ->and($ticket->ID)->toBe(5)
            ->and($ticket->name)->toBe('Adult')
            ->and($ticket->description)->toBe('Entry')
            ->and($ticket->price)->toBe('10.00')
            ->and($ticket->sku)->toBe('TS-10-5')
            ->and($ticket->menu_order)->toBe(2)
            ->and($ticket->provider_class)->toBe(Provider::class)
            ->and($ticket->start_date)->toBe('2026-12-01')
            ->and($ticket->end_time)->toBe('18:00:00')
            ->and($ticket->manage_stock())->toBeTrue()
            ->and($ticket->stock())->toBe(16)
            ->and($ticket->global_stock_mode())->toBe('capped')
            ->and($ticket->global_stock_cap())->toBe(20)
            ->and($ticket->qty_sold())->toBe(3)
            ->and($ticket->qty_pending())->toBe(1)
            ->and($ticket->qty_cancelled())->toBe(2)
            ->and($ticket->qty_refunded())->toBe(1);
    });

    it('reports unlimited stock when the product does not manage it', function (): void {
        $parts = Make::provider();
        $parts['products']->shouldReceive('eventFor')->andReturn(10);
        $parts['shop']->shouldReceive('product')->andReturn(Make::product(5));
        $parts['ledger']->shouldReceive('countsForTicket')->andReturn(['paid' => 0, 'pending' => 0, 'cancelled' => 0, 'refunded' => 0, 'held' => 0]);

        $ticket = $parts['provider']->get_ticket(10, 5);

        expect($ticket->manage_stock())->toBeFalse()->and($ticket->stock())->toBe(-1);
    });

    it('lets a filter replace the ticket', function (): void {
        $parts = Make::provider();
        $parts['products']->shouldReceive('eventFor')->andReturn(10);
        $parts['shop']->shouldReceive('product')->andReturn(Make::product(5));
        $parts['ledger']->shouldReceive('countsForTicket')->andReturn(['paid' => 0, 'pending' => 0, 'cancelled' => 0, 'refunded' => 0, 'held' => 0]);
        $replacement = new Tribe__Tickets__Ticket_Object();
        Filters\expectApplied('tickets_sharp/ticket')->once()->andReturn($replacement);

        expect($parts['provider']->get_ticket(10, 5))->toBe($replacement);
    });
});

describe('ticket IDs and saving', function (): void {
    it('lists the ticket products of an event', function (): void {
        Functions\expect('get_posts')->once()->with(Mockery::on(
            static fn (array $args): bool => $args['post_type'] === 'product' && $args['meta_query'][0]['value'] === 10
        ))->andReturn([5, '6', 'x']);

        expect(Make::provider()['provider']->get_tickets_ids(10))->toBe([5, 6]);
    });

    it('accepts a post object and refuses a missing one', function (): void {
        Functions\when('get_posts')->justReturn([7]);
        $provider = Make::provider()['provider'];

        expect($provider->get_tickets_ids(TestPost::add(10, 'tribe_events')))->toBe([7])
            ->and($provider->get_tickets_ids(0))->toBe([]);
    });

    it('saves a new ticket as a product and announces it', function (): void {
        $parts = Make::provider();
        $ticket = new Tribe__Tickets__Ticket_Object();
        $parts['products']->shouldReceive('save')->once()->with(10, $ticket, ['tribe-ticket' => []])->andReturn(55);
        Actions\expectDone('event_tickets_after_create_ticket')->once();
        Actions\expectDone('event_tickets_after_save_ticket')->once();
        Actions\expectDone('tickets_sharp/ticket_saved')->once()->with(10, 55, 'create');

        expect($parts['provider']->save_ticket('10', $ticket, ['tribe-ticket' => []]))->toBe(55)
            ->and($parts['provider']->parentCalls[0])->toBe(['save_ticket', '10']);
    });

    it('reports an update for an existing ticket', function (): void {
        $parts = Make::provider();
        $ticket = new Tribe__Tickets__Ticket_Object();
        $ticket->ID = 55;
        $parts['products']->shouldReceive('save')->andReturn(55);
        Actions\expectDone('event_tickets_after_update_ticket')->once();

        expect($parts['provider']->save_ticket(10, $ticket, 'not an array'))->toBe(55);
    });

    it('fails without an event or when the product cannot be saved', function (): void {
        $parts = Make::provider();
        $parts['products']->shouldReceive('save')->andReturn(0);

        expect($parts['provider']->save_ticket(0, new Tribe__Tickets__Ticket_Object()))->toBeFalse()
            ->and($parts['provider']->save_ticket(10, 'not a ticket'))->toBeFalse()
            ->and($parts['provider']->save_ticket(10, new Tribe__Tickets__Ticket_Object()))->toBeFalse();
    });

    it('deletes a ticket by trashing its product', function (): void {
        $parts = Make::provider();
        $parts['products']->shouldReceive('eventFor')->with(5)->andReturn(10);
        $parts['products']->shouldReceive('trash')->once()->with(5)->andReturn(true);

        expect($parts['provider']->delete_ticket(0, 5))->toBeTrue()
            ->and($parts['provider']->parentCalls)->toBe([['delete_ticket', 5]]);
    });
});

describe('attendees', function (): void {
    beforeEach(function (): void {
        TestPost::attendee(80, [
            Keys::ATTENDEE_EVENT => 10,
            Keys::ATTENDEE_PRODUCT => 5,
            Keys::ATTENDEE_ORDER => 900,
            Keys::ATTENDEE_STATUS => 'completed',
            Keys::ATTENDEE_SECURITY_CODE => 'abc123',
            Keys::ATTENDEE_FULL_NAME => 'Ann',
            Keys::ATTENDEE_EMAIL => 'ann@example.test',
            Keys::ATTENDEE_PURCHASER_NAME => 'Pat Buyer',
            Keys::ATTENDEE_PURCHASER_EMAIL => 'pat@example.test',
            Keys::ATTENDEE_CHECKIN => '1',
            Keys::ATTENDEE_FIELDS => ['diet' => 'Veg'],
            Keys::ATTENDEE_USER_ID => 3,
            Keys::ATTENDEE_PRICE_PAID => '10.00',
        ], '2026-07-24 11:00:00');
    });

    function attendeeProvider(): array
    {
        $parts = Make::provider();
        $parts['shop']->shouldReceive('product')->with(5)->andReturn(Make::product(5));
        $parts['ledger']->shouldReceive('createdAt')->andReturn(1785000000);

        return $parts;
    }

    it('describes an attendee the way Event Tickets expects', function (): void {
        $attendee = attendeeProvider()['provider']->get_attendee(80, 10);

        expect($attendee)->toMatchArray([
            'attendee_id' => 80,
            'event_id' => 10,
            'product_id' => 5,
            'ticket_name' => 'Adult',
            'security_code' => 'abc123',
            'qr_ticket_id' => 80,
            'check_in' => true,
            'order_id' => 900,
            'order_status' => 'completed',
            'order_status_label' => 'Completed',
            'holder_name' => 'Ann',
            'holder_email' => 'ann@example.test',
            'purchaser_email' => 'pat@example.test',
            'user_id' => 3,
            'provider' => Provider::class,
            'provider_slug' => Keys::PROVIDER_SLUG,
            'attendee_meta' => ['diet' => 'Veg'],
            'is_purchaser' => false,
            'tsharp_created' => 1785000000,
        ]);
    });

    it('applies Event Tickets\' attendee filter', function (): void {
        Filters\expectApplied('tribe_tickets_attendee_data')->once()->andReturn('not an array');

        expect(attendeeProvider()['provider']->get_attendee(80))->toBeFalse();
    });

    it('names a deleted ticket', function (): void {
        $parts = Make::provider();
        $parts['shop']->shouldReceive('product')->andReturn(null);
        $parts['ledger']->shouldReceive('createdAt')->andReturn(0);

        expect($parts['provider']->get_attendee(WpState::$posts[80])['ticket_name'])->toBe('(deleted ticket)');
    });

    it('refuses anything that is not one of its attendees', function (): void {
        TestPost::add(81, 'post');
        $provider = Make::provider()['provider'];

        expect($provider->get_attendee(81))->toBeFalse()
            ->and($provider->get_attendee(9999))->toBeFalse()
            ->and($provider->get_attendee('x'))->toBeFalse();
    });

    it('routes get_attendees_by_id by what the ID is', function (string $type, string $method, ?int $orderLookup): void {
        $parts = attendeeProvider();
        TestPost::add(200, $type === 'hpos' ? 'post' : $type);
        if ($type === 'hpos') {
            unset(WpState::$postTypes[200]);
        }
        if ($orderLookup !== null) {
            $parts['shop']->shouldReceive('order')->with(200)->andReturn($orderLookup === 1 ? Make::order(200) : null);
        }
        $parts['store']->shouldReceive($method)->once()->with(200)->andReturn([WpState::$posts[80]]);

        $attendees = $parts['provider']->get_attendees_by_id(200);

        expect($attendees)->toHaveCount(1)->and($attendees[0]['attendee_id'])->toBe(80);
    })->with([
        'a ticket' => ['product', 'byTicket', null],
        'a legacy order' => ['shop_order', 'byOrder', null],
        'an HPOS order' => ['hpos', 'byOrder', 1],
        'an event' => ['tribe_events', 'byEvent', null],
    ]);

    it('returns a single attendee by its own ID', function (): void {
        expect(attendeeProvider()['provider']->get_attendees_by_id(80))->toHaveCount(1);
    });

    it('returns nothing for a non-numeric ID', function (): void {
        expect(Make::provider()['provider']->get_attendees_by_id('all'))->toBe([]);
    });

    it('treats an unknown non-post ID as an event when it is not an order', function (): void {
        $parts = attendeeProvider();
        $parts['shop']->shouldReceive('order')->with(300)->andReturn(null);
        $parts['store']->shouldReceive('byEvent')->once()->with(300)->andReturn([]);

        expect($parts['provider']->get_attendees_by_id(300))->toBe([]);
    });

    it('filters an order\'s attendees by ticket when asked', function (): void {
        $parts = attendeeProvider();
        TestPost::attendee(81, [Keys::ATTENDEE_PRODUCT => 6]);
        $parts['store']->shouldReceive('byOrder')->with(900)->andReturn([WpState::$posts[80], WpState::$posts[81], null]);
        $parts['shop']->shouldReceive('product')->with(6)->andReturn(null);

        expect($parts['provider']->get_attendees_by_order_id(900))->toHaveCount(2)
            ->and($parts['provider']->get_attendees_by_order_id(900, 5))->toHaveCount(1);
    });
});

describe('check-in', function (): void {
    it('checks in a paid attendee through Event Tickets', function (): void {
        TestPost::attendee(80, [Keys::ATTENDEE_STATUS => 'completed']);
        $provider = Make::provider()['provider'];

        expect($provider->checkin(80, true))->toBeTrue()
            ->and($provider->parentCalls)->toBe([['checkin', 80]]);
    });

    it('refuses an unpaid attendee', function (): void {
        TestPost::attendee(80, [Keys::ATTENDEE_STATUS => 'refunded']);
        $provider = Make::provider()['provider'];

        expect($provider->checkin(80))->toBeFalse()->and($provider->parentCalls)->toBe([]);
    });

    it('refuses another provider\'s attendee', function (): void {
        TestPost::add(81, 'tec_tc_attendee');
        $provider = Make::provider()['provider'];

        expect($provider->checkin(81))->toBeFalse()
            ->and($provider->uncheckin(81))->toBeFalse()
            ->and($provider->checkin('x'))->toBeFalse();
    });

    it('unchecks one of its own attendees', function (): void {
        TestPost::attendee(80);
        $provider = Make::provider()['provider'];

        expect($provider->uncheckin(80))->toBeTrue()->and($provider->parentCalls)->toBe([['uncheckin', 80]]);
    });
});

describe('prices for Event Tickets\' screens', function (): void {
    it('gives the ticket list a price, so it does not say Free', function (): void {
        $parts = Make::provider();
        $product = Make::product(5, ['price' => '18']);
        $parts['products']->shouldReceive('eventFor')->with(5)->andReturn(10);
        $parts['products']->shouldReceive('eventFor')->with(6)->andReturn(0);
        $parts['shop']->shouldReceive('product')->with(5)->andReturn($product);

        expect($parts['provider']->get_price_value(5))->toBe('18')
            ->and($parts['provider']->get_price_value(TestPost::add(5, 'product')))->toBe('18')
            ->and($parts['provider']->get_price_value($product))->toBe('18')
            ->and($parts['provider']->get_price_value(6))->toBeFalse()
            ->and($parts['provider']->get_price_value('x'))->toBeFalse();
    });

    it('formats the price through WooCommerce', function (): void {
        $parts = Make::provider();
        $parts['products']->shouldReceive('eventFor')->andReturnUsing(static fn (int $id): int => $id === 5 ? 10 : 0);
        $parts['shop']->shouldReceive('product')->with(5)->andReturn(Make::product(5, ['price' => '18']));

        expect($parts['provider']->get_price_html(5))->toContain('£18.00')->toContain('<bdi>')
            ->and($parts['provider']->get_price_html(6))->toBe('');
    });
});

describe('classic ticket form capacity', function (): void {
    beforeEach(function (): void {
        $this->handler = Mockery::mock(Tribe__Tickets__Tickets_Handler::class);
        tribe_singleton('tickets.handler', $this->handler);
    });

    it('prints a capacity field when it is the event\'s provider', function (): void {
        $provider = Make::provider()['provider'];
        $this->handler->shouldReceive('is_correct_provider')->with(10, $provider)->andReturn(true);

        $html = captureOutput(fn () => $provider->do_metabox_capacity_options(10, ''));

        expect($html)->toContain('name="tribe-ticket[capacity]"')
            ->toContain('value=""')
            ->toContain('data-condition-not="Tribe__Tickets__RSVP"')
            ->toContain('Leave blank for unlimited');
    });

    it('shows an existing ticket\'s capacity, blank when unlimited', function (): void {
        $provider = Make::provider()['provider'];
        $this->handler->shouldReceive('is_correct_provider')->with(55, $provider)->andReturn(true);
        Functions\expect('tribe_tickets_get_capacity')->with(55)->andReturn('40', -1);

        expect(captureOutput(fn () => $provider->do_metabox_capacity_options(10, 55)))->toContain('value="40"')
            ->and(captureOutput(fn () => $provider->do_metabox_capacity_options(10, 55)))->toContain('value=""');
    });

    it('prints nothing for another provider\'s event, or without a post', function (): void {
        $provider = Make::provider()['provider'];
        $this->handler->shouldReceive('is_correct_provider')->andReturn(false);

        expect(captureOutput(fn () => $provider->do_metabox_capacity_options(10, '')))->toBe('')
            ->and(captureOutput(fn () => $provider->do_metabox_capacity_options('', '')))->toBe('');
    });
});

it('asks the ledger whether an attendee holds a seat', function (): void {
    $parts = Make::provider();
    $parts['ledger']->shouldReceive('arrayHoldsSeat')->once()->with(['order_status' => 'pending'])->andReturn(true);

    expect($parts['provider']->attendee_decreases_inventory(['order_status' => 'pending']))->toBeTrue();
});

it('describes the order behind an attendee', function (): void {
    $parts = Make::provider();
    $order = Make::order(900, ['date_created' => new WC_DateTime('2026-07-24 10:00:00')]);
    $parts['shop']->shouldReceive('order')->with(900)->andReturn($order);
    $parts['shop']->shouldReceive('order')->with(0)->andReturn(null);

    expect($parts['provider']->get_order_data(900))->toBe([
        'order_id' => 900,
        'purchaser_name' => 'Pat Buyer',
        'purchaser_email' => 'pat@example.test',
        'provider' => Provider::class,
        'provider_slug' => Keys::PROVIDER_SLUG,
        'purchase_time' => '2026-07-24 10:00:00',
    ])->and($parts['provider']->get_order_data('x')['purchaser_email'])->toBe('');
});

describe('front end', function (): void {
    it('renders the tickets block when the post has tickets', function (): void {
        $parts = Make::provider();
        $GLOBALS['post'] = TestPost::add(10, 'tribe_events');
        Functions\when('get_post')->justReturn($GLOBALS['post']);
        Functions\when('get_posts')->justReturn([5]);
        $parts['products']->shouldReceive('eventFor')->andReturn(10);
        $parts['shop']->shouldReceive('product')->andReturn(Make::product(5));
        $parts['ledger']->shouldReceive('countsForTicket')->andReturn(['paid' => 0, 'pending' => 0, 'cancelled' => 0, 'refunded' => 0, 'held' => 0]);

        $parts['provider']->front_end_tickets_form('');

        expect(Tribe__Tickets__Tickets_View::instance()->rendered)->toBe([10]);
    });

    it('renders nothing without a post or tickets', function (): void {
        $provider = Make::provider()['provider'];
        Functions\when('get_post')->justReturn(null);
        $provider->front_end_tickets_form('');

        Functions\when('get_post')->justReturn(TestPost::add(10, 'tribe_events'));
        Functions\when('get_posts')->justReturn([]);
        $provider->front_end_tickets_form('');

        expect(Tribe__Tickets__Tickets_View::instance()->rendered)->toBe([]);
    });

    it('posts the form to the cart handler and checks out through WooCommerce', function (): void {
        $parts = Make::provider();
        $parts['urls']->shouldReceive('cartHandler')->andReturn('https://example.test/?tsharp_cart=1');
        $parts['shop']->shouldReceive('checkoutUrl')->andReturn('https://example.test/checkout/');

        expect($parts['provider']->get_cart_url(10))->toBe('https://example.test/?tsharp_cart=1')
            ->and($parts['provider']->get_checkout_url())->toBe('https://example.test/checkout/')
            ->and($parts['provider']->is_checkout_page())->toBeFalse()
            ->and($parts['provider']->supports_global_stock())->toBeTrue()
            ->and($parts['provider']->get_event_reports_link(10))->toBe('')
            ->and($parts['provider']->get_ticket_reports_link(null, 5))->toBe('');
    });
});

it('sends ticket e-mails as this provider', function (): void {
    $provider = Make::provider()['provider'];

    expect($provider->send_tickets_email_for_attendees([], ['post_id' => 10]))->toBeTrue()
        ->and($provider->parentCalls[0])->toBe(['send_tickets_email_for_attendees', ['provider' => Keys::PROVIDER_SLUG, 'post_id' => 10]]);
});
