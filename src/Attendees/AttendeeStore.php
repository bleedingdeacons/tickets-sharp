<?php

declare(strict_types=1);

namespace TicketsSharp\Attendees;

use TicketsSharp\Support\Keys;
use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reads and writes Tickets Sharp attendee posts.
 *
 * Attendees are written directly rather than through Event Tickets' ORM
 * (`create_attendee_for_ticket()`): the ORM resolves meta keys through aliases
 * and repository maps that this plugin already has to patch in, and a direct
 * write keeps what lands in the database plain and testable. Event Tickets'
 * screens still find these posts through the repositories in
 * `TicketsSharp\Integration\Repositories`.
 */
class AttendeeStore
{
    /**
     * Registers the private attendee post type.
     */
    public function registerPostType(): void
    {
        register_post_type(
            Keys::ATTENDEE_POST_TYPE,
            [
                'label' => __('Ticket attendees', 'tickets-sharp'),
                'public' => false,
                'show_ui' => false,
                'show_in_rest' => false,
                'exclude_from_search' => true,
                'publicly_queryable' => false,
                'rewrite' => false,
                'query_var' => false,
                'supports' => ['title', 'custom-fields'],
                'capability_type' => 'post',
            ]
        );
    }

    /**
     * @param array{
     *     event_id: int,
     *     product_id: int,
     *     order_id: int,
     *     order_item_id: int,
     *     status: string,
     *     full_name: string,
     *     email: string,
     *     purchaser_name: string,
     *     purchaser_email: string,
     *     user_id: int,
     *     price_paid: string,
     *     currency: string,
     *     optout: bool,
     *     fields: array<string, string>,
     *     security_code: string
     * } $data
     */
    public function create(array $data): int
    {
        $title = sprintf('%d | %s | %d', $data['order_id'], $data['full_name'], $data['product_id']);

        $id = wp_insert_post(
            [
                'post_type' => Keys::ATTENDEE_POST_TYPE,
                'post_status' => 'publish',
                'post_title' => $title,
                'ping_status' => 'closed',
                'comment_status' => 'closed',
            ],
            true
        );

        if (! is_int($id) || $id <= 0) {
            return 0;
        }

        $meta = [
            Keys::ATTENDEE_EVENT => $data['event_id'],
            Keys::ATTENDEE_PRODUCT => $data['product_id'],
            Keys::ATTENDEE_ORDER => $data['order_id'],
            Keys::ATTENDEE_ORDER_ITEM => $data['order_item_id'],
            Keys::ATTENDEE_STATUS => $data['status'],
            Keys::ATTENDEE_FULL_NAME => $data['full_name'],
            Keys::ATTENDEE_EMAIL => $data['email'],
            Keys::ATTENDEE_PURCHASER_NAME => $data['purchaser_name'],
            Keys::ATTENDEE_PURCHASER_EMAIL => $data['purchaser_email'],
            Keys::ATTENDEE_USER_ID => $data['user_id'],
            Keys::ATTENDEE_PRICE_PAID => $data['price_paid'],
            Keys::ATTENDEE_CURRENCY => $data['currency'],
            Keys::ATTENDEE_OPTOUT => $data['optout'] ? '1' : '',
            Keys::ATTENDEE_FIELDS => $data['fields'],
            Keys::ATTENDEE_SECURITY_CODE => $data['security_code'],
            Keys::ATTENDEE_CHECKIN => '',
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($id, $key, $value);
        }

        return $id;
    }

    /**
     * @return list<WP_Post>
     */
    public function byOrder(int $orderId): array
    {
        return $this->query(Keys::ATTENDEE_ORDER, $orderId);
    }

    /**
     * @return list<WP_Post>
     */
    public function byTicket(int $productId): array
    {
        return $this->query(Keys::ATTENDEE_PRODUCT, $productId);
    }

    /**
     * @return list<WP_Post>
     */
    public function byEvent(int $eventId): array
    {
        return $this->query(Keys::ATTENDEE_EVENT, $eventId);
    }

    /**
     * @return list<WP_Post>
     */
    public function byOrderItem(int $itemId): array
    {
        return $this->query(Keys::ATTENDEE_ORDER_ITEM, $itemId);
    }

    public function get(int $attendeeId): ?WP_Post
    {
        $post = get_post($attendeeId);

        return $post instanceof WP_Post && $post->post_type === Keys::ATTENDEE_POST_TYPE ? $post : null;
    }

    public function status(int $attendeeId): string
    {
        return $this->metaString($attendeeId, Keys::ATTENDEE_STATUS);
    }

    public function setStatus(int $attendeeId, string $status): void
    {
        update_post_meta($attendeeId, Keys::ATTENDEE_STATUS, $status);
    }

    public function isCheckedIn(int $attendeeId): bool
    {
        $value = get_post_meta($attendeeId, Keys::ATTENDEE_CHECKIN, true);

        return $value !== '' && $value !== false && $value !== '0' && $value !== 0 && $value !== null;
    }

    public function metaString(int $attendeeId, string $key): string
    {
        $value = get_post_meta($attendeeId, $key, true);

        return is_scalar($value) ? (string) $value : '';
    }

    public function metaInt(int $attendeeId, string $key): int
    {
        $value = get_post_meta($attendeeId, $key, true);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array<string, string>
     */
    public function fields(int $attendeeId): array
    {
        $value = get_post_meta($attendeeId, Keys::ATTENDEE_FIELDS, true);
        if (! is_array($value)) {
            return [];
        }

        $fields = [];
        foreach ($value as $key => $field) {
            if (is_string($key) && is_scalar($field)) {
                $fields[$key] = (string) $field;
            }
        }

        return $fields;
    }

    /**
     * @return list<WP_Post>
     */
    private function query(string $key, int $value): array
    {
        if ($value <= 0) {
            return [];
        }

        $posts = get_posts(
            [
                'post_type' => Keys::ATTENDEE_POST_TYPE,
                'post_status' => 'any',
                'posts_per_page' => -1,
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => true,
                'meta_query' => [
                    [
                        'key' => $key,
                        'value' => $value,
                    ],
                ],
            ]
        );

        return array_values(array_filter($posts, static fn ($post): bool => $post instanceof WP_Post));
    }
}
