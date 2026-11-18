<?php

declare(strict_types=1);

namespace TicketsSharp\Attendees;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Attendee information fields: what a ticket asks of each attendee.
 *
 * Stored per ticket product as a list of definitions. An admin edits them as
 * plain lines in the ticket form's "Attendee information" box, one field per line:
 *
 *     Label | type | required | Option A, Option B
 *
 * Types: name, email, text, tel, textarea, select, checkbox. The first `name`
 * field becomes the attendee's name on the ticket, and the first `email` field
 * the address the ticket is sent to; without them the buyer's billing name and
 * address are used.
 *
 * @phpstan-type Field array{key: string, label: string, type: string, required: bool, options: list<string>}
 */
final class FieldSet
{
    public const TYPES = ['name', 'email', 'text', 'tel', 'textarea', 'select', 'checkbox'];

    private function __construct()
    {
    }

    /**
     * @return list<Field>
     */
    public static function parse(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($raw)) {
            return [];
        }

        $fields = [];
        $seen = [];
        foreach ($raw as $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $field = self::normalise($definition);
            if ($field === null || isset($seen[$field['key']])) {
                continue;
            }
            $seen[$field['key']] = true;
            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Parses the admin's one-field-per-line text.
     *
     * @return list<Field>
     */
    public static function fromText(string $text): array
    {
        $definitions = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $definitions[] = [
                'label' => $parts[0],
                'type' => strtolower($parts[1] ?? 'text'),
                'required' => in_array(strtolower($parts[2] ?? ''), ['required', 'yes', 'true', '1', 'y'], true),
                'options' => isset($parts[3]) ? array_map('trim', explode(',', $parts[3])) : [],
            ];
        }

        return self::parse($definitions);
    }

    /**
     * @param list<Field> $fields
     */
    public static function toText(array $fields): string
    {
        $lines = [];
        foreach ($fields as $field) {
            $line = $field['label'] . ' | ' . $field['type'] . ' | ' . ($field['required'] ? 'required' : 'optional');
            if ($field['options'] !== []) {
                $line .= ' | ' . implode(', ', $field['options']);
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Validates one attendee's answers.
     *
     * @param list<Field>          $fields
     * @param array<mixed, mixed>  $input
     * @return array{values: array<string, string>, errors: list<string>}
     */
    public static function validate(array $fields, array $input): array
    {
        $values = [];
        $errors = [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $raw = $input[$key] ?? '';
            $value = is_scalar($raw) ? trim((string) $raw) : '';

            if ($field['type'] === 'checkbox') {
                $value = $value !== '' && $value !== '0' ? 'yes' : '';
            } elseif ($field['type'] === 'textarea') {
                $value = sanitize_textarea_field($value);
            } else {
                $value = sanitize_text_field($value);
            }

            if ($value === '') {
                if ($field['required']) {
                    /* translators: %s: field label. */
                    $errors[] = sprintf(__('%s is required.', 'tickets-sharp'), $field['label']);
                }
                $values[$key] = '';
                continue;
            }

            if ($field['type'] === 'email' && ! is_email($value)) {
                /* translators: %s: field label. */
                $errors[] = sprintf(__('%s must be an email address.', 'tickets-sharp'), $field['label']);
            } elseif ($field['type'] === 'select' && ! in_array($value, $field['options'], true)) {
                /* translators: %s: field label. */
                $errors[] = sprintf(__('Choose one of the options for %s.', 'tickets-sharp'), $field['label']);
            } elseif ($field['type'] === 'tel' && preg_match('/^[0-9 +().\-]{5,}$/', $value) !== 1) {
                /* translators: %s: field label. */
                $errors[] = sprintf(__('%s must be a telephone number.', 'tickets-sharp'), $field['label']);
            }

            $values[$key] = $value;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * @param list<Field>           $fields
     * @param array<string, string> $values
     */
    public static function holderName(array $fields, array $values): string
    {
        return self::firstOfType($fields, $values, 'name');
    }

    /**
     * @param list<Field>           $fields
     * @param array<string, string> $values
     */
    public static function holderEmail(array $fields, array $values): string
    {
        return self::firstOfType($fields, $values, 'email');
    }

    /**
     * @param list<Field>           $fields
     * @param array<string, string> $values
     */
    private static function firstOfType(array $fields, array $values, string $type): string
    {
        foreach ($fields as $field) {
            if ($field['type'] === $type) {
                return $values[$field['key']] ?? '';
            }
        }

        return '';
    }

    /**
     * @param array<mixed, mixed> $definition
     * @return Field|null
     */
    private static function normalise(array $definition): ?array
    {
        $label = is_scalar($definition['label'] ?? null) ? trim(wp_strip_all_tags((string) $definition['label'])) : '';
        if ($label === '') {
            return null;
        }

        $type = is_string($definition['type'] ?? null) ? strtolower(trim($definition['type'])) : 'text';
        if (! in_array($type, self::TYPES, true)) {
            $type = 'text';
        }

        $key = is_string($definition['key'] ?? null) && $definition['key'] !== ''
            ? self::key($definition['key'])
            : self::key($label);
        if ($key === '') {
            return null;
        }

        $options = [];
        if ($type === 'select' && is_array($definition['options'] ?? null)) {
            foreach ($definition['options'] as $option) {
                if (is_scalar($option) && trim((string) $option) !== '') {
                    $options[] = trim(wp_strip_all_tags((string) $option));
                }
            }
            $options = array_values(array_unique($options));
            if ($options === []) {
                $type = 'text';
            }
        }

        return [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => ! empty($definition['required']),
            'options' => $options,
        ];
    }

    private static function key(string $value): string
    {
        $key = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $value));

        return trim($key, '_');
    }
}
