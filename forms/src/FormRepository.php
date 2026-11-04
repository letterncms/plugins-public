<?php

declare(strict_types=1);

namespace Cms\Forms;

use InvalidArgumentException;
use Cms\Modules\Blocks\Document;
use Cms\Support\Files;
use Cms\Support\RecordTimestamps;

/** Form definitions in content/forms/<handle>.json (git-friendly). */
final class FormRepository
{
    public const array DEFAULT_SETTINGS = [
        'submit' => 'Send',
        'success' => 'Thanks — we received your message.',
        'redirect' => null,
        'notify' => [],
        'subject' => 'New submission: {form}',
        'reply_to_field' => 'email',
        'confirmation' => false,
        'confirmation_subject' => 'We received your message',
        'confirmation_message' => '<p>Thanks for getting in touch. We will reply soon.</p>',
        'store' => true,
        'honeypot' => true,
        'min_seconds' => 3,
        'webhook' => null,
        'enabled' => true,
    ];

    /** Settings a visitor reads, the ones a language can reword. */
    public const array WORDS = ['submit', 'success', 'confirmation_subject', 'confirmation_message'];

    /** Field properties a visitor reads. */
    public const array FIELD_WORDS = ['label', 'help', 'placeholder', 'options', 'text'];

    private ?array $cache = null;

    public function __construct(private readonly Document $document) {}

    /** @return array<string, array{handle: string, title: string, fields: list<array>, settings: array}> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $out = [];

        foreach (glob(content_path('forms/*.json')) ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);

            if (is_array($data)) {
                $handle = basename($file, '.json');
                $out[$handle] = $this->shape($handle, $data);
            }
        }

        ksort($out);

        return $this->cache = $out;
    }

    /** A form; with $locale, its wording in that language where it has one. */
    public function find(string $handle, ?string $locale = null): ?array
    {
        $form = $this->all()[$handle] ?? null;

        return $form && $locale !== null ? self::translated($form, $locale) : $form;
    }

    /**
     * Lay a language's wording over a form. Only words change: names, rules and
     * order stay those of the form, so one form collects one kind of answer.
     */
    public static function translated(array $form, string $locale): array
    {
        $words = (array) ($form['locales'][$locale] ?? []);

        if ($words === []) {
            return $form;
        }

        foreach (self::WORDS as $key) {
            if (is_string($words['settings'][$key] ?? null) && $words['settings'][$key] !== '') {
                $form['settings'][$key] = $words['settings'][$key];
            }
        }

        $form['fields'] = self::relabel($form['fields'], (array) ($words['fields'] ?? []));

        return $form;
    }

    private static function relabel(array $blocks, array $words): array
    {
        foreach ($blocks as $i => $block) {
            if (! is_array($block)) {
                continue;
            }

            $name = $block['props']['name'] ?? null;

            if (is_string($name) && is_array($words[$name] ?? null)) {
                $block['props'] = array_replace($block['props'], array_filter(array_intersect_key($words[$name], array_flip(self::FIELD_WORDS)), static fn ($v) => is_string($v) && $v !== ''));
            }

            if (is_array($block['children'] ?? null)) {
                $block['children'] = self::relabel($block['children'], $words);
            }

            $blocks[$i] = $block;
        }

        return $blocks;
    }

    public function save(string $handle, array $form): array
    {
        if (! Files::isHandle($handle)) {
            throw new InvalidArgumentException(__('Invalid form handle.'));
        }

        $fields = $this->document->normalize((array) ($form['fields'] ?? []));
        $settings = array_intersect_key(array_replace(self::DEFAULT_SETTINGS, (array) ($form['settings'] ?? [])), self::DEFAULT_SETTINGS);
        $settings['notify'] = array_values(array_filter((array) $settings['notify'], static fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        $settings['min_seconds'] = max(0, min(60, (int) $settings['min_seconds']));

        if ($settings['webhook'] !== null && $settings['webhook'] !== '' && ! preg_match('#^https://#', (string) $settings['webhook'])) {
            throw new InvalidArgumentException(__('Webhooks must use https://.'));
        }

        $names = array_column(FormFields::extract($fields), 'name');

        if (count($names) !== count(array_unique($names))) {
            throw new InvalidArgumentException(__('Two fields share the same name: :names.', ['names' => implode(', ', array_unique(array_diff_assoc($names, array_unique($names))))]));
        }

        Files::put(content_path("forms/{$handle}.json"), json_encode([
            ...RecordTimestamps::saved($this->find($handle)),
            'title' => (string) ($form['title'] ?? $handle),
            'settings' => $settings,
            'fields' => $fields,
            ...(self::cleanLocales((array) ($form['locales'] ?? [])) ?: []),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->cache = null;
        cms()->hooks()->dispatch('form.saved', $handle);

        return $this->find($handle);
    }

    /**
     * Change a form as it is on disk now: the tabs of a form save at once (fields, settings,
     * languages), each its own part, so one never writes back what another just changed.
     *
     * @param  callable(?array): array  $change  the form as stored (null when new) to what to save
     */
    public function update(string $handle, callable $change): array
    {
        if (! Files::isHandle($handle)) {
            throw new InvalidArgumentException(__('Invalid form handle.'));
        }

        $lock = fopen(storage_path("framework/form-{$handle}.lock"), 'c');
        flock($lock, LOCK_EX);

        try {
            $this->cache = null;

            return $this->save($handle, $change($this->find($handle)));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function delete(string $handle): void
    {
        if (Files::isHandle($handle)) {
            @unlink(content_path("forms/{$handle}.json"));
        }

        $this->cache = null;
        cms()->hooks()->dispatch('form.saved', $handle);
    }

    /** @return array{locales?: array} what is worth keeping of a form's translations */
    private static function cleanLocales(array $locales): array
    {
        $out = [];

        foreach ($locales as $code => $words) {
            if (! is_string($code) || ! preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $code) || ! is_array($words)) {
                continue;
            }

            $settings = array_filter(array_intersect_key((array) ($words['settings'] ?? []), array_flip(self::WORDS)), static fn ($v) => is_string($v) && $v !== '');
            $fields = array_filter(array_map(
                static fn ($field) => array_filter(array_intersect_key((array) $field, array_flip(self::FIELD_WORDS)), static fn ($v) => is_string($v) && $v !== ''),
                (array) ($words['fields'] ?? []),
            ));

            if ($settings !== [] || $fields !== []) {
                $out[$code] = array_filter(['settings' => $settings, 'fields' => $fields]);
            }
        }

        return $out === [] ? [] : ['locales' => $out];
    }

    private function shape(string $handle, array $data): array
    {
        return [
            'handle' => $handle,
            ...RecordTimestamps::api($data),
            'title' => (string) ($data['title'] ?? $handle),
            'fields' => array_values((array) ($data['fields'] ?? [])),
            'settings' => array_replace(self::DEFAULT_SETTINGS, (array) ($data['settings'] ?? [])),
            'locales' => (array) ($data['locales'] ?? []),
        ];
    }
}
