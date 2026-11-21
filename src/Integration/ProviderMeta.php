<?php

declare(strict_types=1);

namespace TicketsSharp\Integration;

use TicketsSharp\Commerce\Provider;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the backslashes in an event's saved ticket provider.
 *
 * Event Tickets stores the provider chosen for an event as a class name in
 * `_tribe_default_ticket_provider`. WordPress unslashes every meta value
 * before saving it, so `TicketsSharp\Commerce\Provider` is stored as
 * `TicketsSharpCommerceProvider`. That names no provider, so Event Tickets
 * treats the event as having none and lists none of its tickets. Event Tickets
 * puts the backslashes back only for its own Tickets Commerce class (in the
 * same sanitize filter, at the default priority); this does the same for
 * Tickets Sharp's.
 *
 * Events saved before this fix keep the stripped value, so they are repaired
 * once, on the first admin request after the upgrade.
 */
final class ProviderMeta
{
    /** Event Tickets' `Tribe__Tickets__Tickets_Handler::$key_provider_field`. */
    public const FIELD = '_tribe_default_ticket_provider';

    /** Set once the stored values have been repaired. */
    public const REPAIRED_OPTION = 'tickets_sharp_provider_meta_repaired';

    /** @var \Closure(string): list<int> */
    private readonly \Closure $findPosts;

    /**
     * @param (\Closure(string): list<int>)|null $findPosts Finds the posts storing a given provider value;
     *                                                    injectable because the default queries `$wpdb`.
     */
    public function __construct(?\Closure $findPosts = null)
    {
        $this->findPosts = $findPosts ?? self::postsStoring(...);
    }

    public function register(): void
    {
        // After Event Tickets' own filter, which leaves other values alone.
        add_filter('sanitize_post_meta_' . self::FIELD, [$this, 'keepSlashes'], 20);
        add_action('admin_init', [$this, 'repairOnce']);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public function keepSlashes($value)
    {
        return $value === self::stripped() ? Provider::class : $value;
    }

    public function repairOnce(): void
    {
        if (get_option(self::REPAIRED_OPTION)) {
            return;
        }

        $this->repair();
        update_option(self::REPAIRED_OPTION, 1, false);
    }

    /**
     * Rewrites every stored stripped value. The write goes through
     * {@see keepSlashes()}, so it stores the class name intact.
     *
     * @return list<int> The repaired post IDs.
     */
    public function repair(): array
    {
        $ids = ($this->findPosts)(self::stripped());
        foreach ($ids as $id) {
            update_post_meta($id, self::FIELD, Provider::class);
        }

        return $ids;
    }

    /** The provider class name as WordPress stores it without this fix. */
    public static function stripped(): string
    {
        return stripslashes(Provider::class);
    }

    /**
     * @return list<int>
     */
    private static function postsStoring(string $value): array
    {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                self::FIELD,
                $value
            )
        );

        return array_values(array_map('intval', $ids));
    }
}
