<?php

declare(strict_types=1);

namespace TicketsSharp\Admin;

use TicketsSharp\Commerce\Provider;
use TicketsSharp\Support\Keys;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Event Tickets' Tickets › All Tickets screen, for Tickets Sharp.
 *
 * Event Tickets lists a provider's tickets by querying its ticket post type.
 * The provider registers itself on that screen through Event Tickets' base
 * class (`filter_admin_tickets_table_provider_info()`). For Tickets Sharp that
 * post type is `product`, which on its own would list every product in the
 * shop, so this narrows the query to products linked to an event.
 *
 * The screen opens on whichever provider comes first in that registration
 * list, which would otherwise be Event Tickets' own Tickets Commerce; this
 * moves Tickets Sharp to the front so the screen opens on its tickets. The
 * provider dropdown still switches to any other.
 */
class TicketsTable
{
    public function register(): void
    {
        add_filter('tec_tickets_admin_tickets_table_query_args', [$this, 'onlyTicketProducts']);
        // After the providers have added themselves at the default priority.
        add_filter('tec_tickets_admin_tickets_table_provider_info', [$this, 'putFirst'], 20);
    }

    /**
     * @param mixed $providers Provider class => its details.
     * @return mixed
     */
    public function putFirst($providers)
    {
        if (! is_array($providers) || ! isset($providers[Provider::class])) {
            return $providers;
        }

        return [Provider::class => $providers[Provider::class]] + $providers;
    }

    /**
     * @param mixed $args
     * @return mixed
     */
    public function onlyTicketProducts($args)
    {
        if (! is_array($args) || ($args['post_type'] ?? null) !== 'product') {
            return $args;
        }

        $ours = ['key' => Keys::TICKET_EVENT, 'compare' => 'EXISTS'];
        $existing = $args['meta_query'] ?? [];

        $args['meta_query'] = is_array($existing) && $existing !== []
            ? ['relation' => 'AND', $existing, $ours]
            : [$ours];

        return $args;
    }
}
