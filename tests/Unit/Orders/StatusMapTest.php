<?php

declare(strict_types=1);

use TicketsSharp\Orders\StatusMap;
use TicketsSharp\Support\Keys;
use TicketsSharp\Support\Settings;

beforeEach(function (): void {
    $this->map = new StatusMap(new Settings());
});

it('counts processing and completed as paid by default', function (): void {
    expect($this->map->isPaid('processing'))->toBeTrue()
        ->and($this->map->isPaid('wc-completed'))->toBeTrue()
        ->and($this->map->isPaid('on-hold'))->toBeFalse()
        ->and($this->map->isPaid('pending'))->toBeFalse();
});

it('follows the configured paid statuses', function (): void {
    update_option(Keys::OPTION, ['paid_statuses' => ['wc-completed']]);

    expect($this->map->isPaid('processing'))->toBeFalse()
        ->and($this->map->isPaid('completed'))->toBeTrue();
});

it('treats cancelled, failed and refunded as void', function (): void {
    foreach (['cancelled', 'failed', 'refunded', 'wc-refunded'] as $status) {
        expect($this->map->isVoid($status))->toBeTrue();
    }
    expect($this->map->isVoid('completed'))->toBeFalse();
});

it('decides who holds a seat', function (): void {
    expect($this->map->holdsSeat('completed', 999999))->toBeTrue()
        ->and($this->map->holdsSeat('on-hold', 999999))->toBeTrue()
        ->and($this->map->holdsSeat('pending', 60))->toBeTrue()
        ->and($this->map->holdsSeat('pending', 3601))->toBeFalse()
        ->and($this->map->holdsSeat('cancelled', 0))->toBeFalse()
        ->and($this->map->holdsSeat('checkout-draft', 0))->toBeFalse();
});

it('uses the configured hold window for pending orders', function (): void {
    update_option(Keys::OPTION, ['pending_hold_minutes' => 5]);

    expect($this->map->holdsSeat('pending', 299))->toBeTrue()
        ->and($this->map->holdsSeat('pending', 301))->toBeFalse();
});

it('describes the statuses for Event Tickets with paid ones completed', function (): void {
    $definitions = $this->map->definitions();

    expect(array_keys($definitions))->toBe(['pending', 'on-hold', 'processing', 'completed', 'cancelled', 'failed', 'refunded'])
        ->and($definitions['completed']['flags']['count_completed'])->toBeTrue()
        ->and($definitions['processing']['flags']['count_completed'])->toBeTrue()
        ->and($definitions['pending']['flags'])->not->toHaveKey('count_completed')
        ->and($definitions['refunded']['flags']['count_refunded'])->toBeTrue();
});
