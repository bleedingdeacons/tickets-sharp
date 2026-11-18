<?php

declare(strict_types=1);

use TicketsSharp\CheckIn\QrCode;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Emails\OrderEmailTickets;
use TicketsSharp\Emails\TicketImage;
use TicketsSharp\Orders\StatusMap;
use TicketsSharp\Support\Settings;
use TicketsSharp\Tests\Support\Make;
use TicketsSharp\Tests\Support\TestPost;

beforeEach(function (): void {
    $this->provider = Mockery::mock(Provider::class);
    $this->qr = Mockery::mock(QrCode::class);
    $this->image = Mockery::mock(TicketImage::class);
    $this->section = new OrderEmailTickets($this->provider, new StatusMap(new Settings()), $this->qr, $this->image);
    $this->order = Make::order(900);

    TestPost::add(10, 'tribe_events', ['post_title' => 'NYE – Test']);
    TestPost::add(11, 'tribe_events', ['post_title' => 'Winter Convention']);
    $this->provider->shouldReceive('get_attendees_by_order_id')->with(900)->andReturn([
        ['event_id' => 10, 'order_status' => 'processing', 'ticket_name' => 'NYE-MOCK', 'holder_name' => 'Ann', 'security_code' => 'abc', 'qr_ticket_id' => 80],
        ['event_id' => 10, 'order_status' => 'refunded', 'ticket_name' => 'NYE-MOCK', 'holder_name' => 'Bo', 'security_code' => 'def', 'qr_ticket_id' => 81],
        ['event_id' => '11', 'order_status' => 'completed', 'ticket_name' => 'Adult', 'holder_name' => 'Cy <b>', 'security_code' => 'ghi', 'qr_ticket_id' => 82],
    ]);
    $this->qr->shouldReceive('imageTag')->andReturnUsing(static fn (array $a, int $size): string => '<img src="qr-' . $a['qr_ticket_id'] . '.png" width="' . $size . '">');
    $this->image->shouldReceive('imageTag')->with(10)->andReturn('<img src="nye.jpg">');
    $this->image->shouldReceive('imageTag')->with(11)->andReturn('');
});

function wooEmail(string $id): WC_Email
{
    $email = new WC_Email();
    $email->id = $id;

    return $email;
}

it('hooks WooCommerce\'s order e-mails', function (): void {
    $this->section->register();

    $this->assertActionAdded('woocommerce_email_after_order_table');
});

it('groups an order\'s paid tickets by event', function (): void {
    $byEvent = $this->section->paidTicketsByEvent($this->order);

    expect(array_keys($byEvent))->toBe([10, 11])
        ->and($byEvent[10])->toHaveCount(1)
        ->and($byEvent[10][0]['holder_name'])->toBe('Ann');
});

it('adds the tickets, QR codes and event image to the customer\'s order e-mail', function (): void {
    $html = captureOutput(fn () => $this->section->render($this->order, false, false, wooEmail('customer_completed_order')));

    expect($html)->toContain('<h2>Your tickets</h2>')
        ->toContain('<h3 style="margin:24px 0 8px;">NYE – Test</h3>')
        ->toContain('<img src="nye.jpg">')
        ->toContain('<strong>NYE-MOCK</strong>')
        ->toContain('<code>abc</code>')
        ->toContain('<img src="qr-80.png" width="140">')
        ->toContain('<img src="qr-82.png" width="140">')
        ->toContain('Cy &lt;b&gt;')
        ->not->toContain('qr-81.png');
});

it('writes a plain-text version without images', function (): void {
    $text = captureOutput(fn () => $this->section->render($this->order, false, true, wooEmail('customer_processing_order')));

    expect($text)->toContain('YOUR TICKETS')
        ->toContain('- NYE-MOCK: Ann (security code abc)')
        ->toContain('Winter Convention')
        ->not->toContain('<img');
});

it('stays out of admin e-mails, other e-mails and orders without paid tickets', function (): void {
    expect(captureOutput(fn () => $this->section->render($this->order, true, false, wooEmail('new_order'))))->toBe('')
        ->and(captureOutput(fn () => $this->section->render($this->order, false, false, wooEmail('customer_on_hold_order'))))->toBe('')
        ->and(captureOutput(fn () => $this->section->render('not an order')))->toBe('');

    $unpaid = Make::order(901);
    $this->provider->shouldReceive('get_attendees_by_order_id')->with(901)->andReturn([['event_id' => 10, 'order_status' => 'pending']]);
    expect(captureOutput(fn () => $this->section->render($unpaid, false, false, wooEmail('customer_invoice'))))->toBe('');
});

it('renders when WooCommerce passes no e-mail object', function (): void {
    expect(captureOutput(fn () => $this->section->render($this->order)))->toContain('Your tickets');
});
