<?php

declare(strict_types=1);

namespace TicketsSharp\Integration;

use Tribe__Tickets__Status__Abstract;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One WooCommerce order status, described in Event Tickets' terms.
 *
 * Event Tickets' own providers declare a class per status. One configurable
 * class does the same job: the flags are plain public properties on the base.
 */
final class OrderStatus extends Tribe__Tickets__Status__Abstract
{
    /**
     * @param array<string, bool> $flags Event Tickets status flags (count_completed, incomplete...).
     */
    public function __construct(string $name, string $providerName, array $flags = [])
    {
        $this->name = $name;
        $this->provider_name = $providerName;
        $this->post_type = 'shop_order';

        foreach ($flags as $flag => $value) {
            if (property_exists($this, $flag)) {
                $this->{$flag} = $value;
            }
        }
    }
}
