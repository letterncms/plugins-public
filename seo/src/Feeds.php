<?php

declare(strict_types=1);

namespace Cms\Seo;

use Cms\Cms;

/**
 * RSS for collections of dated entries, built next to their pages: /blog/feed.xml, and
 * /de/blog/feed.xml for another language. On unless the collection says `feed: false`;
 * `feed: {path, limit, title}` sets the rest.
 */
final class Feeds
{
    public function __construct(private readonly Cms $cms, private readonly Meta $meta) {}

    /** @return array<string, array{collection: string, locale: string, listing: string}> path from the site root => what it holds */
    public function all(): array
    {
        $out = [];

        foreach ($this->cms->collections()->all() as $collection) {
            $feed = $collection->get('feed');

            if ($feed === false || ! $collection->routable() || ($collection->structure !== 'dated' && ! is_array($feed))) {
                continue;
            }

            foreach ($this->cms->locales() as $locale) {
                $base = '/'.trim((string) preg_replace('#/?\{.*$#', '', (string) $collection->routeFor($locale)), '/');
                $path = trim((string) (is_array($feed) ? ($feed['path'] ?? '') : '') ?: ($base === '/' ? '' : $base).'/feed.xml', '/');

                if ($this->cms->entries()->query()->collection($collection->handle)->locale($locale)->live()->limit(1)->get() === []) {
                    continue;
                }

                $prefix = $locale === $this->cms->defaultLocale() ? '' : $locale.'/';
                $out[$prefix.$path] = ['collection' => $collection->handle, 'locale' => $locale, 'listing' => rtrim('/'.$prefix.ltrim($base, '/'), '/') ?: '/'];
            }
        }

        return $out;
    }

    public function render(string $handle, string $locale): string
    {
        $collection = $this->cms->collections()->find($handle);
        $feed = (array) ($collection?->get('feed') ?: []);
        $limit = max(1, min(100, (int) ($feed['limit'] ?? 20)));
        $site = (string) $this->cms->setting('site', 'name', '');
        $title = (string) ($feed['title'] ?? ($site !== '' ? $collection->title.' · '.$site : $collection->title));
        $path = array_search(['collection' => $handle, 'locale' => $locale], array_map(static fn ($f) => ['collection' => $f['collection'], 'locale' => $f['locale']], $this->all()), true);
        $x = static fn (?string $value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $items = '';
        $latest = null;

        foreach ($this->cms->entries()->query()->collection($handle)->locale($locale)->live()->orderBy('-published_at')->limit($limit)->get() as $entry) {
            if ($entry->uri === null) {
                continue;
            }

            $published = $entry->publishedAt;
            $latest ??= $published;
            $url = $this->meta->absolute($entry->uri);
            $summary = (string) ($entry->get('summary') ?: $entry->get('excerpt') ?: ($entry->get('seo')['description'] ?? ''));
            $items .= '<item><title>'.$x($entry->title).'</title><link>'.$x($url).'</link><guid isPermaLink="true">'.$x($url).'</guid>'
                .($published ? '<pubDate>'.$published->toRssString().'</pubDate>' : '')
                .($summary !== '' ? '<description>'.$x($summary).'</description>' : '').'</item>';
        }

        $listing = $this->meta->absolute($this->all()[(string) $path]['listing'] ?? '/');

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel><title>'.$x($title).'</title><link>'.$x($listing).'</link>'
            .'<atom:link href="'.$x($this->meta->absolute((string) $path)).'" rel="self" type="application/rss+xml"/>'
            .'<description>'.$x($collection->description ?: $title).'</description><language>'.$x($locale).'</language>'
            .($latest ? '<lastBuildDate>'.$latest->toRssString().'</lastBuildDate>' : '')
            .$items.'</channel></rss>'."\n";
    }
}
