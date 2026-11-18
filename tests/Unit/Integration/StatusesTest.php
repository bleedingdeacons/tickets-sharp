<?php

declare(strict_types=1);

use TicketsSharp\Commerce\Provider;
use TicketsSharp\Integration\OrderStatus;
use TicketsSharp\Integration\OrderStatuses;
use TicketsSharp\Integration\StatusManager;
use TicketsSharp\Orders\StatusMap;
use TicketsSharp\Support\Keys;
use TicketsSharp\Support\Settings;

it('patches the live status manager with the provider and its statuses', function (): void {
    $manager = new Tribe__Tickets__Status__Manager();

    StatusManager::register($manager);

    expect($manager->get_provider_slug(Provider::class))->toBe(Keys::PROVIDER_SLUG)
        ->and($manager->get_provider_slug('Tribe__Tickets__RSVP'))->toBe('rsvp')
        ->and($manager->get_status_managers())->toMatchArray([Keys::PROVIDER_SLUG => OrderStatuses::class, 'rsvp' => 'Tribe__Tickets__RSVP__Status_Manager']);
});

it('checks that the members it patches still exist', function (): void {
    // If Event Tickets renames these, StatusManager::register() silently does
    // nothing and the provider is treated as inactive. The plugin then stands
    // down with a notice instead.
    expect(StatusManager::isCompatible())->toBeTrue()
        ->and(property_exists(Tribe__Tickets__Status__Manager::class, 'module_slugs'))->toBeTrue();
});

it('builds Event Tickets status objects for every WooCommerce status', function (): void {
    $statuses = new OrderStatuses();

    expect($statuses->completed_status_id)->toBe('completed')
        ->and($statuses->status_names)->toBe(['pending', 'on-hold', 'processing', 'completed', 'cancelled', 'failed', 'refunded'])
        ->and($statuses->statuses['completed'])->toBeInstanceOf(OrderStatus::class)
        ->and($statuses->statuses['completed']->count_completed)->toBeTrue()
        ->and($statuses->statuses['completed']->provider_name)->toBe('completed')
        ->and($statuses->statuses['completed']->post_type)->toBe('shop_order')
        ->and($statuses->statuses['on-hold']->incomplete)->toBeTrue()
        ->and($statuses->statuses['refunded']->count_refunded)->toBeTrue();
});

it('follows the configured paid statuses when rebuilt', function (): void {
    update_option(Keys::OPTION, ['paid_statuses' => ['completed']]);

    $statuses = new OrderStatuses(new StatusMap(new Settings()));
    expect($statuses->statuses['processing']->count_completed)->toBeFalse();

    update_option(Keys::OPTION, ['paid_statuses' => ['processing', 'completed']]);
    $statuses->initialize_status_classes();
    expect($statuses->statuses['processing']->count_completed)->toBeTrue();
});

it('sets only flags Event Tickets knows', function (): void {
    $status = new OrderStatus('Paid', 'paid', ['count_completed' => true, 'made_up' => true]);

    expect($status->name)->toBe('Paid')
        ->and($status->count_completed)->toBeTrue()
        ->and(property_exists($status, 'made_up'))->toBeFalse();
});
