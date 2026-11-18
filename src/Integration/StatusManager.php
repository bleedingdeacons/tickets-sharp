<?php

declare(strict_types=1);

namespace TicketsSharp\Integration;

use TicketsSharp\Commerce\Provider;
use TicketsSharp\Support\Keys;
use Tribe__Tickets__Status__Manager;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Teaches Event Tickets' status manager about the Tickets Sharp provider.
 *
 * `Tribe__Tickets__Status__Manager::$module_slugs` and `$status_managers` are
 * protected arrays with no filter. Until a provider class is in the first,
 * Event Tickets treats it as inactive (`tribe_tickets_is_provider_active()`),
 * which breaks the REST ticket save, the tickets block and check-in.
 *
 * Rather than rebinding `tickets.status` (Event Tickets resolves it during its
 * own boot, so other objects may already hold that instance), this patches the
 * live instance. PHP lets a subclass reach a protected member of any object of
 * the declaring class, which is the only reason this class extends it: it is
 * never instantiated.
 */
final class StatusManager extends Tribe__Tickets__Status__Manager
{
    public static function register(Tribe__Tickets__Status__Manager $manager): void
    {
        if (! is_array($manager->module_slugs) || ! is_array($manager->status_managers)) {
            return;
        }

        $manager->module_slugs[Provider::class] = Keys::PROVIDER_SLUG;
        $manager->status_managers[Keys::PROVIDER_SLUG] = OrderStatuses::class;
    }

    /**
     * Whether the members this relies on still exist on Event Tickets' class.
     * Checked at boot; the plugin stands down with a notice when it is false.
     */
    public static function isCompatible(): bool
    {
        return property_exists(Tribe__Tickets__Status__Manager::class, 'module_slugs')
            && property_exists(Tribe__Tickets__Status__Manager::class, 'status_managers');
    }
}
