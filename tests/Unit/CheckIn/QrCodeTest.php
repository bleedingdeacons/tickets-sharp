<?php

declare(strict_types=1);

use TicketsSharp\CheckIn\QrCode;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Integration\EventTickets;

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
    $this->eventTickets->shouldReceive('qrImageUrl')->with('https://example.test/?event_qr_code=1')->andReturn('https://example.test/uploads/qr.png');

    expect($this->qr->cell($this->ticket))
        ->toContain('<td class="tec-tickets__email-table-content-ticket-qr tsharp-qr"')
        ->toContain('src="https://example.test/uploads/qr.png"')
        ->toContain('alt="Check-in QR code"');
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
