<?php

declare(strict_types=1);

namespace TicketsSharp\Emails;

use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A per-event image in the ticket e-mail.
 *
 * Each event gets a "Ticket e-mail image" box in its editor, where an image is
 * chosen from the media library. Event Tickets' ticket e-mail (one is sent per
 * event, so the image always belongs to it) then shows that image between the
 * event title and the tickets. An event without one sends the e-mail as before.
 */
class TicketImage
{
    public const META = '_tsharp_ticket_image_id';
    public const NONCE = 'tsharp_ticket_image_nonce';
    public const INPUT = 'tsharp_ticket_image_id';

    /** Event Tickets' "Tickets Emails" event title, which the image follows. */
    public const HOOK = 'tribe_template_after_include:tickets/emails/template-parts/body/post-title';

    /** @var list<string> */
    private const POST_TYPES = ['tribe_events'];

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'renderForTemplate'], 10, 3);
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
        add_action('save_post', [$this, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function addMetaBox(): void
    {
        foreach (self::POST_TYPES as $type) {
            add_meta_box('tickets-sharp-ticket-image', __('Ticket e-mail image', 'tickets-sharp'), [$this, 'renderMetaBox'], $type, 'side', 'low');
        }
    }

    /**
     * @param mixed $post
     */
    public function renderMetaBox($post): void
    {
        if ($post instanceof WP_Post) {
            echo $this->metaBoxHtml($post->ID); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
        }
    }

    public function metaBoxHtml(int $postId): string
    {
        $imageId = $this->imageId($postId);
        $preview = $imageId > 0 ? (string) wp_get_attachment_image_url($imageId, 'medium') : '';

        return '<div class="tsharp-ticket-image">'
            . wp_nonce_field(self::NONCE, self::NONCE, true, false)
            . '<input type="hidden" class="tsharp-ticket-image__id" name="' . esc_attr(self::INPUT) . '" value="' . esc_attr($imageId > 0 ? (string) $imageId : '') . '">'
            . '<p><img class="tsharp-ticket-image__preview" src="' . esc_url($preview) . '" alt="" style="max-width:100%;height:auto;' . ($preview === '' ? 'display:none;' : '') . '"></p>'
            . '<p><button type="button" class="button tsharp-ticket-image__choose">' . esc_html__('Choose image', 'tickets-sharp') . '</button> '
            . '<button type="button" class="button-link tsharp-ticket-image__remove"' . ($imageId > 0 ? '' : ' style="display:none;"') . '>' . esc_html__('Remove', 'tickets-sharp') . '</button></p>'
            . '<p class="description">' . esc_html__('Shown in the ticket e-mail for this event, above the tickets.', 'tickets-sharp') . '</p>'
            . '</div>';
    }

    /**
     * @param mixed $postId
     * @param mixed $post
     */
    public function save($postId, $post = null): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified here.
        $postId = is_numeric($postId) ? (int) $postId : 0;
        $nonce = isset($_POST[self::NONCE]) && is_string($_POST[self::NONCE]) ? wp_unslash($_POST[self::NONCE]) : '';
        if (
            $postId <= 0
            || ! $post instanceof WP_Post
            || ! in_array($post->post_type, self::POST_TYPES, true)
            || ! wp_verify_nonce($nonce, self::NONCE)
            || ! current_user_can('edit_post', $postId)
        ) {
            return;
        }

        $value = isset($_POST[self::INPUT]) && is_scalar($_POST[self::INPUT]) ? absint(wp_unslash($_POST[self::INPUT])) : 0;
        // phpcs:enable

        if ($value > 0 && get_post_type($value) === 'attachment') {
            update_post_meta($postId, self::META, $value);
        } else {
            delete_post_meta($postId, self::META);
        }
    }

    /**
     * The media picker for the meta box, on event edit screens only.
     *
     * @param mixed $hook
     */
    public function enqueue($hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen instanceof \WP_Screen || ! in_array($screen->post_type, self::POST_TYPES, true)) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script('tickets-sharp-ticket-image', TICKETS_SHARP_URL . 'assets/js/ticket-image.js', ['jquery'], TICKETS_SHARP_VERSION, true);
        wp_localize_script('tickets-sharp-ticket-image', 'tsharpTicketImage', [
            'title' => __('Ticket e-mail image', 'tickets-sharp'),
            'button' => __('Use this image', 'tickets-sharp'),
        ]);
    }

    /**
     * @param mixed $file
     * @param mixed $name
     * @param mixed $template A Tribe__Template; its `post_id` is the e-mail's event.
     */
    public function renderForTemplate($file, $name, $template): void
    {
        if (! is_object($template) || ! method_exists($template, 'get')) {
            return;
        }

        echo $this->row($this->eventOf($template->get('post_id'), $template->get('tickets'))); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }

    /**
     * The e-mail table row holding an event's ticket image, or ''.
     */
    public function row(int $eventId): string
    {
        $imageId = $this->imageId($eventId);
        if ($imageId <= 0) {
            return '';
        }

        $url = wp_get_attachment_image_url($imageId, 'large');
        if (! is_string($url) || $url === '') {
            return '';
        }

        $alt = get_post_meta($imageId, '_wp_attachment_image_alt', true);
        $alt = is_string($alt) && $alt !== '' ? $alt : (string) get_the_title($eventId);

        return '<tr><td style="padding:0" class="tec-tickets__email-table-content-event-image-container tsharp-ticket-image">'
            . '<img class="tec-tickets__email-table-content-event-image" style="display:block;max-width:100%;height:auto;" src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" />'
            . '</td></tr>';
    }

    public function imageId(int $eventId): int
    {
        if ($eventId <= 0) {
            return 0;
        }
        $value = get_post_meta($eventId, self::META, true);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The e-mail's event: its `post_id`, or the first ticket's event.
     */
    private function eventOf(mixed $postId, mixed $tickets): int
    {
        if (is_numeric($postId) && (int) $postId > 0) {
            return (int) $postId;
        }
        if (is_array($tickets)) {
            $first = reset($tickets);
            if (is_array($first) && is_numeric($first['event_id'] ?? null)) {
                return (int) $first['event_id'];
            }
        }

        return 0;
    }
}
