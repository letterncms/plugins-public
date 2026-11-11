<?php

declare(strict_types=1);

namespace Cms\Consent;

use Cms\Admin\Translations;
use Cms\Modules\Languages\Languages;
use Cms\Packages\Plugin;

/**
 * Asks visitors which cookies they accept, on the published site. The page is the same for
 * everyone (it may be a built file), so the answer is kept and read in the browser: see Banner.
 */
final class ConsentPlugin extends Plugin
{
    public const string SETTINGS = 'consent';

    /** What a visitor is asked about, besides what the site needs to work. */
    public const array CATEGORIES = ['analytics', 'marketing'];

    private bool $describing = false;

    public function boot(): void
    {
        $this->settingsSchema();

        // The CMS asks every plugin for a home-screen widget. This one only speaks when something is wrong: nothing to restate otherwise.
        $this->cms()->admin()->widget('consent-banner', ['label' => 'Consent', 'description' => __('Warns when visitors are never asked, while Analytics waits for their answer.'), 'icon' => 'shield', 'column' => 'side', 'order' => 86, 'permission' => 'settings.manage', 'view' => fn () => $this->widget()]);

        $hooks = $this->cms()->hooks();

        // Never in the builder or a preview: nobody is tracked there, and the banner would sit on the canvas.
        $hooks->addFilter('site.foot', function (array $foot, $context = null) {
            // With nothing to ask about, there is nothing to show.
            if (($context->editor ?? false) || ($context->preview ?? false) || ! $this->asks()) {
                return $foot;
            }

            $locale = is_object($context) && method_exists($context, 'locale') ? $context->locale() : $this->cms()->defaultLocale();

            // First in the foot: `window.cmsConsent` is there before any script that asks for it.
            return [Banner::html($this->offered(), $this->copy($locale), (bool) $this->setting('reopen', true)), ...$foot];
        });

        // Every setting ends up in the built pages.
        $hooks->addFilter('site.build.signature', fn (array $parts) => [...$parts, 'consent' => md5((string) json_encode($this->cms()->setting(self::SETTINGS)))]);
    }

    /** @return list<string> The categories this site asks about. */
    private function offered(): array
    {
        return array_values(array_filter(self::CATEGORIES, fn (string $category) => (bool) $this->setting($category, $category === 'analytics')));
    }

    /** Whether visitors are asked anything: the banner is on and there is a category to choose. */
    private function asks(): bool
    {
        return (bool) $this->setting('enabled', true) && $this->offered() !== [];
    }

    /** Nothing, unless Analytics waits for an answer nobody is asked for: then its visitors are never counted. */
    private function widget(): array
    {
        $waits = $this->cms()->isActive('letterncms/analytics')
            && $this->cms()->setting('analytics', 'enabled', true)
            && $this->cms()->setting('analytics', 'mode') === 'visitors'
            && $this->cms()->setting('analytics', 'consent_required', true);

        return ['type' => 'ui/stack', 'children' => $waits && ! in_array('analytics', $this->asks() ? $this->offered() : [], true) ? [
            ['type' => 'ui/notice', 'props' => ['tone' => 'warning', 'title' => __('Analytics waits for consent, but visitors aren’t asked.'), 'action' => ['label' => __('Open Settings'), 'to' => '/settings/'.self::SETTINGS]]],
        ] : []];
    }

    /** @return array{title: string, text: string, policy: string} What the banner says in a language: the settings' words, or the plugin's own. */
    private function copy(string $locale): array
    {
        $key = self::key($locale);
        $own = fn (string $field) => trim((string) $this->setting("{$field}_{$key}", ''));
        $default = self::defaults($this->offered());

        return [
            'title' => $own('title') ?: $default['title'],
            'text' => $own('text') ?: $default['text'],
            'policy' => $own('policy'),
        ];
    }

    /**
     * The plugin's own words, for what is asked about, in the language given or the current one.
     *
     * @param  list<string>  $categories
     * @return array{title: string, text: string}
     */
    public static function defaults(array $categories, ?string $locale = null): array
    {
        return [
            'title' => __('Cookies', [], $locale),
            'text' => match (true) {
                $categories === ['analytics'] => __('We’d like to use cookies to see how this site is used. You decide.', [], $locale),
                $categories === ['marketing'] => __('We’d like to use cookies to show you relevant ads. You decide.', [], $locale),
                default => __('We’d like to use cookies to see how this site is used and to show you relevant ads. You choose which.', [], $locale),
            },
        ];
    }

    /** A language code as part of a setting's handle: pt-BR is pt_br. */
    public static function key(string $locale): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $locale));
    }

    private function settingsSchema(): void
    {
        $fields = [
            ['handle' => 'enabled', 'type' => 'toggle', 'label' => 'Show the Banner', 'default' => true],
            ['handle' => 'analytics', 'type' => 'toggle', 'label' => 'Ask About Analytics', 'default' => true, 'if' => ['enabled' => true]],
            ['handle' => 'marketing', 'type' => 'toggle', 'label' => 'Ask About Marketing', 'default' => false, 'if' => ['enabled' => true]],
            ['handle' => 'reopen', 'type' => 'toggle', 'label' => 'Show Cookie Settings Button', 'default' => true, 'if' => ['enabled' => true]],
        ];

        // The banner's words, once per language of the site. Left empty, it says the plugin's own.
        foreach ($this->cms()->locales() as $locale) {
            $key = self::key($locale);
            $section = Languages::name($locale);
            $fields[] = ['handle' => "title_{$key}", 'type' => 'text', 'label' => 'Title', 'max' => 80, 'section' => $section, 'if' => ['enabled' => true]];
            $fields[] = ['handle' => "text_{$key}", 'type' => 'textarea', 'label' => 'Text', 'max' => 600, 'rows' => 3, 'section' => $section, 'if' => ['enabled' => true]];
            $fields[] = ['handle' => "policy_{$key}", 'type' => 'url', 'component' => 'link', 'files' => false, 'label' => 'Privacy Policy', 'section' => $section, 'if' => ['enabled' => true]];
        }

        // What the banner says while a field is empty is what the field shows, in the banner's language. Added when the
        // fields are read, not here: translating into another language before every plugin has booted would leave theirs out.
        $this->cms()->hooks()->addFilter('settings.fields', function (array $fields, string $namespace) {
            // Reading which categories are asked about reads the fields again: that time they go as they are.
            if ($namespace !== self::SETTINGS || ! $this->app->isBooted() || $this->describing) {
                return $fields;
            }

            $this->describing = true;
            $this->app->make(Translations::class)->register();
            $words = [];

            foreach ($this->cms()->locales() as $locale) {
                foreach (self::defaults($this->offered(), $locale) as $field => $text) {
                    // The admin would put a placeholder into its own language; a zero-width space keeps these in the banner's.
                    $words[$field.'_'.self::key($locale)] = $text."\u{200B}";
                }
            }

            $this->describing = false;

            return array_map(static fn (array $field) => isset($words[$field['handle']]) ? [...$field, 'placeholder' => $words[$field['handle']]] : $field, $fields);
        });

        $this->cms()->settingsRegistry()->register(self::SETTINGS, fields: $fields, icon: 'shield', description: __('The cookie banner on the published site.'), order: 56);
    }
}
