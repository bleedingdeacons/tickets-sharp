<?php

/**
 * Uninstall: removes Tickets Sharp's settings and the attendee details page it
 * created. Attendees, ticket products and order data are sales records and are
 * deliberately left in place.
 *
 * @package TicketsSharp
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$tsharpSettings = get_option('tickets_sharp_settings', []);

if (is_array($tsharpSettings) && is_numeric($tsharpSettings['details_page_id'] ?? null)) {
    $tsharpPage = get_post((int) $tsharpSettings['details_page_id']);
    if ($tsharpPage instanceof WP_Post && str_contains($tsharpPage->post_content, '[tickets_sharp_attendees]')) {
        wp_trash_post($tsharpPage->ID);
    }
}

delete_option('tickets_sharp_settings');
