<?php

declare(strict_types=1);

use TicketsSharp\CheckIn\QrCode;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Integration\EventTickets;
use TicketsSharp\Support\Keys;

beforeEach(function (): void {
    $this->eventTickets = Mockery::mock(EventTickets::class);
    $this->qr = new QrCode($this->eventTickets);
    $this->ticket = ['provider' => Provider::class, 'qr_ticket_id' => 80, 'event_id' => 10, 'security_code' => 'abc'];
});

it('hooks the ticket e-mail\'s security code template', function (): void {
    $this->qr->register();

    $this->assertActionAdded(QrCode::HOOK);
});

it('adds a QR image cell for its own tickets', function (): void {
    $this->eventTickets->shouldReceive('qrCheckinUrl')->with(80, 10, 'abc')->andReturn('https://example.test/?event_qr_code=1');
    $this->eventTickets->shouldReceive('qrImageUrl')->with('https://example.test/?event_qr_code=1', Mockery::pattern('/^tsharp-qr-80-[0-9a-f]{12}$/'))->andReturn('https://example.test/uploads/qr.png');

    expect($this->qr->cell($this->ticket))
        ->toContain('<td class="tec-tickets__email-table-content-ticket-qr tsharp-qr"')
        ->toContain('src="https://example.test/uploads/qr.png"')
        ->toContain('alt="Check-in QR code"');
});

it('makes each attendee\'s QR image once and keeps it', function (): void {
    $this->eventTickets->shouldReceive('qrCheckinUrl')->once()->andReturn('https://example.test/qr');
    $this->eventTickets->shouldReceive('qrImageUrl')->once()->andReturn('https://example.test/qr.png');

    expect($this->qr->imageUrl($this->ticket))->toBe('https://example.test/qr.png')
        ->and($this->qr->imageUrl($this->ticket))->toBe('https://example.test/qr.png')
        ->and(get_post_meta(80, Keys::ATTENDEE_QR_IMAGE, true))->toBe(['code' => 'abc', 'url' => 'https://example.test/qr.png'])
        ->and($this->qr->imageTag($this->ticket, 140))->toContain('width="140" height="140"');
});

it('makes a new QR image when the security code changes', function (): void {
    update_post_meta(80, Keys::ATTENDEE_QR_IMAGE, ['code' => 'old', 'url' => 'https://example.test/old.png']);
    $this->eventTickets->shouldReceive('qrCheckinUrl')->once()->andReturn('https://example.test/qr');
    $this->eventTickets->shouldReceive('qrImageUrl')->once()->andReturn('https://example.test/new.png');

    expect($this->qr->imageUrl($this->ticket))->toBe('https://example.test/new.png');
});

it('keeps nothing when the image could not be written', function (): void {
    $this->eventTickets->shouldReceive('qrCheckinUrl')->andReturn('https://example.test/qr');
    $this->eventTickets->shouldReceive('qrImageUrl')->andReturn('');

    expect($this->qr->imageTag($this->ticket))->toBe('')
        ->and(get_post_meta(80, Keys::ATTENDEE_QR_IMAGE, true))->toBe('');
});

it('renders from the e-mail template\'s ticket', function (): void {
    $this->eventTickets->shouldReceive('qrCheckinUrl')->andReturn('https://example.test/qr');
    $this->eventTickets->shouldReceive('qrImageUrl')->andReturn('https://example.test/qr.png');
    $template = new class ($this->ticket) {
        public function __construct(private array $ticket)
        {
        }

        public function get(string $key): mixed
        {
            return $key === 'ticket' ? $this->ticket : null;
        }
    };

    expect(captureOutput(fn () => $this->qr->renderForTemplate('', [], $template)))->toContain('qr.png')
        ->and(captureOutput(fn () => $this->qr->renderForTemplate('', [], 'not a template')))->toBe('');
});

it('adds nothing for other providers or incomplete tickets', function (): void {
    $this->eventTickets->shouldReceive('qrCheckinUrl')->never();

    expect($this->qr->cell(['provider' => 'Tribe__Tickets__RSVP'] + $this->ticket))->toBe('')
        ->and($this->qr->cell(['security_code' => ''] + $this->ticket))->toBe('')
        ->and($this->qr->imageUrl(['qr_ticket_id' => 'x']))->toBe('');
});

it('adds nothing when Event Tickets cannot make the code', function (): void {
    $this->eventTickets->shouldReceive('qrCheckinUrl')->andReturn('');

    expect($this->qr->cell($this->ticket))->toBe('');
});
