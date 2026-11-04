<?php

declare(strict_types=1);

namespace Cms\I18n;

use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Content\Entries\Entry;
use Cms\Packages\Plugin;
use Cms\Users\User;

final class I18nPlugin extends Plugin
{
    /** Cache key of the translation coverage (see translations/coverage). */
    private const string COVERAGE = 'i18n.coverage';

    public function register(): void
    {
        foreach ([Languages::class, Translations::class, LocalLinks::class] as $service) {
            $this->app->singleton($service);
        }

        require_once __DIR__.'/helpers.php';
    }

    public function install(): void
    {
        $this->refreshUris();
    }

    public function boot(): void
    {
        $hooks = $this->cms()->hooks();
        $languages = fn () => $this->app->make(Languages::class);
        $translations = fn () => $this->app->make(Translations::class);

        // The language switcher as a list for the Repeat block.
        $hooks->addFilter('blocks.data-sources', static fn (array $sources) => [...$sources, 'languages' => [
            'label' => 'Languages',
            'description' => __('This page in every language (or that language’s home page), for a language switcher.'),
            'fields' => [],
            'item' => ['locale' => __('Code (en, de…)'), 'name' => __('Language name'), 'url' => __('Link'), 'current' => __('Is the current language')],
            'resolve' => static fn () => cms_translations(),
        ]]);

        // /de/ueber-uns: every language but the default lives under its own prefix.
        $hooks->addFilter('entry.uri', function (string $uri, Entry $entry) use ($languages) {
            $prefix = $languages()->prefix($entry->locale);

            return $prefix === '' ? $uri : '/'.$prefix.($uri === '/' ? '' : $uri);
        });

        $languages()->track();
        $hooks->on('settings.saved', function (string $namespace) use ($languages) {
            if (in_array($namespace, ['site', 'i18n'], true)) {
                $languages()->recordChanges();
            }
        }, priority: 20);

        // Changing languages or the default reshapes URLs; recompute them (redirects follow via entry.uri-changed).
        $hooks->on('settings.saved', fn (string $namespace) => in_array($namespace, ['site', 'i18n'], true) && $this->refreshUris());

        // Languages — the default among them — are managed under Languages, not as a field of the site.
        $hooks->addFilter('settings.fields', static fn (array $fields, string $namespace) => $namespace === 'site' ? array_filter($fields, static fn ($f) => $f['handle'] !== 'locale') : $fields);

        $hooks->addFilter('seo.alternates', function (array $alternates, Entry $entry) use ($languages, $translations) {
            if (! $languages()->multilingual()) {
                return $alternates;
            }

            // Only versions search engines may list: noindexed ones and ones canonical to another page stay out.
            $live = array_filter($translations()->versions($entry), static fn (Entry $e) => $e->isLive() && $e->uri !== null
                && empty($e->data['seo']['noindex']) && in_array($e->data['seo']['canonical'] ?? '', ['', null, $e->uri], true));

            if (count($live) < 2 || ! isset($live[$entry->locale])) {
                return $alternates;
            }

            foreach ($live as $locale => $version) {
                $alternates[] = ['locale' => $locale, 'uri' => $version->uri];
            }

            if ($default = $live[$languages()->default()] ?? null) {
                $alternates[] = ['locale' => 'x-default', 'uri' => $default->uri];
            }

            return $alternates;
        });

        // Menus link one version of a page; show the visitor's language when it is live.
        $hooks->addFilter('navigation.entry', function (?Entry $entry, ?string $locale) use ($translations) {
            if (! $entry || ! $locale || $entry->locale === $locale) {
                return $entry;
            }

            $version = $translations()->in($entry, $locale);

            return $version && $version->isLive() ? $version : $entry;
        });

        // A link to a page follows the visitor's language; language switcher links say hreflang and stay as they are.
        $hooks->addFilter('site.html', fn (string $html, $context) => $context->editor ? $html : $this->app->make(LocalLinks::class)->localize($html, $context->locale()));

        // A missing page under /de/ is answered in German.
        $hooks->addFilter('site.locale', static function (?string $locale, string $uri) use ($languages) {
            $first = explode('/', trim($uri, '/'))[0];

            foreach ($languages()->codes() as $code) {
                if ($first !== '' && $languages()->prefix($code) === $first) {
                    return $code;
                }
            }

            return $locale;
        });

        // Coverage changes with every entry and with the languages; count again on the next look.
        foreach (['entry.saved', 'entry.deleted', 'settings.saved'] as $event) {
            $hooks->on($event, static fn () => cache()->forget(self::COVERAGE));
        }

        $this->abilities();

        $this->cms()->context()->register('cms://languages', 'Languages', 'The site’s languages (default first) with URL prefixes. Translate entries with translations/create.', fn () => $languages()->all());

        $this->cms()->admin()->nav('languages', ['path' => '/languages', 'icon' => 'translate', 'section' => 'plugins', 'permission' => 'settings.manage']);
        $this->cms()->admin()->component('i18n/add-language', ['label' => 'Add Language', 'description' => 'A searchable list of languages to add to the site, chosen with the keyboard; any other xx-YY code works too.', 'surface' => false, 'permission' => 'settings.manage',
            'props' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false]]);
        $this->cms()->views()->register('languages', '/languages', fn (User $user) => $this->view($user), permission: 'settings.manage');
        $this->cms()->admin()->widget('i18n-coverage', ['label' => 'Translations', 'description' => __('How much of the content exists in each language.'), 'icon' => 'translate', 'column' => 'side', 'order' => 55]);
        $this->adminModule(import: '@letterncms/i18n');
    }

    private function abilities(): void
    {
        $a = $this->cms()->abilities();
        $languages = fn () => $this->app->make(Languages::class);
        $translations = fn () => $this->app->make(Translations::class);
        $can = function (?User $user, string $id, string $action): bool {
            $entry = $this->cms()->entries()->find($id);

            return $entry !== null && ($user?->hasPermission("entries.{$entry->collection}.{$action}") ?? false);
        };
        $code = ['type' => 'string', 'pattern' => '^[a-z]{2}(-[A-Z]{2})?$', 'description' => 'Language code, e.g. de or pt-BR'];

        $a->register('languages/list', [
            'label' => 'List Languages',
            'description' => 'The site’s languages with names, URL prefixes, direction and how many entries each has.',
            'permission' => 'admin.access',
            'annotations' => ['readOnly' => true],
            'package' => $this->manifest->name,
            'handler' => function () use ($languages) {
                $prefixed = (bool) $this->cms()->settings('i18n', 'prefix_default', false);

                return [
                    'prefixDefault' => $prefixed,
                    'canTranslate' => $this->app->make(Translations::class)->translator() !== null,
                    'languages' => array_map(fn (array $l) => $this->row([...$l, 'entries' => $this->count($l['code'])], $prefixed), $languages()->all()),
                ];
            },
        ]);

        $a->register('languages/save', [
            'label' => 'Save Languages',
            'description' => 'Set the site’s languages and the default one. Languages that still have entries cannot be removed.',
            'permission' => 'settings.manage',
            'expose' => ['mcp' => false],
            'package' => $this->manifest->name,
            'input' => [
                'type' => 'object',
                'properties' => [
                    'default' => $code,
                    'languages' => ['type' => 'array', 'items' => $code, 'minItems' => 1, 'maxItems' => 50],
                    'prefixDefault' => ['type' => 'boolean', 'description' => 'Also put the default language under a prefix (/en/…).'],
                ],
                'required' => ['default', 'languages'],
            ],
            'handler' => fn (array $in) => $this->saveLanguages($in['default'], $in['languages'], $in['prefixDefault'] ?? null),
        ]);

        // One change at a time, as the Languages screen makes them.
        $one = ['type' => 'object', 'properties' => ['code' => $code], 'required' => ['code']];
        $a->register('languages/add', [
            'label' => 'Add Language',
            'description' => 'Add a language to the site.',
            'permission' => 'settings.manage',
            'expose' => ['mcp' => false],
            'package' => $this->manifest->name,
            'input' => $one,
            'handler' => fn (array $in) => $this->saveLanguages($languages()->default(), [...$languages()->codes(), $in['code']]),
        ]);

        $a->register('languages/remove', [
            'label' => 'Remove Language',
            'description' => 'Remove a language that has no entries. The default language cannot be removed.',
            'permission' => 'settings.manage',
            'annotations' => ['destructive' => true],
            'expose' => ['mcp' => false],
            'package' => $this->manifest->name,
            'input' => $one,
            'handler' => function (array $in) use ($languages) {
                if ($in['code'] === $languages()->default()) {
                    throw AbilityException::invalid(__('Make another language the default first.'));
                }

                return $this->saveLanguages($languages()->default(), array_values(array_diff($languages()->codes(), [$in['code']])));
            },
        ]);

        $a->register('languages/default', [
            'label' => 'Make Language the Default',
            'description' => 'Make one of the site’s languages the default: its pages move to the root URL.',
            'permission' => 'settings.manage',
            'expose' => ['mcp' => false],
            'package' => $this->manifest->name,
            'input' => $one,
            'handler' => fn (array $in) => in_array($in['code'], $languages()->codes(), true)
                ? $this->saveLanguages($in['code'], $languages()->codes())
                : throw AbilityException::invalid(__('Add the language first.')),
        ]);

        $a->register('languages/prefix', [
            'label' => 'Prefix the Default Language',
            'description' => 'Whether the default language’s pages are under a prefix (/en/…) too.',
            'permission' => 'settings.manage',
            'expose' => ['mcp' => false],
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['prefixDefault' => ['type' => 'boolean']], 'required' => ['prefixDefault']],
            'handler' => fn (array $in) => $this->saveLanguages($languages()->default(), $languages()->codes(), $in['prefixDefault']),
        ]);

        $a->register('translations/list', [
            'label' => 'List Translations',
            'description' => 'Every language version of an entry, with status and URL, plus the languages it is missing.',
            'permission' => fn (?User $u, array $in) => $can($u, (string) $in['id'], 'view'),
            'annotations' => ['readOnly' => true],
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            'handler' => function (array $in) use ($languages, $translations) {
                $entry = $this->cms()->entries()->findOrFail($in['id']);
                $versions = $translations()->versions($entry);

                return [
                    'origin' => $entry->originId ?? $entry->id,
                    'versions' => array_values(array_map(static fn (Entry $e) => [
                        'id' => $e->id,
                        'locale' => $e->locale,
                        'name' => Languages::name($e->locale),
                        'title' => $e->title,
                        'uri' => $e->uri,
                        'status' => $e->displayStatus(),
                        'original' => $e->originId === null,
                    ], $versions)),
                    'missing' => array_values(array_map(static fn ($code) => ['locale' => $code, 'name' => Languages::name($code)], array_diff($languages()->codes(), array_keys($versions)))),
                ];
            },
        ]);

        $a->register('translations/coverage', [
            'label' => 'Translation Coverage',
            'description' => 'For each language, how many entries exist in it (as the original or a translation) out of all entries in content collections, as counts and a share. Counted at most every 10 minutes.',
            'permission' => 'admin.access',
            'annotations' => ['readOnly' => true],
            'package' => $this->manifest->name,
            'handler' => fn () => cache()->remember(self::COVERAGE, 600, fn () => $this->coverage()),
        ]);

        $a->register('translations/create', [
            'label' => 'Translate Entry',
            'description' => 'Start the version of an entry in another language, as a draft. translate: true translates the text with AI (when the assistant is set up); otherwise the content is copied for a person to translate.',
            'permission' => fn (?User $u, array $in) => $can($u, (string) $in['id'], 'create'),
            'package' => $this->manifest->name,
            'input' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'string'], 'locale' => $code, 'translate' => ['type' => 'boolean', 'default' => false]],
                'required' => ['id', 'locale'],
            ],
            'handler' => fn (array $in, AbilityContext $ctx) => $translations()->create($this->cms()->entries()->findOrFail($in['id']), $in['locale'], $ctx->user, (bool) $in['translate'])->toArray(),
        ]);
    }

    /** The site's languages as given: the default first; one that still has entries stays. */
    private function saveLanguages(string $default, array $languages, ?bool $prefixDefault = null): array
    {
        $codes = array_values(array_unique([$default, ...$languages]));
        $all = fn () => $this->app->make(Languages::class);

        foreach (array_diff($all()->codes(), $codes) as $removed) {
            if (($n = $this->count($removed)) > 0) {
                throw AbilityException::invalid($n === 1
                    ? __(':language still has 1 entry. Delete or move it before removing the language.', ['language' => $removed])
                    : __(':language still has :count entries. Delete or move them before removing the language.', ['language' => $removed, 'count' => $n]));
            }
        }

        if ($prefixDefault !== null) {
            $this->cms()->settingsRegistry()->save('i18n', ['prefix_default' => $prefixDefault]);
        }

        $this->cms()->saveSite(['locale' => $default, 'locales' => $codes]);

        return $all()->all();
    }

    /** A language as the Languages screen lists it: what it can be done with, in words for the person looking. */
    private function row(array $l, bool $prefixed): array
    {
        return [...$l,
            'path' => $l['prefix'] !== '' ? '/'.$l['prefix'].'/…' : null,
            'prefixLabel' => $l['prefix'] !== '' ? '/'.$l['prefix'].'/…' : __('None'),
            'badge' => $l['default'] ? __('Default') : null,
            'directionName' => $l['direction'] === 'rtl' ? __('Right to Left') : __('Left to Right'),
            'removable' => ! $l['default'] && $l['entries'] === 0,
            'note' => ! $l['default'] && $l['entries'] > 0 ? ($l['entries'] === 1 ? __('It can be removed once its 1 entry is gone.') : __('It can be removed once its :count entries are gone.', ['count' => $l['entries']])) : null,
            'prefixable' => $l['default'] && ! $prefixed,
            'prefixed' => $l['default'] && $prefixed,
        ];
    }

    /** The Languages screen (see Cms\Admin\Views): the site's languages, what can be done with each, and adding one. */
    private function view(User $user): array
    {
        $fallback = Languages::name($this->app->make(Languages::class)->default());
        $assistant = isset($this->cms()->settingsRegistry()->schemas()['assistant']);
        $translator = $this->app->make(Translations::class)->translator() !== null;
        // A language's own actions: in its row's menu, and under it once opened. Only the default has a prefix to change.
        $makeDefault = ['label' => __('Make Default'), 'icon' => 'star', 'ability' => 'languages/default', 'input' => ['code' => '{row.code}'], 'then' => 'manifest', 'unless' => '{row.default}', 'success' => __('{row.name} is now the default'),
            'confirm' => ['title' => __('Make {row.name} the default?'), 'text' => __('Its pages move to the root URL and :current gets a prefix. Old URLs redirect automatically.', ['current' => $fallback]), 'confirm' => __('Make Default')]];
        $prefix = [
            ['label' => __('Add URL Prefix'), 'icon' => 'link', 'ability' => 'languages/prefix', 'input' => ['prefixDefault' => true], 'then' => 'manifest', 'when' => '{row.prefixable}', 'success' => __('{row.name} now has a URL prefix'),
                'confirm' => ['title' => __('Give {row.name} a URL prefix?'), 'text' => __('Its pages move to /{row.code}/….'), 'confirm' => __('Add Prefix')]],
            ['label' => __('Remove URL Prefix'), 'icon' => 'link', 'ability' => 'languages/prefix', 'input' => ['prefixDefault' => false], 'then' => 'manifest', 'when' => '{row.prefixed}', 'success' => __('{row.name} is now at the root URL'),
                'confirm' => ['title' => __('Remove the URL prefix of {row.name}?'), 'text' => __('Its pages move to the root URL.'), 'confirm' => __('Remove Prefix')]],
        ];
        // Shown while it still has entries, but not possible: the dialog says why.
        $remove = ['label' => __('Remove'), 'icon' => 'trash', 'variant' => 'danger', 'ability' => 'languages/remove', 'input' => ['code' => '{row.code}'], 'then' => 'manifest', 'unless' => '{row.default}', 'disabled' => '{row.entries}', 'success' => __('{row.name} removed'),
            'confirm' => ['title' => __('Remove {row.name}?'), 'text' => __('Visitors will no longer see this language. You can add it again later.'), 'confirm' => __('Remove'), 'danger' => true]];

        return ['type' => 'ui/page', 'props' => ['title' => __('Languages'), 'icon' => 'translate', 'width' => 'narrow', 'actions' => [
            // A searchable list chosen with the keyboard, which takes any other xx-YY code too.
            ['label' => __('Add Language'), 'name' => 'add-language', 'icon' => 'plus', 'variant' => 'primary', 'can' => 'settings.manage', 'component' => 'i18n/add-language'],
        ]], 'children' => [['type' => 'ui/stack', 'children' => [
            ['type' => 'ui/records', 'props' => [
                'id' => 'languages', 'label' => __('Languages'), 'key' => 'code',
                'source' => ['ability' => 'languages/list'], 'at' => 'languages',
                'columns' => [
                    ['key' => 'name', 'label' => __('Name'), 'muted' => 'badge'],
                    ['key' => 'code', 'label' => __('Code'), 'width' => '72px', 'format' => 'code'],
                    ['key' => 'path', 'label' => __('URL Prefix'), 'width' => '120px', 'format' => 'code', 'empty' => __('None')],
                    ['key' => 'entries', 'label' => __('Entries'), 'width' => '80px', 'align' => 'end', 'format' => 'number'],
                ],
                'open' => ['label' => __('Open'), 'dialog' => ['title' => '{row.name}', 'text' => '{row.note}', 'body' => [
                    ['type' => 'ui/detail', 'props' => ['items' => [
                        ['label' => __('Code'), 'key' => 'row.code', 'format' => 'code'],
                        ['label' => __('URL Prefix'), 'key' => 'row.prefixLabel'],
                        ['label' => __('Direction'), 'key' => 'row.directionName'],
                        ['label' => __('Entries'), 'key' => 'row.entries', 'format' => 'number'],
                    ]]],
                ], 'actions' => [$remove, ...$prefix, $makeDefault]]],
                'actions' => [$makeDefault, ...$prefix, $remove],
                'empty' => ['icon' => 'translate', 'title' => __('Languages are not available')],
            ]],
            ...($translator
                ? [['type' => 'ui/text', 'props' => ['text' => __('Translate a page from its language menu.'), 'muted' => true]]]
                : ($assistant ? [['type' => 'ui/actions', 'can' => 'settings.manage', 'props' => ['items' => [['label' => __('Set Up Automatic Translation'), 'icon' => 'sparkle', 'variant' => 'ghost', 'to' => '/settings/assistant']]]]] : [])),
        ]]]];
    }

    private function count(string $locale): int
    {
        return $this->cms()->entries()->query()->locale($locale)->count();
    }

    /**
     * Each language's share of the content: originals (entries without an origin) in
     * content collections, against those that exist in the language — written in it
     * or translated into it. Templates and components (system collections) do not count.
     *
     * @return array{total: int, languages: list<array{code: string, name: string, englishName: string, default: bool, entries: int, share: float}>}
     */
    private function coverage(): array
    {
        $handles = array_keys(array_filter($this->cms()->collections()->all(), static fn ($c) => ! $c->system));
        $query = fn () => $this->cms()->entries()->query()->collection(...$handles);
        $total = $handles === [] ? 0 : $query()->where('origin_id', 'null', null)->count();

        return [
            'total' => $total,
            'languages' => array_map(function (array $language) use ($query, $total) {
                $entries = $total === 0 ? 0 : $query()->locale($language['code'])->where('origin_id', 'null', null)->count()
                    + $query()->locale($language['code'])->where('origin_id', 'not null', null)->count();

                return [
                    ...array_intersect_key($language, array_flip(['code', 'name', 'englishName', 'default'])),
                    'entries' => min($entries, $total),
                    'share' => $total === 0 ? 0.0 : round(min(1, $entries / $total), 4),
                ];
            }, $this->app->make(Languages::class)->all()),
        ];
    }

    /** Recompute URLs only when something that shapes them changed. */
    private function refreshUris(): void
    {
        $file = storage_path('i18n.json');
        $signature = $this->app->make(Languages::class)->signature();

        if (is_file($file) && (json_decode((string) file_get_contents($file), true)['signature'] ?? null) === $signature) {
            return;
        }

        $this->cms()->content()->refreshUris();
        file_put_contents($file, json_encode(['signature' => $signature]));
    }
}
