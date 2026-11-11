<?php

declare(strict_types=1);

namespace Cms\Seo;

use Cms\Support\Brand;
use Cms\Content\Entries\Entry;
use Cms\Cms;

/** sitemap.xml and robots.txt, and what SEO adds to the site's llms.txt. */
final class SiteFiles
{
    /** Crawlers that feed AI products — each can be allowed or blocked in settings. */
    public const array AI_CRAWLERS = [
        'GPTBot' => 'OpenAI (training)',
        'OAI-SearchBot' => 'ChatGPT search',
        'ChatGPT-User' => 'ChatGPT browsing',
        'ClaudeBot' => 'Anthropic (training)',
        'Claude-SearchBot' => 'Claude search',
        'Claude-User' => 'Claude browsing',
        'PerplexityBot' => 'Perplexity',
        'Google-Extended' => 'Google Gemini (training)',
        'Applebot-Extended' => 'Apple Intelligence (training)',
        'CCBot' => 'Common Crawl',
        'Bytespider' => 'ByteDance',
        'Meta-ExternalAgent' => 'Meta AI',
    ];

    public function __construct(private readonly Cms $cms, private readonly Meta $meta) {}

    /** @return list<Entry> live, indexable, routable entries */
    public function pages(): array
    {
        $out = [];

        foreach ($this->cms->collections()->all() as $collection) {
            if (! $collection->routable() || $collection->get('sitemap', true) === false) {
                continue;
            }

            foreach ($this->cms->entries()->query()->collection($collection->handle)->live()->orderBy('uri')->get() as $entry) {
                if ($entry->uri !== null && empty($entry->data['seo']['noindex'])) {
                    $out[] = $entry;
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> language => its sitemap's path, once more than one language has pages */
    public function languageSitemaps(): array
    {
        $out = [];

        foreach ($this->pages() as $entry) {
            $out[$entry->locale] ??= 'sitemap-'.$entry->locale.'.xml';
        }

        return count($out) > 1 ? $out : [];
    }

    /**
     * The sitemap: one file while one language has pages. With more, /sitemap.xml is an
     * index of a file per language, so Search Console reports on each language by itself.
     */
    public function sitemap(?string $locale = null): string
    {
        if ($locale === null && ($files = $this->languageSitemaps()) !== []) {
            return implode("\n", [
                '<?xml version="1.0" encoding="UTF-8"?>',
                '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
                ...array_map(fn (string $path) => '<sitemap><loc>'.e((string) $this->meta->absolute($path)).'</loc></sitemap>', array_values($files)),
                '</sitemapindex>',
            ])."\n";
        }

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'];

        foreach ($this->pages() as $entry) {
            if ($locale !== null && $entry->locale !== $locale) {
                continue;
            }

            $xml[] = '<url><loc>'.e((string) $this->meta->absolute($entry->uri)).'</loc>'
                .($entry->updatedAt ? '<lastmod>'.$entry->updatedAt->toDateString().'</lastmod>' : '')
                .implode('', array_map(fn ($alt) => '<xhtml:link rel="alternate" hreflang="'.e($alt['locale']).'" href="'.e((string) $this->meta->absolute($alt['uri'])).'"/>', $this->cms->hooks()->filter('seo.alternates', [], $entry)))
                .'</url>';
        }

        // Addresses the core or a plugin answers itself: in the sitemap of the language they say (`locale`),
        // and in the default language's when they say none.
        foreach ((array) $this->cms->hooks()->filter('seo.sitemap', [], $locale) as $url) {
            // One that stands for an entry (a term's archive is the term at another address): left out when the
            // entry is kept out of search, and with the addresses of its other languages, as a page has them.
            $page = ($url['entry'] ?? null) instanceof Entry ? clone $url['entry'] : null;

            if ($page && ! empty($page->data['seo']['noindex'])) {
                continue;
            }

            $page && $page->uri = (string) $url['uri'];

            if ($locale === null || ($url['locale'] ?? $this->cms->defaultLocale()) === $locale) {
                $xml[] = '<url><loc>'.e((string) $this->meta->absolute((string) $url['uri'])).'</loc>'.(($url['updated'] ?? null) ? '<lastmod>'.e((string) $url['updated']).'</lastmod>' : '')
                    .($page ? implode('', array_map(fn ($alt) => '<xhtml:link rel="alternate" hreflang="'.e($alt['locale']).'" href="'.e((string) $this->meta->absolute($alt['uri'])).'"/>', $this->cms->hooks()->filter('seo.alternates', [], $page))) : '')
                    .'</url>';
            }
        }

        $xml[] = '</urlset>';

        return implode("\n", $xml)."\n";
    }

    public function robots(): string
    {
        $blocked = array_values((array) $this->meta->settings('blocked_crawlers', []));
        $lines = ['# robots.txt generated by '.Brand::get(), 'User-agent: *', 'Disallow: /admin', 'Disallow: /api/', 'Disallow: /__preview/'];

        if ($this->meta->settings('discourage_indexing', false)) {
            $lines = ['User-agent: *', 'Disallow: /'];
        }

        foreach ($blocked as $agent) {
            if (isset(self::AI_CRAWLERS[$agent])) {
                $lines[] = '';
                $lines[] = "User-agent: {$agent}";
                $lines[] = 'Disallow: /';
            }
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.$this->cms->url().'/sitemap.xml';

        return implode("\n", $lines)."\n";
    }

    /** RFC 9116. Null until the site names a contact. Written fresh, so it is a year from expiring whenever it is built. */
    public function securityTxt(): ?string
    {
        $contact = trim((string) $this->meta->settings('security_contact', ''));

        if ($contact === '') {
            return null;
        }

        $uri = str_contains($contact, '@') && ! str_contains($contact, ':') ? 'mailto:'.$contact : $this->meta->absolute($contact);

        return "Contact: {$uri}\nExpires: ".now()->addYear()->utc()->format('Y-m-d\TH:i:s\Z')."\n";
    }

    /**
     * The site's llms.txt (the core writes it, see `site.llms`), as SEO knows the pages: the summary written
     * for it, only pages search engines may list, each with its description and linked as its Markdown twin.
     *
     * @param  array{title: string, summary: string, sections: array<string, list<array{title: string, url: string, description: string, entry: Entry}>>}  $document
     */
    public function llms(array $document): array
    {
        $summary = trim((string) $this->meta->settings('llms_summary', ''));
        $listed = array_flip(array_map(static fn (Entry $entry) => $entry->id, $this->pages()));

        foreach ($document['sections'] as $title => $pages) {
            $document['sections'][$title] = array_values(array_map(function (array $page) {
                $entry = $page['entry'];

                return [
                    ...$page,
                    'url' => (string) $this->meta->absolute($entry->uri === '/' ? '/index.md' : $entry->uri.'.md'),
                    'description' => (string) $this->meta->forEntry($entry)['description'],
                ];
            }, array_filter($pages, static fn (array $page) => ($page['entry'] ?? null) instanceof Entry && isset($listed[$page['entry']->id]))));
        }

        return [...$document, 'summary' => $summary !== '' ? $summary : $document['summary']];
    }
}
