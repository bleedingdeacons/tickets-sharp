<?php

declare(strict_types=1);

namespace TicketsSharp\Tests\Support;

use BleedingDeacons\WpMocks\WpState;
use TicketsSharp\Support\Keys;
use WP_Post;

/**
 * wp-mocks' WP_Post with the GMT date property WordPress's has, so tests can
 * set it without creating a dynamic property.
 */
final class TestPost extends WP_Post
{
    public string $post_date_gmt = '';

    /**
     * Registers a post where get_post(), get_post_type() and get_posts() see it.
     *
     * @param array<string, mixed> $props
     */
    public static function add(int $id, string $type, array $props = []): self
    {
        $post = new self(array_merge(['ID' => $id, 'post_type' => $type, 'post_title' => 'Post ' . $id], $props));

        WpState::$posts[$id] = $post;
        WpState::$postTypes[$id] = $type;
        WpState::$postStatuses[$id] = $post->post_status;

        return $post;
    }

    /**
     * An attendee post with its meta.
     *
     * @param array<string, mixed> $meta
     */
    public static function attendee(int $id, array $meta = [], string $createdGmt = ''): self
    {
        $post = self::add($id, Keys::ATTENDEE_POST_TYPE, ['post_date_gmt' => $createdGmt]);

        foreach ($meta as $key => $value) {
            WpState::$postMeta[$id][$key] = $value;
        }

        return $post;
    }
}
