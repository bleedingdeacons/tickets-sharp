<?php

declare(strict_types=1);

namespace TicketsSharp\Commerce;

use TicketsSharp\Support\Settings;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The plugin's own front-end URLs.
 */
class Urls
{
    public const CART_QUERY_VAR = 'tsharp_cart';

    public function __construct(private readonly Settings $settings)
    {
    }

    /** Where the Event Tickets form posts; {@see CartHandler}. */
    public function cartHandler(): string
    {
        return add_query_arg(self::CART_QUERY_VAR, '1', home_url('/'));
    }

    /** The attendee details page, or '' when none is configured. */
    public function detailsPage(): string
    {
        $pageId = $this->settings->detailsPageId();
        if ($pageId <= 0) {
            return '';
        }
        $url = get_permalink($pageId);

        return is_string($url) ? $url : '';
    }
}
