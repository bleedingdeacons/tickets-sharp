<?php

declare(strict_types=1);

use BleedingDeacons\WpMocks\WpState;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Integration\Currency;
use TicketsSharp\Integration\EventTickets;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Support\Keys;
use TicketsSharp\Tests\Support\Make;

const TS_EVENT = 25265;
const OTHER_EVENT = 400;
const TS_TICKET = 25300;

beforeEach(function (): void {
    $this->shop = Mockery::mock(Shop::class);
    $this->shop->allows([
        'currency' => 'GBP',
        'currencySymbol' => '&pound;',
        'currencyPosition' => 'left',
        'decimalSeparator' => ',',
        'thousandSeparator' => '.',
        'decimals' => 3,
    ])->byDefault();

    $this->eventTickets = Mockery::mock(EventTickets::class);
    $this->eventTickets->allows('eventProvider')->andReturnUsing(static fn (int $id): string => match ($id) {
        TS_EVENT => Provider::class,
        OTHER_EVENT => 'TEC\\Tickets\\Commerce\\Module',
        default => '',
    });

    WpState::$postMeta[TS_TICKET][Keys::TICKET_EVENT] = TS_EVENT;

    $this->currency = new Currency($this->shop, $this->eventTickets);
});

it('formats Tickets Sharp prices with WooCommerce\'s settings', function (): void {
    expect($this->currency->get_currency_by_provider(TS_EVENT, Provider::class))->toBe([
        'symbol' => '&pound;',
        'placement' => 'prefix',
        'decimal_point' => ',',
        'thousands_sep' => '.',
        'number_of_decimals' => 3,
    ]);
});

it('recognises the provider passed as an instance', function (): void {
    $provider = Make::provider(['shop' => $this->shop])['provider'];

    expect($this->currency->get_provider_symbol($provider, TS_EVENT))->toBe('&pound;');
});

it('leaves every other provider to Event Tickets', function (): void {
    expect($this->currency->get_currency_by_provider(OTHER_EVENT, 'TEC\\Tickets\\Commerce\\Module'))->toBe([
        'symbol' => '&#x24;',
        'placement' => 'prefix',
        'decimal_point' => '.',
        'thousands_sep' => ',',
        'number_of_decimals' => 2,
    ]);
});

it('places the symbol after the amount when WooCommerce does', function (string $position, string $placement): void {
    $this->shop->allows('currencyPosition')->andReturn($position);

    expect($this->currency->get_provider_symbol_position(Provider::class, TS_EVENT))->toBe($placement);
})->with([
    'left' => ['left', 'prefix'],
    'left with space' => ['left_space', 'prefix'],
    'right' => ['right', 'postfix'],
    'right with space' => ['right_space', 'postfix'],
]);

it('gives an event sold through Tickets Sharp WooCommerce\'s symbol and position', function (): void {
    $this->shop->allows('currencyPosition')->andReturn('right');

    expect($this->currency->get_currency_symbol(TS_EVENT))->toBe('&pound;')
        ->and($this->currency->get_currency_symbol(TS_EVENT, true))->toBe('£')
        ->and($this->currency->get_currency_symbol_position(TS_EVENT))->toBe('postfix')
        ->and($this->currency->reverse_currency_symbol_position(false, TS_EVENT))->toBeTrue();
});

it('gives a Tickets Sharp ticket WooCommerce\'s symbol', function (): void {
    expect($this->currency->get_currency_symbol(TS_TICKET))->toBe('&pound;');
});

it('answers Event Tickets\' symbol filter for an event sold through Tickets Sharp', function (): void {
    // Event Tickets passes the event's provider object, which its stub cannot
    // resolve; the lookup then falls back to the post, as it does for any
    // provider Event Tickets does not know.
    expect($this->currency->filter_currency_symbol('$', TS_EVENT))->toBe('&pound;')
        ->and($this->currency->filter_currency_symbol('$', OTHER_EVENT))->toBe('&#x24;');
});

it('leaves other posts, and no post, to Event Tickets', function (mixed $postId): void {
    expect($this->currency->get_currency_symbol($postId))->toBe('&#x24;')
        ->and($this->currency->get_currency_symbol_position($postId))->toBe('prefix');
})->with([
    'another provider\'s event' => [OTHER_EVENT],
    'no post' => [null],
    'zero' => [0],
    'not a number' => ['abc'],
]);

it('reports WooCommerce\'s currency code for a Tickets Sharp ticket', function (): void {
    expect($this->currency->ticketCurrencyCode(null, TS_TICKET))->toBe('GBP')
        ->and($this->currency->ticketCurrencyCode(null, 999))->toBeNull()
        ->and($this->currency->ticketCurrencyCode(null, 'x'))->toBeNull();
});

it('keeps Event Tickets\' code when WooCommerce gives none', function (): void {
    $this->shop->allows('currency')->andReturn('');

    expect($this->currency->ticketCurrencyCode('EUR', TS_TICKET))->toBe('EUR');
});

it('takes the place of Event Tickets\' currency service and hooks after it', function (): void {
    $this->currency->replace();

    expect(tribe('tickets.commerce.currency'))->toBe($this->currency)
        ->and(tribe(Tribe__Tickets__Commerce__Currency::class))->toBe($this->currency)
        ->and(has_filter('tec_tickets_commerce_tickets_currency_code', [$this->currency, 'ticketCurrencyCode']))->toBe(20);
    $this->assertFilterAdded('tribe_currency_symbol', [$this->currency, 'filter_currency_symbol']);
    $this->assertFilterAdded('tribe_reverse_currency_position', [$this->currency, 'reverse_currency_symbol_position']);
    $this->assertFilterAdded('get_post_metadata', [$this->currency, 'filter_cost_meta']);
});

it('gives the provider WooCommerce\'s currency code', function (): void {
    $provider = Make::provider(['shop' => $this->shop])['provider'];

    expect($provider->get_currency())->toBe('GBP');

    $this->shop->allows('currency')->andReturn('');
    expect($provider->get_currency())->toBe('USD');
});
