<?php

declare(strict_types=1);

namespace Cms\SiteSearch;

use Cms\Content\Entries\Entry;
use Cms\Modules\Blocks\BlockRegistry;
use Cms\Packages\Plugin;

/**
 * A search field for any page and a page of results. Both read the index the core already builds (/search.json), to
 * which the plugin adds what a results page shows and finds by: each page's kind, its date, and its keywords.
 */
final class SiteSearchPlugin extends Plugin
{
    public function boot(): void
    {
        $this->cms()->whenAvailable(BlockRegistry::class, function (BlockRegistry $blocks): void {
            $blocks->feature(SearchForm::FEATURE, script: $this->file('site-search.js'), style: $this->file('site-search.css'));
            $blocks->register(SearchForm::definition());
            $blocks->register(SearchResults::definition());
        });

        $hooks = $this->cms()->hooks();

        // Core `site.search.record`: what a result says of its page, and the words an editor gave it to be found by.
        $hooks->addFilter('site.search.record', static fn (?array $record, ?Entry $entry = null): ?array => $record !== null && $entry !== null ? Records::add($record, $entry) : $record);

        // The look of a button is a class of the site's (btn-<style>) that only this PHP writes: name every one people can pick.
        $hooks->addFilter('styling.candidates', static fn (array $classes): array => [...$classes, ...SearchForm::buttonClasses()]);
    }

    /** A built file's address with its version, so browsers fetch it again after an update; null before the first build. */
    private function file(string $name): ?string
    {
        $path = $this->path('dist/'.$name);

        return is_file($path) ? $this->asset($name).'?v='.substr((string) hash_file('xxh3', $path), 0, 10) : null;
    }
}
