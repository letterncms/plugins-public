<?php

declare(strict_types=1);

namespace Cms\I18n;

use Cms\Cms;
use Cms\Extend\Hooks;

/**
 * Keeps a visitor in their language: a link to a page leads to that page's version
 * in the language being read, wherever the link was written (a block, a template, a
 * menu, rich text), and stays where it is when that version is not live. Links that
 * say which language they lead to, with hreflang, mean it: the language switcher.
 */
final class LocalLinks
{
    /** @var array<string, ?string> path|locale => localised path, or null for no change */
    private array $resolved = [];

    public function __construct(private readonly Cms $cms, private readonly Languages $languages, private readonly Translations $translations, private readonly Hooks $hooks) {}

    public function localize(string $html, string $locale): string
    {
        if (! $this->languages->multilingual()) {
            return $html;
        }

        return (string) preg_replace_callback('~<a\b[^>]*>~i', function (array $tag) use ($locale): string {
            if (preg_match('~\shreflang=~i', $tag[0]) || ! preg_match('~\shref="(/(?!/)[^"?#]*)~i', $tag[0], $href, PREG_OFFSET_CAPTURE)) {
                return $tag[0];
            }

            $path = $this->resolve($href[1][0], $locale);

            return $path === null ? $tag[0] : substr_replace($tag[0], $path, $href[1][1], strlen($href[1][0]));
        }, $html);
    }

    private function resolve(string $path, string $locale): ?string
    {
        $key = $path.'|'.$locale;

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $normal = $path === '/' ? '/' : rtrim($path, '/');
        $entry = $this->cms->entries()->findByUri($normal);
        $version = $entry && $entry->locale !== $locale ? $this->translations->in($entry, $locale) : null;

        if ($entry && $entry->locale !== $locale) {
            // Publishing a translation changes where this page's link goes.
            $this->hooks->dispatch('render.depend', 'entry:'.$entry->id);
            $version && $this->hooks->dispatch('render.depend', 'entry:'.$version->id);
        }

        return $this->resolved[$key] = $version && $version->isLive() && $version->uri !== null ? $version->uri : null;
    }
}
