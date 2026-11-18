<?php

declare(strict_types=1);

use BleedingDeacons\WpMocks\WpState;
use TicketsSharp\Admin\TicketFormFields;
use TicketsSharp\Attendees\FieldSet;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Support\Keys;

beforeEach(function (): void {
    $this->provider = Mockery::mock(Provider::class);
    $this->handler = Mockery::mock(Tribe__Tickets__Tickets_Handler::class);
    tribe_singleton('tickets.handler', $this->handler);
    $this->fields = new TicketFormFields($this->provider);
});

it('goes in the ticket form, after the capacity field', function (): void {
    $this->fields->register();

    $this->assertActionAdded('tribe_events_tickets_metabox_edit_main');
});

it('asks a new ticket\'s attendees for their name and email by default', function (): void {
    $this->handler->shouldReceive('is_correct_provider')->with(10, $this->provider)->andReturn(true);

    $html = captureOutput(fn () => $this->fields->render(10, ''));

    expect($html)->toContain('name="tsharp_attendee_fields"')
        ->toContain(">Name | name | required\nEmail | email | required</textarea>")
        ->toContain('Attendee information:');
});

it('shows an existing ticket\'s own fields, or none', function (): void {
    WpState::$postMeta[55][Keys::PRODUCT_FIELDS] = FieldSet::fromText("Name | name | required\nDiet | select | optional | Meat, Veg");
    $this->handler->shouldReceive('is_correct_provider')->andReturn(true);

    expect(captureOutput(fn () => $this->fields->render(10, 55)))->toContain(">Name | name | required\nDiet | select | optional | Meat, Veg</textarea>")
        ->and($this->fields->currentText(56))->toBe('');
});

it('stays out of another provider\'s ticket form', function (): void {
    $this->handler->shouldReceive('is_correct_provider')->andReturn(false);

    expect(captureOutput(fn () => $this->fields->render(10, '')))->toBe('')
        ->and(captureOutput(fn () => $this->fields->render('', '')))->toBe('');
});

it('escapes what it shows', function (): void {
    expect($this->fields->html('</textarea><script>'))->not->toContain('<script>');
});
