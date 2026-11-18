<?php

declare(strict_types=1);

use TicketsSharp\Support\Keys;
use TicketsSharp\Support\Settings;

it('has defaults when nothing is stored', function (): void {
    $settings = new Settings();

    expect($settings->all())->toBe([])
        ->and($settings->paidStatuses())->toBe(['processing', 'completed'])
        ->and($settings->pendingHoldMinutes())->toBe(60)
        ->and($settings->detailsPageId())->toBe(0);
});

it('ignores a stored value that is not an array', function (): void {
    update_option(Keys::OPTION, 'garbage');

    expect((new Settings())->all())->toBe([]);
});

it('reads and normalises stored values', function (): void {
    update_option(Keys::OPTION, [
        'paid_statuses' => ['wc-completed', 'completed', '', 7, 'on-hold'],
        'pending_hold_minutes' => '15',
        'details_page_id' => '42',
    ]);
    $settings = new Settings();

    expect($settings->paidStatuses())->toBe(['completed', 'on-hold'])
        ->and($settings->pendingHoldMinutes())->toBe(15)
        ->and($settings->detailsPageId())->toBe(42);
});

it('falls back on unusable values', function (): void {
    update_option(Keys::OPTION, ['paid_statuses' => [], 'pending_hold_minutes' => '-3', 'details_page_id' => 'x']);
    $settings = new Settings();

    expect($settings->paidStatuses())->toBe(['processing', 'completed'])
        ->and($settings->pendingHoldMinutes())->toBe(60)
        ->and($settings->detailsPageId())->toBe(0);

    update_option(Keys::OPTION, ['paid_statuses' => 'completed']);
    expect($settings->paidStatuses())->toBe(['processing', 'completed']);
});

it('merges updates into the stored option', function (): void {
    update_option(Keys::OPTION, ['pending_hold_minutes' => 10]);
    (new Settings())->update(['details_page_id' => 9]);

    expect(get_option(Keys::OPTION))->toBe(['pending_hold_minutes' => 10, 'details_page_id' => 9]);
});

it('strips the wc- prefix', function (): void {
    expect(Settings::stripPrefix('wc-on-hold'))->toBe('on-hold')
        ->and(Settings::stripPrefix('pending'))->toBe('pending');
});
