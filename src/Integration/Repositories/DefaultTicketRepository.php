<?php

declare(strict_types=1);

namespace TicketsSharp\Integration\Repositories;

use TicketsSharp\Support\Keys;
use Tribe__Tickets__Ticket_Repository;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Event Tickets' cross-provider ticket repository (`tribe_tickets()`), with
 * Tickets Sharp's ticket products included.
 *
 * A ticket here is a WooCommerce `product`, and so is everything else in the
 * shop. Listing `product` as a ticket type would make every product a ticket,
 * so the constructor adds one clause: a product only counts when it carries
 * the ticket → event meta key.
 */
class DefaultTicketRepository extends Tribe__Tickets__Ticket_Repository
{
    public function __construct()
    {
        parent::__construct();

        global $wpdb;
        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;
        $key = Keys::TICKET_EVENT;

        $this->filter_query->where(
            "( {$posts}.post_type <> 'product' OR EXISTS ( SELECT 1 FROM {$postmeta} tsharp_ticket_event"
            . " WHERE tsharp_ticket_event.post_id = {$posts}.ID AND tsharp_ticket_event.meta_key = '{$key}' ) )",
            'tsharp-only-ticket-products'
        );
    }

    /** @return array<string, string> */
    public function ticket_types()
    {
        return array_merge(parent::ticket_types(), [Keys::ORM_PROVIDER => 'product']);
    }

    /** @return array<string, string> */
    public function ticket_to_event_keys()
    {
        return array_merge(parent::ticket_to_event_keys(), [Keys::ORM_PROVIDER => Keys::TICKET_EVENT]);
    }
}
