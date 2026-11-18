<?php

declare(strict_types=1);

namespace TicketsSharp\Admin;

use TicketsSharp\Support\Keys;
use TicketsSharp\Support\Settings as SettingsStore;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce › Settings › Products › Tickets.
 *
 * Fields are stored inside the single `tickets_sharp_settings` option: the
 * WooCommerce settings API writes an `option[key]` id into that array.
 */
class Settings
{
    public const SECTION = 'tickets_sharp';

    public function register(): void
    {
        add_filter('woocommerce_get_sections_products', [$this, 'addSection']);
        add_filter('woocommerce_get_settings_products', [$this, 'fields'], 10, 2);
    }

    /**
     * @param mixed $sections
     * @return mixed
     */
    public function addSection($sections)
    {
        if (is_array($sections)) {
            $sections[self::SECTION] = __('Event tickets', 'tickets-sharp');
        }

        return $sections;
    }

    /**
     * @param mixed $settings
     * @param mixed $section
     * @return mixed
     */
    public function fields($settings, $section = '')
    {
        if ($section !== self::SECTION) {
            return $settings;
        }

        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        $options = [];
        foreach ($statuses as $slug => $label) {
            $options[SettingsStore::stripPrefix((string) $slug)] = $label;
        }
        unset($options['checkout-draft'], $options['cancelled'], $options['refunded'], $options['failed']);

        $option = Keys::OPTION;

        return [
            [
                'title' => __('Event tickets', 'tickets-sharp'),
                'type' => 'title',
                'desc' => __('How tickets sold through WooCommerce behave. Tickets themselves are created on each event, in the Tickets section of the event editor.', 'tickets-sharp'),
                'id' => 'tickets_sharp_options',
            ],
            [
                'title' => __('Issue tickets when an order is', 'tickets-sharp'),
                'desc' => __('Tickets are e-mailed, and can be checked in, once the order reaches one of these statuses.', 'tickets-sharp'),
                'id' => $option . '[paid_statuses]',
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'options' => $options,
                'default' => SettingsStore::DEFAULT_PAID_STATUSES,
            ],
            [
                'title' => __('Hold seats for unpaid orders (minutes)', 'tickets-sharp'),
                'desc' => __('How long an order awaiting payment keeps its seats before they are offered to others. On-hold orders keep theirs until cancelled.', 'tickets-sharp'),
                'id' => $option . '[pending_hold_minutes]',
                'type' => 'number',
                'default' => (string) SettingsStore::DEFAULT_PENDING_HOLD_MINUTES,
                'custom_attributes' => ['min' => '0', 'step' => '1'],
            ],
            [
                'title' => __('Attendee details page', 'tickets-sharp'),
                'desc' => __('The page holding the [tickets_sharp_attendees] shortcode, where buyers enter attendee information before checkout.', 'tickets-sharp'),
                'id' => $option . '[details_page_id]',
                'type' => 'single_select_page',
                'class' => 'wc-page-search',
                'args' => ['exclude' => []],
            ],
            ['type' => 'sectionend', 'id' => 'tickets_sharp_options'],
        ];
    }
}
