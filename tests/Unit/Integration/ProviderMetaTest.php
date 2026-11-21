<?php

declare(strict_types=1);

use BleedingDeacons\WpMocks\WpState;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Integration\ProviderMeta;

it('names the value WordPress stores without the fix', function (): void {
    expect(ProviderMeta::stripped())->toBe('TicketsSharpCommerceProvider')
        ->and(ProviderMeta::stripped())->toBe(wp_unslash(Provider::class));
});

it('puts the backslashes back into a stripped Tickets Sharp provider', function (): void {
    expect((new ProviderMeta())->keepSlashes('TicketsSharpCommerceProvider'))->toBe(Provider::class);
});

it('leaves every other provider value alone', function (mixed $value): void {
    expect((new ProviderMeta())->keepSlashes($value))->toBe($value);
})->with([
    'intact class name' => [Provider::class],
    'Tickets Commerce' => ['TEC\\Tickets\\Commerce\\Module'],
    'RSVP' => ['Tribe__Tickets__RSVP'],
    'empty' => [''],
    'not a string' => [null],
]);

it('runs after Event Tickets\' own sanitize filter and repairs on admin requests', function (): void {
    $meta = new ProviderMeta();
    $meta->register();

    expect(has_filter('sanitize_post_meta__tribe_default_ticket_provider', [$meta, 'keepSlashes']))->toBe(20);
    $this->assertActionAdded('admin_init', [$meta, 'repairOnce']);
});

it('repairs every event storing the stripped value', function (): void {
    WpState::$postMeta[25265][ProviderMeta::FIELD] = 'TicketsSharpCommerceProvider';
    WpState::$postMeta[300][ProviderMeta::FIELD] = 'TicketsSharpCommerceProvider';
    $searched = [];
    $meta = new ProviderMeta(static function (string $value) use (&$searched): array {
        $searched[] = $value;

        return [25265, 300];
    });

    expect($meta->repair())->toBe([25265, 300])
        ->and($searched)->toBe(['TicketsSharpCommerceProvider'])
        ->and(get_post_meta(25265, ProviderMeta::FIELD, true))->toBe(Provider::class)
        ->and(get_post_meta(300, ProviderMeta::FIELD, true))->toBe(Provider::class);
});

it('repairs once, then remembers it has', function (): void {
    $calls = 0;
    $meta = new ProviderMeta(static function () use (&$calls): array {
        $calls++;

        return [];
    });

    $meta->repairOnce();
    $meta->repairOnce();

    expect($calls)->toBe(1)
        ->and(get_option(ProviderMeta::REPAIRED_OPTION))->toBe(1);
});

it('finds the affected posts with a prepared query on postmeta', function (): void {
    $wpdb = Mockery::mock();
    $wpdb->postmeta = 'wp_postmeta';
    $wpdb->shouldReceive('prepare')
        ->once()
        ->with(Mockery::pattern('/FROM wp_postmeta WHERE meta_key = %s AND meta_value = %s/'), ProviderMeta::FIELD, 'TicketsSharpCommerceProvider')
        ->andReturn('SQL');
    $wpdb->shouldReceive('get_col')->once()->with('SQL')->andReturn(['25265', '300']);
    $GLOBALS['wpdb'] = $wpdb;

    expect((new ProviderMeta())->repair())->toBe([25265, 300]);
});
