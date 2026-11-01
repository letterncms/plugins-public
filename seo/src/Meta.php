<?php

declare(strict_types=1);

namespace Cms\Seo;

use Cms\Content\Entries\Entry;
use Cms\Cms;
use Cms\Support\Html\Sanitizer;

/** Resolved metadata for an entry: what search engines, social cards and AI see. */
final class Meta
{
    public function __construct(private readonly Cms $cms) {}

    public function settings(?string $key = null, mixed $default = null): mixed
    {
        return $this->cms->settings('seo', $key, $default);
    }

    public function siteUrl(): string
    {
        return rtrim((string) $this->cms->site('url', config('app.url')), '/');
    }

    public function absolute(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return preg_match('#^https?://#', $path) ? $path : $this->siteUrl().'/'.ltrim($path, '/');
    }

    /** @return array{title: string, description: string, image: ?string, canonical: string, robots: string, type: string, schema: string, published: ?string, modified: ?string} */
    public function forEntry(Entry $entry): array
    {
        $seo = (array) ($entry->data['seo'] ?? []);
        $site = (string) config('app.name');
        $title = trim((string) ($seo['title'] ?? '')) ?: $entry->title;
        $format = (string) $this->settings('title_format', '{title} · {site}');
        $isHome = self::isHome($entry);

        return [
            // The home title is written for the default language; another language's home goes by its own title.
            'title' => $isHome && $entry->locale === $this->cms->defaultLocale() && ($seo['title'] ?? '') === '' ? ($this->settings('home_title') ?: $site) : strtr($format, ['{title}' => $title, '{site}' => $site]),
            'description' => $this->description($entry, $seo),
            'image' => $this->image($entry, $seo),
            // A page picked as canonical is a path on this site: canonical URLs are absolute.
            'canonical' => (string) $this->absolute((string) ($seo['canonical'] ?? '') ?: $entry->uri),
            'robots' => ! empty($seo['noindex']) ? 'noindex, follow' : 'index, follow, max-image-preview:large',
            'type' => $entry->collection()->isDated() ? 'article' : 'website',
            'schema' => (string) ($seo['schema'] ?? '') ?: ($entry->collection()->isDated() ? 'Article' : ($isHome ? 'WebPage' : 'WebPage')),
            'published' => $entry->publishedAt?->toIso8601String(),
            'modified' => $entry->updatedAt?->toIso8601String(),
        ];
    }

    /** The home page of its language: `/`, or `/de` for its German version. */
    public static function isHome(Entry $entry): bool
    {
        $home = $entry->collection()->home;

        return $home !== null && $entry->slug === $home && $entry->parentId === null;
    }

    /** A language as Open Graph writes it, always with a territory: de_DE, en_US, pt_BR. */
    public static function ogLocale(string $locale): string
    {
        if (str_contains($locale, '-')) {
            return str_replace('-', '_', $locale);
        }

        return $locale.'_'.(['en' => 'US', 'da' => 'DK', 'el' => 'GR', 'cs' => 'CZ', 'sv' => 'SE', 'ja' => 'JP', 'ko' => 'KR', 'zh' => 'CN', 'uk' => 'UA', 'he' => 'IL', 'ar' => 'SA', 'fa' => 'IR'][$locale] ?? strtoupper($locale));
    }

    private function description(Entry $entry, array $seo): string
    {
        $text = trim((string) ($seo['description'] ?? ''));

        foreach (['excerpt', 'summary', 'description', 'intro'] as $field) {
            if ($text === '' && is_string($entry->data[$field] ?? null)) {
                $text = Sanitizer::text($entry->data[$field]);
            }
        }

        if ($text === '') {
            $text = $this->firstParagraph($entry->data);
        }

        if ($text === '') {
            $text = (string) ($this->cms->settings('site', 'description') ?? '');
        }

        return mb_strimwidth(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 0, 160, '…');
    }

    /** First paragraph of any blocks field — the "answer first" text. */
    public function firstParagraph(array $data): string
    {
        $found = '';
        $walk = function ($value) use (&$walk, &$found): void {
            if ($found !== '' || ! is_array($value)) {
                return;
            }

            if (($value['type'] ?? null) === 'paragraph' && is_string($value['props']['text'] ?? null) && trim(strip_tags($value['props']['text'])) !== '') {
                $found = Sanitizer::text($value['props']['text']);

                return;
            }

            foreach ($value as $child) {
                $walk($child);
            }
        };
        $walk($data);

        return $found;
    }

    private function image(Entry $entry, array $seo): ?string
    {
        foreach ([$seo['image'] ?? null, $entry->data['image'] ?? null, $entry->data['cover'] ?? null, $this->settings('default_image')] as $candidate) {
            $url = $this->cms->hooks()->filter('media.url', null, $candidate);

            if (is_string($url) && $url !== '') {
                return $this->absolute($url);
            }

            if (is_string($candidate) && preg_match('#^(https://|/)#', $candidate)) {
                return $this->absolute($candidate);
            }
        }

        return null;
    }
}
