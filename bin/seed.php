<?php

/**
 * Seeds the dev site with a sample event selling tickets through WooCommerce.
 *
 * Run through WP-CLI (bin/dev.ps1 seed does this):
 *   wp eval-file bin/seed.php            create sample data (idempotent: replaces earlier samples)
 *   wp eval-file bin/seed.php reset      remove sample data only
 *
 * The event gets three tickets, all created through Event Tickets' own
 * ticket_add(), exactly as the ticket editor creates them:
 *   - "Adult" and "Concession" share a capacity of 30 (Adult capped at 20);
 *   - "Workshop" has its own capacity of 10 and asks each attendee for a name,
 *     an email address and dietary needs.
 *
 * Everything created is tagged with `_tickets_sharp_sample`, so reset never
 * touches real content. Orders placed against the sample tickets are left
 * alone: they are WooCommerce records.
 *
 * @package TicketsSharp
 */

// No declare(strict_types=1): wp eval-file runs this through eval(), where it is a fatal error.

if (! defined('WP_CLI') || ! WP_CLI) {
    exit('Run with wp eval-file.');
}

const TSHARP_SAMPLE = '_tickets_sharp_sample';

$tsharpArgs = isset($args) && is_array($args) ? $args : [];

/**
 * Removes every sample post (event and ticket products).
 */
function tsharp_seed_reset(): void
{
    $ids = get_posts([
        'post_type' => ['tribe_events', 'product'],
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_key' => TSHARP_SAMPLE,
    ]);

    foreach ($ids as $id) {
        wp_delete_post((int) $id, true);
    }

    WP_CLI::log(sprintf('Removed %d sample posts.', count($ids)));
}

tsharp_seed_reset();

if (in_array('reset', $tsharpArgs, true)) {
    return;
}

if (! class_exists(TicketsSharp\Commerce\Provider::class) || ! function_exists('tribe') || ! tribe(TicketsSharp\Commerce\Provider::class)) {
    WP_CLI::error('Tickets Sharp is not running. Check that Event Tickets and WooCommerce are active, then activate tickets-sharp.');
}
if (! function_exists('tribe_create_event')) {
    WP_CLI::error('The Events Calendar is not active.');
}

/** @var TicketsSharp\Commerce\Provider $tsharpProvider */
$tsharpProvider = tribe(TicketsSharp\Commerce\Provider::class);

$tsharpDate = gmdate('Y-m-d', strtotime('+3 weeks'));
$tsharpEvent = tribe_create_event([
    'post_title' => 'Tickets Sharp sample: Winter Convention',
    'post_content' => 'A sample event whose tickets are sold through WooCommerce by Tickets Sharp.',
    'post_status' => 'publish',
    'EventStartDate' => $tsharpDate,
    'EventEndDate' => $tsharpDate,
    'EventStartHour' => '10',
    'EventStartMinute' => '00',
    'EventEndHour' => '16',
    'EventEndMinute' => '00',
]);

if (! $tsharpEvent) {
    WP_CLI::error('Could not create the sample event.');
}

update_post_meta($tsharpEvent, TSHARP_SAMPLE, 1);
// wp_slash(): update_post_meta() unslashes its value, which would strip the namespace separators.
update_post_meta($tsharpEvent, '_tribe_default_ticket_provider', wp_slash(TicketsSharp\Commerce\Provider::class));

$tsharpTickets = [
    'Adult' => ['price' => '15', 'tribe-ticket' => ['mode' => 'capped', 'capacity' => '20', 'event_capacity' => '30']],
    'Concession' => ['price' => '8', 'tribe-ticket' => ['mode' => 'global', 'event_capacity' => '30']],
    'Workshop' => ['price' => '25', 'tribe-ticket' => ['mode' => 'own', 'capacity' => '10']],
];

$tsharpOrder = 0;
foreach ($tsharpTickets as $tsharpName => $tsharpTicket) {
    $tsharpId = $tsharpProvider->ticket_add($tsharpEvent, [
        'ticket_name' => $tsharpName,
        'ticket_price' => $tsharpTicket['price'],
        'ticket_description' => sprintf('%s ticket for the sample event.', $tsharpName),
        'ticket_show_description' => 'yes',
        'ticket_menu_order' => $tsharpOrder++,
        'tribe-ticket' => $tsharpTicket['tribe-ticket'],
    ]);

    if (! $tsharpId) {
        WP_CLI::error(sprintf('Could not create the "%s" ticket.', $tsharpName));
    }

    update_post_meta((int) $tsharpId, TSHARP_SAMPLE, 1);
    WP_CLI::log(sprintf('Ticket "%s": product #%d', $tsharpName, $tsharpId));

    if ($tsharpName === 'Workshop') {
        update_post_meta(
            (int) $tsharpId,
            TicketsSharp\Support\Keys::PRODUCT_FIELDS,
            TicketsSharp\Attendees\FieldSet::fromText("Name | name | required\nEmail | email | required\nDietary needs | select | optional | None, Vegetarian, Vegan")
        );
    }
}

TicketsSharp\Lifecycle::ensureDetailsPage(new TicketsSharp\Support\Settings());

WP_CLI::success(sprintf('Sample event: %s', get_permalink($tsharpEvent)));
