<?php

declare(strict_types=1);

use Cms\Content\Entries\Entry;
use Cms\Site\Rendering\AugmentedEntry;
use Cms\Site\Rendering\EntryQueryProxy;
use Cms\Site\Rendering\PageContext;
use Cms\Site\Rendering\UrlGenerator;
use Cms\Support\Tokens;

if (! function_exists('cms_entries')) {
    /** Live entries of one or more collections, as template-friendly objects. */
    function cms_entries(string ...$collections): EntryQueryProxy
    {
        $locale = PageContext::current()?->locale() ?? cms()->defaultLocale();

        return new EntryQueryProxy(cms()->entries()->query()->collection(...$collections)->locale($locale)->live());
    }
}

if (! function_exists('cms_entry')) {
    function cms_entry(string $id): ?AugmentedEntry
    {
        $entry = cms()->entries()->find($id);

        return $entry instanceof Entry && $entry->isLive() ? new AugmentedEntry($entry) : null;
    }
}

if (! function_exists('cms_url')) {
    function cms_url(string $uri, bool $absolute = false): string
    {
        $urls = app(UrlGenerator::class);

        return $absolute ? $urls->absolute($uri) : $urls->to($uri);
    }
}

if (! function_exists('cms_token')) {
    /** CSS for a token reference: cms_token('color.primary') → var(--color-primary). */
    function cms_token(string $path): string
    {
        return (string) Tokens::css('token:'.$path);
    }
}

if (! function_exists('cms_global')) {
    /** A global value by dot path: cms_global('footer.tagline'). */
    function cms_global(string $path, mixed $default = null): mixed
    {
        PageContext::depend('globals');
        [$handle, $key] = array_pad(explode('.', $path, 2), 2, null);
        $set = cms()->globals()->find((string) $handle);

        if (! $set) {
            return $default;
        }

        $values = $set->blueprint()->augment($set->values(PageContext::current()?->locale()));

        return $key === null ? $values : data_get($values, $key, $default);
    }
}
