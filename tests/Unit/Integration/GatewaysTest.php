<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use TEC\Tickets\QR\Connector;
use TicketsSharp\Commerce\Urls;
use TicketsSharp\Integration\EventTickets;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Support\Keys;
use TicketsSharp\Support\Settings;
use TicketsSharp\Tests\Support\Make;

describe('EventTickets', function (): void {
    beforeEach(function (): void {
        $this->et = new EventTickets();
        tribe_singleton('tickets.handler', new Tribe__Tickets__Tickets_Handler());
    });

    it('reads Event Tickets\' handler keys and capacities', function (): void {
        Functions\expect('tribe_tickets_get_capacity')->with(10)->andReturn('40', null);

        expect($this->et->globalStock(10))->toBeInstanceOf(Tribe__Tickets__Global_Stock::class)
            ->and($this->et->capacityKey())->toBe('_tribe_ticket_capacity')
            ->and($this->et->showDescriptionKey())->toBe('_tribe_ticket_show_description')
            ->and($this->et->eventCapacity(10))->toBe(40)
            ->and($this->et->eventCapacity(10))->toBe(-1);
    });

    it('sets a ticket\'s capacity through Event Tickets', function (): void {
        $ticket = new Tribe__Tickets__Ticket_Object();
        Functions\expect('tribe_tickets_update_capacity')->once()->with($ticket, 25);

        $this->et->setTicketCapacity($ticket, 25);
    });

    it('reads an event\'s provider and clears its caches', function (): void {
        $provider = Mockery::mock(Tribe__Tickets__Tickets::class);
        $provider->shouldReceive('clear_ticket_cache_for_post')->once()->with(10);
        $provider->shouldReceive('clear_attendees_cache')->once()->with(10);

        expect($this->et->eventProvider(10))->toBe('');
        $this->et->clearTicketCaches(10);

        Patchwork\redefine('Tribe__Tickets__Tickets::get_event_ticket_provider', static fn (): string => 'Some\\Provider');
        Patchwork\redefine('Tribe__Tickets__Tickets::get_event_ticket_provider_object', static fn () => $provider);

        expect($this->et->eventProvider(10))->toBe('Some\\Provider');
        $this->et->clearTicketCaches(10);
    });

    it('builds QR check-in links through Event Tickets', function (): void {
        $connector = Mockery::mock(Connector::class);
        $connector->shouldReceive('get_checkin_url')->with(80, 10, 'abc')->andReturn('https://example.test/qr');
        tribe_singleton(Connector::class, $connector);

        expect($this->et->qrCheckinUrl(80, 10, 'abc'))->toBe('https://example.test/qr');
    });

    it('writes QR images larger than Event Tickets\' default, without changing its shared instance', function (): void {
        $shared = new class extends \TEC\Common\QR\QR {
            /** @var list<array{int, int, string, string, string}> */
            public static array $written = [];

            public function get_png_as_file(string $data, string $name, string $folder = 'tec-qr-codes'): array
            {
                self::$written[] = [$this->size, $this->margin, $data, $name, $folder];

                return ['file' => '/x.png', 'url' => 'https://example.test/uploads/tec-tickets-qr/' . $name . '.png', 'type' => 'image/png', 'error' => false];
            }
        };
        tribe_singleton(\TEC\Common\QR\QR::class, $shared);

        expect($this->et->qrImageUrl('https://example.test/qr', 'tsharp-qr-80-abc'))->toBe('https://example.test/uploads/tec-tickets-qr/tsharp-qr-80-abc.png')
            ->and($shared::$written)->toBe([[6, 2, 'https://example.test/qr', 'tsharp-qr-80-abc', 'tec-tickets-qr']])
            ->and($shared->size)->toBe(3)
            ->and($this->et->qrImageUrl('', 'x'))->toBe('');
    });

    it('returns no QR image when the file could not be written', function (): void {
        $failing = new class extends \TEC\Common\QR\QR {
            public function get_png_as_file(string $data, string $name, string $folder = 'tec-qr-codes'): array
            {
                return ['file' => '', 'url' => '', 'type' => '', 'error' => 'disk full'];
            }
        };
        tribe_singleton(\TEC\Common\QR\QR::class, $failing);

        expect($this->et->qrImageUrl('https://example.test/qr', 'n'))->toBe('');
    });

    it('recognises events through The Events Calendar', function (): void {
        Functions\when('tribe_is_event')->alias(static fn ($id): bool => $id === 10);

        expect($this->et->isEvent(10))->toBeTrue()->and($this->et->isEvent(11))->toBeFalse();
    });
});

describe('Shop', function (): void {
    beforeEach(function (): void {
        $this->shop = new Shop();
    });

    it('loads products and orders through WooCommerce', function (): void {
        $product = Make::product(5);
        $order = Make::order(900);
        Functions\when('wc_get_product')->alias(static fn ($id) => $id === 5 ? $product : false);
        Functions\when('wc_get_order')->alias(static fn ($id) => $id === 900 ? $order : false);

        expect($this->shop->product(5))->toBe($product)
            ->and($this->shop->product(6))->toBeNull()
            ->and($this->shop->product(0))->toBeNull()
            ->and($this->shop->order(900))->toBe($order)
            ->and($this->shop->order(901))->toBeNull()
            ->and($this->shop->order(-1))->toBeNull()
            ->and($this->shop->newTicketProduct())->toBeInstanceOf(WC_Product_Simple::class);
    });

    it('loads the cart when it is not there yet', function (): void {
        WC()->cart = null;
        Functions\expect('wc_load_cart')->once()->andReturnUsing(static function (): void {
            WC()->cart = new WC_Cart();
        });

        expect($this->shop->cart())->toBeInstanceOf(WC_Cart::class)
            ->and($this->shop->cart())->toBe(WC()->cart);

        WC()->cart = null;
    });

    it('returns null when no cart can be had', function (): void {
        WC()->cart = null;

        expect($this->shop->cart())->toBeNull();
    });

    it('passes URLs, notices and prices through', function (): void {
        Functions\expect('wc_add_notice')->once()->with('Hello', 'error');

        $this->shop->notice('Hello');

        expect($this->shop->checkoutUrl())->toBe('https://example.test/checkout/')
            ->and($this->shop->cartUrl())->toBe('https://example.test/cart/')
            ->and($this->shop->currency())->toBe('GBP')
            ->and($this->shop->formatPrice(12.5))->toBe('£12.50');
    });
});

describe('Urls', function (): void {
    it('builds the cart handler URL and finds the details page', function (): void {
        $urls = new Urls(new Settings());

        expect($urls->cartHandler())->toBe('https://example.test/?tsharp_cart=1')
            ->and($urls->detailsPage())->toBe('');

        update_option(Keys::OPTION, ['details_page_id' => 42]);
        expect($urls->detailsPage())->toBe('https://example.test/?p=42');

        Functions\when('get_permalink')->justReturn(false);
        expect($urls->detailsPage())->toBe('');
    });
});
