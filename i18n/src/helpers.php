<?php

declare(strict_types=1);

use Cms\Content\Entries\Entry;
use Cms\I18n\Languages;
use Cms\I18n\Translations;
use Cms\Site\Rendering\PageContext;

if (! function_exists('cms_translations')) {
    /**
     * Language switcher links for the page being rendered:
     * [{locale, name, url, current}] — the live version in each language, or
     * that language's home page; languages with neither are left out. Empty
     * on single-language sites.
     */
    function cms_translations(?Entry $entry = null): array
    {
        $languages = app(Languages::class);

        if (! $languages->multilingual()) {
            return [];
        }

        $entry ??= class_exists(PageContext::class) ? PageContext::current()?->entry : null;
        $current = $entry?->locale ?? (class_exists(PageContext::class) ? PageContext::current()?->locale() : null) ?? $languages->default();
        $versions = $entry ? app(Translations::class)->versions($entry) : [];
        $links = [];

        foreach ($languages->codes() as $code) {
            $version = $versions[$code] ?? null;

            if (! $version || ! $version->isLive() || $version->uri === null) {
                $prefix = $languages->prefix($code);
                $version = cms()->entries()->findByUri($prefix === '' ? '/' : '/'.$prefix, $code);

                if (! $version || ! $version->isLive()) {
                    continue;
                }
            }

            $uri = (string) $version->uri;

            if (class_exists(PageContext::class) && $version) {
                PageContext::depend('entry:'.$version->id);
            }

            $links[] = [
                'locale' => $code,
                'name' => Languages::name($code),
                'url' => function_exists('cms_url') ? cms_url($uri) : $uri,
                'current' => $code === $current,
            ];
        }

        return $links;
    }
}

if (! function_exists('cms_text_direction')) {
    /** "rtl" for right-to-left languages, otherwise "ltr" — for <html dir>. */
    function cms_text_direction(?string $locale = null): string
    {
        return Languages::direction($locale ?? app(Languages::class)->default());
    }
}
