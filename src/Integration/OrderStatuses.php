<?php

declare(strict_types=1);

namespace TicketsSharp\Integration;

use TicketsSharp\Orders\StatusMap;
use TicketsSharp\Support\Settings;
use Tribe__Tickets__Status__Abstract_Commerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Event Tickets' per-provider status manager for Tickets Sharp.
 *
 * Event Tickets instantiates this itself (`new $class()`) for each active
 * provider slug in `Tribe__Tickets__Status__Manager::$status_managers`, so the
 * constructor takes no arguments.
 */
final class OrderStatuses extends Tribe__Tickets__Status__Abstract_Commerce
{
    public function __construct(?StatusMap $map = null)
    {
        $this->completed_status_id = StatusMap::COMPLETED;
        $this->initializeFrom($map ?? new StatusMap(new Settings()));
    }

    public function initialize_status_classes(): void
    {
        $this->initializeFrom(new StatusMap(new Settings()));
    }

    private function initializeFrom(StatusMap $map): void
    {
        $this->statuses = [];
        $this->status_names = [];

        foreach ($map->definitions() as $slug => $definition) {
            $this->status_names[] = $slug;
            $this->statuses[$slug] = new OrderStatus($definition['name'], $slug, $definition['flags']);
        }
    }
}
