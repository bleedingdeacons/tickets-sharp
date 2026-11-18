<?php

declare(strict_types=1);

namespace TicketsSharp\Admin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin notices explaining why Tickets Sharp is standing down.
 *
 * Problems are found on `plugins_loaded`, before translations may be loaded,
 * so they are recorded as codes and only turned into text when rendered.
 */
class Notices
{
    public const NO_WOOCOMMERCE = 'no-woocommerce';
    public const NO_EVENT_TICKETS = 'no-event-tickets';
    public const INCOMPATIBLE_EVENT_TICKETS = 'incompatible-event-tickets';
    public const CONFLICT = 'conflict';

    /** @var list<string> */
    private array $codes = [];

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function add(string $code): void
    {
        if (! in_array($code, $this->codes, true)) {
            $this->codes[] = $code;
        }
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return $this->codes;
    }

    public function message(string $code): string
    {
        return match ($code) {
            self::NO_WOOCOMMERCE => __('It needs WooCommerce to be active.', 'tickets-sharp'),
            self::NO_EVENT_TICKETS => __('It needs Event Tickets to be active.', 'tickets-sharp'),
            self::INCOMPATIBLE_EVENT_TICKETS => __('This version of Event Tickets is not supported: its status manager has changed. Update Tickets Sharp.', 'tickets-sharp'),
            self::CONFLICT => __('Another plugin already sells Event Tickets through WooCommerce. Running both would issue every ticket twice, so Tickets Sharp has stood down.', 'tickets-sharp'),
            default => $code,
        };
    }

    public function render(): void
    {
        if ($this->codes === [] || ! current_user_can('activate_plugins')) {
            return;
        }

        foreach ($this->codes as $code) {
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Tickets Sharp is not running.', 'tickets-sharp'),
                esc_html($this->message($code))
            );
        }
    }
}
