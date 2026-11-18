<?php

/**
 * Stand-ins for the parts of The Events Calendar Tickets Sharp touches.
 *
 * The Events Calendar is optional at runtime — Event Tickets can sell tickets
 * on any post type — but it is the expected host, and its event post type is
 * what the seed script and the tests use.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps

if (! class_exists('Tribe__Events__Main')) {
    class Tribe__Events__Main
    {
        public const POSTTYPE = 'tribe_events';
    }
}

if (! function_exists('tribe_is_event')) {
    /**
     * @param int|WP_Post|null $postId
     */
    function tribe_is_event($postId = null): bool
    {
        $type = get_post_type(is_object($postId) ? $postId->ID : (int) $postId);

        return $type === Tribe__Events__Main::POSTTYPE;
    }
}
