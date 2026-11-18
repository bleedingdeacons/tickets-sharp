<?php

declare(strict_types=1);

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use TicketsSharp\Lifecycle;
use TicketsSharp\Support\Keys;
use TicketsSharp\Support\Settings;
use TicketsSharp\Tests\Support\TestPost;

it('creates the attendee details page on activation', function (): void {
    Lifecycle::activate();

    expect(WpState::$insertedPosts[0])->toMatchArray(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'attendee-details'])
        ->and(WpState::$insertedPosts[0]['post_content'])->toContain('[tickets_sharp_attendees]')
        ->and(get_option(Keys::OPTION))->toBe(['details_page_id' => 100]);
});

it('keeps an existing page', function (): void {
    TestPost::add(42, 'page');
    update_option(Keys::OPTION, ['details_page_id' => 42]);

    expect(Lifecycle::ensureDetailsPage(new Settings()))->toBe(42)
        ->and(WpState::$insertedPosts)->toBe([]);
});

it('replaces a trashed or missing page', function (): void {
    TestPost::add(42, 'page', ['post_status' => 'trash']);
    update_option(Keys::OPTION, ['details_page_id' => 42]);

    expect(Lifecycle::ensureDetailsPage(new Settings()))->toBe(100);
});

it('reports failure to create the page', function (): void {
    Functions\when('wp_insert_post')->justReturn(0);

    expect(Lifecycle::ensureDetailsPage(new Settings()))->toBe(0)
        ->and(get_option(Keys::OPTION, 'unset'))->toBe('unset');
});
