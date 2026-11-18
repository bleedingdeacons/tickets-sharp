<?php

declare(strict_types=1);

use TicketsSharp\Admin\TicketsTable;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Support\Keys;

beforeEach(function (): void {
    $this->table = new TicketsTable();
});

it('hooks the All Tickets query and provider list', function (): void {
    $this->table->register();

    $this->assertFilterAdded('tec_tickets_admin_tickets_table_query_args');
    $this->assertFilterAdded('tec_tickets_admin_tickets_table_provider_info');
});

it('puts Tickets Sharp first so the screen opens on it', function (): void {
    $providers = [
        'TEC\\Tickets\\Commerce\\Module' => ['title' => 'Tickets Commerce'],
        Provider::class => ['title' => 'WooCommerce'],
    ];

    expect(array_keys($this->table->putFirst($providers)))->toBe([Provider::class, 'TEC\\Tickets\\Commerce\\Module'])
        ->and($this->table->putFirst($providers)[Provider::class])->toBe(['title' => 'WooCommerce']);
});

it('leaves the provider list alone without Tickets Sharp in it', function (): void {
    $providers = ['TEC\\Tickets\\Commerce\\Module' => ['title' => 'Tickets Commerce']];

    expect($this->table->putFirst($providers))->toBe($providers)
        ->and($this->table->putFirst('x'))->toBe('x');
});

it('lists only products linked to an event', function (): void {
    $args = $this->table->onlyTicketProducts(['post_type' => 'product', 'posts_per_page' => 20]);

    expect($args['meta_query'])->toBe([['key' => Keys::TICKET_EVENT, 'compare' => 'EXISTS']])
        ->and($args['posts_per_page'])->toBe(20);
});

it('keeps Event Tickets\' own meta conditions alongside', function (): void {
    $existing = [['key' => '_sale_price', 'compare' => 'EXISTS']];

    expect($this->table->onlyTicketProducts(['post_type' => 'product', 'meta_query' => $existing])['meta_query'])
        ->toBe(['relation' => 'AND', $existing, ['key' => Keys::TICKET_EVENT, 'compare' => 'EXISTS']]);
});

it('leaves other providers\' queries alone', function (): void {
    expect($this->table->onlyTicketProducts(['post_type' => 'tec_tc_ticket']))->toBe(['post_type' => 'tec_tc_ticket'])
        ->and($this->table->onlyTicketProducts('x'))->toBe('x');
});
