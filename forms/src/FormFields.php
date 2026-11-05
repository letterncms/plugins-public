<?php

declare(strict_types=1);

namespace Cms\Forms;

/** Form field blocks (category "form") and helpers to read fields out of a form's block tree. */
final class FormFields
{
    public const array TYPES = ['text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'radio', 'checkboxes', 'checkbox', 'consent', 'file', 'hidden'];

    /** @return list<array> block definitions */
    public static function blocks(): array
    {
        $common = [
            ['handle' => 'label', 'type' => 'text', 'label' => 'Label', 'required' => true],
            ['handle' => 'name', 'type' => 'text', 'label' => 'Field Name', 'instructions' => 'Key in submissions and emails, like company_name.', 'pattern' => '^[a-z][a-z0-9_]{0,40}$'],
            ['handle' => 'required', 'type' => 'toggle', 'label' => 'Required'],
            ['handle' => 'help', 'type' => 'text', 'label' => 'Help Text'],
        ];
        $placeholder = ['handle' => 'placeholder', 'type' => 'text', 'label' => 'Placeholder'];
        $options = ['handle' => 'options', 'type' => 'textarea', 'label' => 'Options', 'instructions' => 'One per line. Use value|Label to store a different value.', 'default' => "Option one\nOption two"];
        $condition = ['handle' => 'show_if', 'type' => 'group', 'label' => 'Only Show When', 'fields' => [
            ['handle' => 'field', 'type' => 'text', 'label' => 'Field Name', 'width' => 'third'],
            ['handle' => 'op', 'type' => 'select', 'label' => 'Is', 'options' => ['equals' => __('equal to'), 'not' => __('not equal to'), 'filled' => __('filled in'), 'empty' => __('empty')], 'default' => 'equals', 'width' => 'third'],
            ['handle' => 'value', 'type' => 'text', 'label' => 'Value', 'width' => 'third'],
        ]];

        $defs = [
            'text' => [__('Short Text'), __('One line of text'), 'text', [$placeholder, ['handle' => 'max', 'type' => 'number', 'label' => 'Max Length', 'default' => 200], ['handle' => 'autocomplete', 'type' => 'select', 'label' => 'Autofill', 'options' => ['off' => __('Off'), 'name' => __('Name'), 'given-name' => __('First name'), 'family-name' => __('Last name'), 'organization' => __('Company'), 'street-address' => __('Street address'), 'postal-code' => __('Postal code'), 'address-level2' => __('City'), 'country-name' => __('Country')]]]],
            'email' => [__('Email'), __('An email address'), 'mail', [$placeholder]],
            'tel' => [__('Phone'), __('A phone number'), 'phone', [$placeholder]],
            'url' => [__('Website'), __('A web address'), 'link', [$placeholder]],
            'number' => [__('Number'), __('A number'), 'number', [$placeholder, ['handle' => 'min', 'type' => 'number', 'label' => 'Minimum'], ['handle' => 'max', 'type' => 'number', 'label' => 'Maximum']]],
            'date' => [__('Date'), __('A date'), 'calendar', []],
            'textarea' => [__('Long Text'), __('Several lines of text'), 'paragraph', [$placeholder, ['handle' => 'rows', 'type' => 'number', 'label' => 'Rows', 'default' => 5], ['handle' => 'max', 'type' => 'number', 'label' => 'Max Length', 'default' => 5000]]],
            'select' => [__('Dropdown'), __('One option from a list'), 'select', [$options, ['handle' => 'placeholder', 'type' => 'text', 'label' => 'Empty Option', 'default' => 'Choose…']]],
            'radio' => [__('Choice'), __('One option, all in view'), 'radio', [$options]],
            'checkboxes' => [__('Checkboxes'), __('Any number of options'), 'checkbox', [$options]],
            'checkbox' => [__('Checkbox'), __('A single yes or no'), 'checkbox', []],
            'consent' => [__('Consent'), __('Agreement to a statement'), 'shield', [['handle' => 'text', 'type' => 'richtext', 'label' => 'Statement', 'inline' => true, 'default' => 'I agree to the <a href="/privacy">privacy policy</a>.']]],
            'file' => [__('File Upload'), __('A file the visitor sends'), 'upload', [['handle' => 'accept', 'type' => 'select', 'label' => 'Allowed Files', 'options' => ['documents' => __('Documents (PDF, Word)'), 'images' => __('Images'), 'any' => __('Documents and images')], 'default' => 'documents'], ['handle' => 'max_mb', 'type' => 'number', 'label' => 'Max Size (MB)', 'default' => 10, 'min' => 1, 'max' => 50]]],
            'hidden' => [__('Hidden Value'), __('A value sent unseen'), 'eye-off', [['handle' => 'value', 'type' => 'text', 'label' => 'Value']]],
        ];

        $out = [];

        foreach ($defs as $type => [$title, $description, $icon, $extra]) {
            $fields = match ($type) {
                'consent' => [['handle' => 'name', 'type' => 'text', 'label' => 'Field Name', 'default' => 'consent'], ...$extra, $condition],
                'hidden' => [['handle' => 'name', 'type' => 'text', 'label' => 'Field Name', 'required' => true], ...$extra],
                'checkbox' => [...$common, $condition],
                default => [...$common, ...$extra, $condition],
            };

            $out[] = [
                'name' => "form/{$type}",
                'title' => $title,
                'icon' => $icon,
                'category' => 'form',
                'description' => $description,
                'keywords' => ['form', 'field', 'input', $type],
                'fields' => $fields,
                'inline' => $type === 'consent' ? ['text'] : [],
                'styles' => ['spacing', 'size'],
                // The label visitors see starts as the type in sentence case: “Short text”, not “Short Text”.
                'defaults' => $type === 'consent' ? ['required' => true, 'name' => 'consent'] : ['label' => mb_strtoupper(mb_substr($title, 0, 1)).mb_strtolower(mb_substr($title, 1))],
                'render' => FieldView::field(...),
            ];
        }

        return $out;
    }

    /**
     * Fields in document order.
     *
     * @return list<array{type: string, name: string, props: array, id: string}>
     */
    public static function extract(array $nodes): array
    {
        $out = [];
        $walk = static function (array $nodes) use (&$walk, &$out): void {
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $type = (string) ($node['type'] ?? '');

                if (str_starts_with($type, 'form/') && empty($node['hidden'])) {
                    $props = (array) ($node['props'] ?? []);
                    $name = self::name($props, $type);
                    $out[] = ['type' => substr($type, 5), 'name' => $name, 'props' => $props, 'id' => (string) ($node['id'] ?? $name)];
                }

                if (! empty($node['children'])) {
                    $walk((array) $node['children']);
                }
            }
        };
        $walk($nodes);

        return $out;
    }

