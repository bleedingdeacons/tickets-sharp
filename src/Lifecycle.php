<?php

declare(strict_types=1);

namespace TicketsSharp;

use TicketsSharp\Attendees\DetailsPage;
use TicketsSharp\Support\Settings;
use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Activation: make sure the attendee details page exists.
 */
final class Lifecycle
{
    public static function activate(): void
    {
        self::ensureDetailsPage(new Settings());
    }

    /**
     * Creates the attendee details page unless the configured one still exists.
     *
     * @return int The page ID.
     */
    public static function ensureDetailsPage(Settings $settings): int
    {
        $existing = $settings->detailsPageId();
        if ($existing > 0) {
            $page = get_post($existing);
            if ($page instanceof WP_Post && $page->post_status !== 'trash') {
                return $existing;
            }
        }

        $pageId = wp_insert_post(
            [
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => __('Attendee details', 'tickets-sharp'),
                'post_name' => 'attendee-details',
                'post_content' => '<!-- wp:shortcode -->[' . DetailsPage::SHORTCODE . ']<!-- /wp:shortcode -->',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ]
        );

        if (! is_int($pageId) || $pageId <= 0) {
            return 0;
        }

        $settings->update(['details_page_id' => $pageId]);

        return $pageId;
    }
}
