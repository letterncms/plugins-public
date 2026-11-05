<?php

declare(strict_types=1);

namespace Cms\Forms;

use Cms\Admin\Views;
use Cms\Cms;
use Cms\Modules\Languages\Languages;
use Cms\Users\User;

/**
 * The places of Forms, as data (see Cms\Admin\Views): the list of forms, and one form with
 * its tabs. The builder and a submission's answers are the two pieces drawn by the plugin.
 */
final class FormsViews
{
    public function __construct(private readonly Cms $cms, private readonly FormRepository $forms) {}

    public function register(): void
    {
        $admin = $this->cms->admin();
        $admin->component('forms/builder', ['label' => 'Form builder', 'description' => 'The fields of one form, edited like a page.', 'surface' => false, 'permission' => 'forms.view',
            'props' => ['type' => 'object', 'properties' => ['handle' => ['type' => 'string']], 'required' => ['handle'], 'additionalProperties' => false]]);
        $admin->component('forms/submission', ['label' => 'Form submission', 'description' => 'One submission in a dialog: its answers and files, to reply to, archive or mark as spam. Opening a new one marks it read.', 'surface' => false, 'permission' => 'forms.view',
            'props' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id'], 'additionalProperties' => false]]);

        $views = $this->cms->views();
        $views->register('forms', '/forms', fn () => $this->list(), permission: 'forms.view');
        $views->register('form', '/forms/:handle', fn (User $user, array $params) => $this->form((string) ($params['handle'] ?? ''), $user), permission: 'forms.view', private: true);
    }

    private function list(): array
    {
        return ['type' => 'ui/page', 'props' => ['title' => __('Forms'), 'icon' => 'form'], 'children' => [
            ['type' => 'ui/tabs', 'children' => [
                ['type' => 'ui/tab', 'props' => ['id' => 'forms', 'label' => __('Forms'), 'actions' => [
                    ['label' => __('New Form'), 'name' => 'new-form', 'icon' => 'plus', 'variant' => 'primary', 'ability' => 'forms/create', 'input' => ['title' => '{values.title}'], 'then' => 'open', 'to' => '/forms/{result.handle}',
                        'form' => ['title' => __('New Form'), 'text' => __('Starts with name, email and message.'), 'confirm' => __('Create'), 'fields' => [
                            ['handle' => 'title', 'type' => 'text', 'label' => __('Name'), 'placeholder' => __('Quote Request'), 'required' => true, 'autofocus' => true],
                        ]]],
                ]], 'children' => [
                    ['type' => 'ui/records', 'props' => [
                        'id' => 'forms', 'label' => __('Forms'), 'key' => 'handle',
                        'source' => ['ability' => 'forms/list'],
                        'columns' => [
                            ['key' => 'title', 'label' => __('Name'), 'icon' => 'icon'],
                            ['key' => 'enabled', 'label' => __('Status'), 'width' => '120px', 'format' => 'status', 'labels' => ['true' => __('On'), 'false' => __('Off')], 'tones' => ['true' => 'success', 'false' => 'neutral']],
                            ['key' => 'fields', 'label' => __('Fields'), 'width' => '90px', 'align' => 'end', 'format' => 'number'],
                            ['key' => 'total', 'label' => __('Submissions'), 'width' => '120px', 'align' => 'end', 'format' => 'number'],
                            ['key' => 'unread', 'label' => __('Unread'), 'width' => '90px', 'align' => 'end', 'format' => 'badge'],
                        ],
                        'open' => ['label' => __('Open'), 'to' => '/forms/{row.handle}'],
                        'actions' => [
                            ['label' => __('Fields'), 'icon' => 'field', 'to' => '/forms/{row.handle}'],
                            ['label' => __('Submissions'), 'icon' => 'inbox', 'to' => '/forms/{row.handle}?tab=submissions'],
                            ['label' => __('Settings'), 'icon' => 'settings', 'to' => '/forms/{row.handle}?tab=options'],
                        ],
                        'empty' => ['icon' => 'form', 'title' => __('No forms yet')],
                    ]],
                ]],
            ]],
        ]];
    }

    private function form(string $handle, User $user): array
    {
        $form = $this->forms->find($handle);

        if ($form === null) {
            return Views::notFound('form', ['to' => '/forms', 'label' => __('Forms')]);
        }

        $input = ['handle' => $handle];
        // Whoever may only look sees what the form does and says, as it is.
        $manage = $user->hasPermission('forms.manage');
        $tabs = [
            ['type' => 'ui/tab', 'props' => ['id' => 'fields', 'label' => __('Fields')], 'children' => [['type' => 'forms/builder', 'props' => $input]]],
            ['type' => 'ui/tab', 'props' => ['id' => 'options', 'label' => __('Settings')], 'children' => $manage
                ? [['type' => 'ui/form', 'props' => ['source' => ['ability' => 'forms/get', 'input' => $input], 'valuesAt' => 'settings', 'fields' => self::settingsFields(), 'save' => ['ability' => 'forms/configure', 'input' => $input, 'as' => 'settings']]]]
                : self::readOnly(self::settingsFields(), $form['settings'])],
        ];

        if (count($this->cms->locales()) > 1) {
            $tabs[] = ['type' => 'ui/tab', 'props' => ['id' => 'languages', 'label' => __('Languages')], 'children' => $manage
                // Fields and values both from the ability: the placeholders are the form's own words, not the admin's to translate.
                ? [['type' => 'ui/form', 'props' => ['source' => ['ability' => 'forms/words', 'input' => $input], 'save' => ['ability' => 'forms/translate', 'input' => $input, 'as' => 'words']]]]
                : self::readOnly($this->wordFields($form), self::words($form['locales']))];
        }

        $tabs[] = ['type' => 'ui/tab', 'props' => ['id' => 'submissions', 'label' => __('Submissions'), 'actions' => [
            ['label' => __('Export CSV'), 'icon' => 'download', 'href' => "/api/admin/forms/{$handle}/export"],
        ]], 'children' => [$this->submissions($form)]];

        // Renaming and deleting the form are at hand on every tab, as its name is.
        return ['type' => 'ui/page', 'props' => ['title' => $form['title'], 'icon' => 'form', 'width' => 'default', 'back' => ['to' => '/forms', 'label' => __('Forms')], 'actions' => [
            ['label' => __('Rename'), 'icon' => 'edit', 'ability' => 'forms/save', 'input' => [...$input, 'title' => '{values.title}'],
                'form' => ['title' => __('Rename Form'), 'confirm' => __('Rename'), 'values' => ['title' => $form['title']], 'fields' => [['handle' => 'title', 'type' => 'text', 'label' => __('Name'), 'required' => true, 'autofocus' => true]]]],
            ['label' => __('Delete'), 'icon' => 'trash', 'variant' => 'ghost', 'ability' => 'forms/delete', 'input' => $input, 'then' => 'open', 'to' => '/forms',
                'confirm' => ['title' => __('Delete “:title”?', ['title' => $form['title']]), 'text' => __('Submissions are kept. Pages that embed it will show nothing.'), 'confirm' => __('Delete'), 'danger' => true]],
        ]], 'children' => [['type' => 'ui/tabs', 'children' => $tabs]]];
    }

    /** The inbox of one form: its first answers as columns, read, archived or caught as spam. */
    private function submissions(array $form): array
    {
        // A field's label is the form's own word, shown as it was written.
        $columns = array_map(static fn (array $f) => ['key' => 'data.'.$f['name'], 'label' => strip_tags((string) ($f['props']['label'] ?? $f['name']))], array_slice(array_values(array_filter(FormFields::extract($form['fields']), static fn ($f) => $f['type'] !== 'hidden')), 0, 3));
        $mark = static fn (string $label, string $status) => ['label' => $label, 'ability' => 'forms/submission-update', 'input' => ['ids' => '{ids}', 'status' => $status]];

        return ['type' => 'ui/records', 'props' => [
            'id' => 'submissions', 'label' => __('Submissions'),
            'source' => ['ability' => 'forms/submissions', 'input' => ['form' => $form['handle']]],
            'search' => ['param' => 'search', 'placeholder' => __('Search…')],
            'filters' => [['param' => 'status', 'label' => __('Show'), 'all' => __('Inbox'), 'options' => self::options(['new' => __('Unread'), 'archived' => __('Archived'), 'spam' => __('Spam')])]],
            'paged' => ['perPage' => 100],
            'columns' => [
                ...$columns,
                ['key' => 'status', 'label' => __('Status'), 'width' => '100px', 'format' => 'badge', 'tones' => ['new' => 'info'], 'labels' => ['new' => __('New'), 'read' => __('Read'), 'spam' => __('Spam'), 'archived' => __('Archived')]],
                ['key' => 'createdAt', 'label' => __('Received'), 'width' => '120px', 'align' => 'end', 'format' => 'ago'],
            ],
            'open' => ['label' => __('Open'), 'component' => 'forms/submission', 'props' => ['id' => '{row.id}']],
            'bulk' => [
                $mark(__('Mark Read'), 'read'),
                $mark(__('Archive'), 'archived'),
                $mark(__('Spam'), 'spam'),
                // How many, said without a plural to get wrong.
                ['label' => __('Delete'), 'ability' => 'forms/submission-delete', 'input' => ['ids' => '{ids}'],
                    'confirm' => ['title' => __('Delete the selected submissions?'), 'text' => __('{ids.length} selected. This cannot be undone.'), 'confirm' => __('Delete'), 'danger' => true]],
            ],
            'empty' => ['icon' => 'inbox', 'title' => __('No submissions')],
        ]];
    }

    /** @param array<string, string> $labels value => words */
    private static function options(array $labels): array
    {
        return array_map(static fn ($value, $label) => ['value' => $value, 'label' => $label], array_keys($labels), $labels);
    }

    /**
     * Fields as they read, for whoever may not change them: in their sections, each with its value.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values  by handle
     */
    private static function readOnly(array $fields, array $values): array
    {
        $sections = [];

        foreach ($fields as $field) {
            if (isset($field['if']) && ! array_all((array) $field['if'], static fn ($want, $handle) => ($values[$handle] ?? null) === $want)) {
                continue;
            }

            $value = $values[$field['handle']] ?? null;
            $sections[(string) ($field['section'] ?? '')][] = ['label' => $field['label'], 'value' => is_string($value) ? trim(strip_tags($value)) : $value, ...($field['type'] === 'toggle' ? ['format' => 'yesno'] : [])];
        }

        return array_map(static fn ($title, $items) => ['type' => 'ui/section', 'props' => $title === '' ? [] : ['title' => $title], 'children' => [['type' => 'ui/detail', 'props' => ['items' => array_slice($items, 0, 40)]]]], array_keys($sections), $sections);
    }

    /** What a form does once it is sent: in sections, the way a settings page reads. */
    private static function settingsFields(): array
    {
        return [
            ['handle' => 'enabled', 'type' => 'toggle', 'label' => __('Accept Submissions')],
            ['handle' => 'submit', 'type' => 'text', 'label' => __('Button Label')],
            ['handle' => 'success', 'type' => 'textarea', 'label' => __('Success Message'), 'rows' => 2],
            ['handle' => 'redirect', 'type' => 'url', 'component' => 'link', 'label' => __('Or Redirect To'), 'placeholder' => __('A Thank-You Page')],
            ['handle' => 'notify', 'type' => 'tags', 'label' => __('Email Submissions To'), 'placeholder' => 'name@example.com', 'section' => __('Email')],
            // Literal braces: the subject's own placeholders, not the view's.
            ['handle' => 'subject', 'type' => 'text', 'label' => __('Email Subject'), 'instructions' => __('Use {{form}} or any {{field_name}}.'), 'section' => __('Email')],
            ['handle' => 'reply_to_field', 'type' => 'text', 'label' => __('Reply-to Field'), 'instructions' => __('The field that holds the sender’s email.'), 'section' => __('Email')],
            ['handle' => 'confirmation', 'type' => 'toggle', 'label' => __('Send the Person a Confirmation Email'), 'section' => __('Email')],
            ['handle' => 'confirmation_subject', 'type' => 'text', 'label' => __('Confirmation Subject'), 'if' => ['confirmation' => true], 'section' => __('Email')],
            ['handle' => 'confirmation_message', 'type' => 'richtext', 'label' => __('Confirmation Message'), 'if' => ['confirmation' => true], 'section' => __('Email')],
            ['handle' => 'store', 'type' => 'toggle', 'label' => __('Keep Submissions in {brand}'), 'section' => __('Submissions')],
            ['handle' => 'honeypot', 'type' => 'toggle', 'label' => __('Hidden Spam Trap'), 'section' => __('Submissions')],
            ['handle' => 'min_seconds', 'type' => 'number', 'label' => __('Minimum Seconds to Fill In'), 'min' => 0, 'max' => 60, 'section' => __('Submissions')],
            ['handle' => 'webhook', 'type' => 'url', 'label' => __('Webhook'), 'instructions' => __('Each submission is POSTed as JSON to this https:// URL (Zapier, Make, n8n, your CRM).'), 'section' => __('Submissions')],
        ];
    }

    /** The form in the site's other languages, one section each, in the admin's language: empty fields fall back to the form's own wording. */
    public function wordFields(array $form): array
    {
        $locale = app('translator')->getLocale();
        $names = ['label' => __('Label'), 'help' => __('Help Text'), 'placeholder' => __('Placeholder'), 'options' => __('Options'), 'text' => __('Statement')];
        $settings = $form['settings'];
        $fields = [];

        foreach (array_values(array_diff($this->cms->locales(), [$this->cms->defaultLocale()])) as $code) {
            $section = Languages::name($code, $locale);
            $fields[] = ['handle' => "{$code}:s:submit", 'type' => 'text', 'label' => __('Button Label'), 'placeholder' => $settings['submit'], 'section' => $section];
            $fields[] = ['handle' => "{$code}:s:success", 'type' => 'textarea', 'label' => __('Success Message'), 'placeholder' => $settings['success'], 'rows' => 2, 'section' => $section];

            if ($settings['confirmation']) {
                $fields[] = ['handle' => "{$code}:s:confirmation_subject", 'type' => 'text', 'label' => __('Confirmation Subject'), 'placeholder' => $settings['confirmation_subject'], 'section' => $section];
                $fields[] = ['handle' => "{$code}:s:confirmation_message", 'type' => 'richtext', 'label' => __('Confirmation Message'), 'section' => $section];
            }

            foreach (FormFields::extract($form['fields']) as $field) {
                if ($field['type'] === 'hidden') {
                    continue;
                }

                $label = strip_tags((string) ($field['props']['label'] ?? $field['name']));

                foreach (FormRepository::FIELD_WORDS as $key) {
                    if (is_string($field['props'][$key] ?? null) && $field['props'][$key] !== '') {
                        $fields[] = ['handle' => "{$code}:f:{$field['name']}:{$key}", 'type' => $key === 'options' ? 'textarea' : 'text', 'label' => "{$label} · {$names[$key]}", 'placeholder' => $field['props'][$key], 'section' => $section,
                            ...($key === 'options' ? ['instructions' => __('Keep each value: value|Label.')] : [])];
                    }
                }
            }
        }

        return $fields;
    }

    /** A form's translations as one value per field of the Languages tab. */
    public static function words(array $locales): array
    {
        $out = [];

        foreach ($locales as $code => $words) {
            foreach ((array) ($words['settings'] ?? []) as $key => $value) {
                $out["{$code}:s:{$key}"] = $value;
            }

            foreach ((array) ($words['fields'] ?? []) as $name => $props) {
                foreach ((array) $props as $key => $value) {
                    $out["{$code}:f:{$name}:{$key}"] = $value;
                }
            }
        }

        return $out;
    }

    /** The Languages tab's values as a form's translations; languages it doesn't show keep theirs. */
    public static function locales(array $words, array $current): array
    {
        $out = [];

        foreach ($words as $handle => $value) {
            $parts = explode(':', (string) $handle);

            if (! is_string($value) || $value === '') {
                continue;
            }

            if (count($parts) === 3 && $parts[1] === 's') {
                $out[$parts[0]]['settings'][$parts[2]] = $value;
            } elseif (count($parts) === 4 && $parts[1] === 'f') {
                $out[$parts[0]]['fields'][$parts[2]][$parts[3]] = $value;
            }
        }

        $shown = array_unique(array_map(static fn ($h) => explode(':', (string) $h)[0], array_keys($words)));

        return [...array_diff_key($current, array_flip($shown)), ...$out];
    }
}