    public static function name(array $props, string $type): string
    {
        $name = (string) ($props['name'] ?? '');

        if (! preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name)) {
            $name = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(strip_tags((string) ($props['label'] ?? substr($type, 5))))), '_') ?: 'field';
        }

        return $name;
    }

    /** @return array<string, string> value => label */
    public static function options(array $props): array
    {
        $out = [];

        foreach (preg_split('/\r?\n/', (string) ($props['options'] ?? '')) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$value, $label] = str_contains($line, '|') ? array_map('trim', explode('|', $line, 2)) : [$line, $line];
            $out[$value] = $label;
        }

        return $out;
    }

    /** Is a field shown for the given answers? (show_if rules) */
    public static function visible(array $field, array $data): bool
    {
        $rule = (array) ($field['props']['show_if'] ?? []);

        if (empty($rule['field'])) {
            return true;
        }

        $value = $data[$rule['field']] ?? null;
        $values = array_map('strval', (array) $value);

        return match ($rule['op'] ?? 'equals') {
            'not' => ! in_array((string) ($rule['value'] ?? ''), $values, true),
            'filled' => $value !== null && $value !== '' && $value !== [],
            'empty' => $value === null || $value === '' || $value === [],
            default => in_array((string) ($rule['value'] ?? ''), $values, true),
        };
    }
}
