<?php

declare(strict_types=1);

namespace TicketsSharp\Attendees;

use TicketsSharp\Commerce\Provider;
use TicketsSharp\Support\Keys;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * An "Attendee information" column in Event Tickets' Attendees list, and the
 * same in its CSV export (the export reads columns through the table).
 */
class Columns
{
    public const COLUMN = 'tsharp_attendee_information';

    public function register(): void
    {
        add_filter('tribe_tickets_attendee_table_columns', [$this, 'addColumn'], 20, 2);
        add_filter('tribe_events_tickets_attendees_table_column', [$this, 'value'], 10, 3);
        add_filter('tribe_events_tickets_attendees_csv_export_columns', [$this, 'addExportColumn'], 20, 3);
    }

    /**
     * @param mixed $columns
     * @param mixed $eventId
     * @return mixed
     */
    public function addColumn($columns, $eventId = 0)
    {
        if (is_array($columns)) {
            $columns[self::COLUMN] = __('Attendee information', 'tickets-sharp');
        }

        return $columns;
    }

    /**
     * @param mixed $columns
     * @param mixed $items
     * @param mixed $eventId
     * @return mixed
     */
    public function addExportColumn($columns, $items = [], $eventId = 0)
    {
        return $this->addColumn($columns, $eventId);
    }

    /**
     * @param mixed $value
     * @param mixed $item
     * @param mixed $column
     * @return mixed
     */
    public function value($value, $item, $column)
    {
        if ($column !== self::COLUMN || ! is_array($item)) {
            return $value;
        }
        if (($item['provider'] ?? '') !== Provider::class) {
            return '';
        }

        $productId = is_numeric($item['product_id'] ?? null) ? (int) $item['product_id'] : 0;
        $labels = [];
        foreach (FieldSet::parse(get_post_meta($productId, Keys::PRODUCT_FIELDS, true)) as $field) {
            $labels[$field['key']] = $field['label'];
        }

        $parts = [];
        foreach ((array) ($item['attendee_meta'] ?? []) as $key => $answer) {
            if (is_scalar($answer) && (string) $answer !== '') {
                $label = $labels[(string) $key] ?? ucfirst(str_replace('_', ' ', (string) $key));
                $parts[] = esc_html($label) . ': ' . esc_html((string) $answer);
            }
        }

        return implode('; ', $parts);
    }
}
