<?php

declare(strict_types=1);

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use TicketsSharp\Admin\Notices;
use TicketsSharp\Admin\OrderMetaBox;
use TicketsSharp\Admin\Settings;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Orders\Mailer;
use TicketsSharp\Support\Keys;
use TicketsSharp\Tests\Support\Make;
use TicketsSharp\Tests\Support\TestPost;

describe('Notices', function (): void {
    it('records each problem once and renders it for admins', function (): void {
        $notices = new Notices();
        $notices->register();
        $notices->add(Notices::NO_WOOCOMMERCE);
        $notices->add(Notices::NO_WOOCOMMERCE);
        $notices->add('custom');

        $this->assertActionAdded('admin_notices');
        expect($notices->codes())->toBe([Notices::NO_WOOCOMMERCE, 'custom'])
            ->and(captureOutput([$notices, 'render']))
                ->toContain('Tickets Sharp is not running.')
                ->toContain('It needs WooCommerce to be active.')
                ->toContain('custom');
    });

    it('has a message for every code', function (string $code): void {
        expect((new Notices())->message($code))->not->toBe($code);
    })->with([Notices::NO_WOOCOMMERCE, Notices::NO_EVENT_TICKETS, Notices::INCOMPATIBLE_EVENT_TICKETS, Notices::CONFLICT]);

    it('shows nothing to users who cannot manage plugins, or when all is well', function (): void {
        $notices = new Notices();
        expect(captureOutput([$notices, 'render']))->toBe('');

        $notices->add(Notices::CONFLICT);
        WpState::$userCan = false;
        expect(captureOutput([$notices, 'render']))->toBe('');
    });
});

describe('Settings page', function (): void {
    it('adds a section under WooCommerce products settings', function (): void {
        $settings = new Settings();
        $settings->register();

        $this->assertFilterAdded('woocommerce_get_sections_products');
        expect($settings->addSection(['' => 'General']))->toBe(['' => 'General', Settings::SECTION => 'Event tickets'])
            ->and($settings->addSection('x'))->toBe('x');
    });

    it('stores its fields inside the single option', function (): void {
        $fields = (new Settings())->fields([], Settings::SECTION);
        $ids = array_column($fields, 'id');

        expect($ids)->toContain(Keys::OPTION . '[paid_statuses]', Keys::OPTION . '[pending_hold_minutes]', Keys::OPTION . '[details_page_id]')
            ->and($fields[1]['options'])->toBe(['pending' => 'Pending payment', 'processing' => 'Processing', 'on-hold' => 'On hold', 'completed' => 'Completed'])
            ->and($fields[1]['default'])->toBe(['processing', 'completed']);
    });

    it('leaves other sections alone', function (): void {
        expect((new Settings())->fields(['x'], 'downloadable'))->toBe(['x']);
    });
});

describe('Order meta box', function (): void {
    beforeEach(function (): void {
        $this->provider = Mockery::mock(Provider::class);
        $this->mailer = Mockery::mock(Mailer::class);
        $this->shop = Mockery::mock(Shop::class);
        $this->box = new OrderMetaBox($this->provider, $this->mailer, $this->shop);
        $this->order = Make::order(900);
        // Not in wp-mocks' WordPress stub group.
        Functions\when('wp_nonce_url')->alias(static fn (string $url, string $action): string => $url . '&_wpnonce=' . $action);
    });

    it('adds itself to both order screens', function (): void {
        $this->box->register();
        Functions\expect('add_meta_box')->twice();

        $this->assertActionAdded('add_meta_boxes');
        $this->box->addMetaBox();
    });

    it('lists the order\'s attendees', function (): void {
        $this->provider->shouldReceive('get_attendees_by_order_id')->with(900)->andReturn([[
            'ticket_name' => 'Adult', 'holder_name' => 'Ann', 'holder_email' => 'ann@example.test',
            'security_code' => 'abc', 'order_status_label' => 'Completed', 'check_in' => true,
            'attendee_meta' => ['diet' => 'Veg', 'none' => ''],
        ]]);
        $this->shop->shouldReceive('order')->with(900)->andReturn($this->order);

        $html = captureOutput(fn () => $this->box->render($this->order));

        expect($html)->toContain('<td>Adult</td>')
            ->toContain('ann@example.test')
            ->toContain('diet: Veg')
            ->not->toContain('none:')
            ->toContain('<code>abc</code>')
            ->toContain('Checked in')
            ->toContain('Send tickets again')
            ->and(captureOutput(fn () => $this->box->render(TestPost::add(900, 'shop_order'))))->toBe($html);
    });

    it('says when an order has no tickets', function (): void {
        $this->provider->shouldReceive('get_attendees_by_order_id')->andReturn([]);
        $this->shop->shouldReceive('order')->andReturn(null);

        expect($this->box->html($this->order))->toContain('This order has no event tickets.')
            ->and(captureOutput(fn () => $this->box->render('nothing')))->toBe('');
    });

    it('sends tickets again on request', function (): void {
        Functions\when('wp_verify_nonce')->justReturn(1);
        $this->shop->shouldReceive('order')->with(900)->andReturn($this->order);
        $this->mailer->shouldReceive('sendForOrder')->once()->with($this->order, true)->andReturn(true);

        expect($this->box->resend(['order_id' => '900', '_wpnonce' => 'n']))->toContain('tsharp_resent=1')
            ->and($this->order->notes)->toBe(['Event tickets sent again.']);
    });

    it('notes when there was nothing to send', function (): void {
        Functions\when('wp_verify_nonce')->justReturn(1);
        $this->shop->shouldReceive('order')->andReturn($this->order);
        $this->mailer->shouldReceive('sendForOrder')->andReturn(false);

        expect($this->box->resend(['order_id' => 900, '_wpnonce' => 'n']))->toContain('tsharp_resent=0')
            ->and($this->order->notes[0])->toContain('no paid tickets');
    });

    it('refuses a bad nonce or a user without permission', function (): void {
        Functions\when('wp_verify_nonce')->justReturn(false);

        expect(fn () => $this->box->resend(['order_id' => 900]))->toThrow(WpDieException::class);

        Functions\when('wp_verify_nonce')->justReturn(1);
        WpState::$userCan = false;
        expect(fn () => $this->box->resend(['order_id' => 900, '_wpnonce' => 'n']))->toThrow(WpDieException::class);
    });
});
