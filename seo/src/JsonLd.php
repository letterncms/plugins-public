<?php

declare(strict_types=1);

namespace Cms\Seo;

use Cms\Content\Entries\Entry;
use Cms\Cms;

/**
 * One schema.org @graph per page: WebSite, Organization, the page (WebPage /
 * Article …), BreadcrumbList, plus anything blocks contribute (FAQPage…).
 */
final class JsonLd
{
    /** @var array<string, array> */
    private array $extra = [];

    public function __construct(private readonly Cms $cms, private readonly Meta $meta) {}

    public function add(string $key, array $data): void
    {
        $this->extra[$key] = $data;
    }

    public function reset(): void
    {
        $this->extra = [];
    }

    public function graph(Entry $entry): array
    {
        $site = $this->meta->siteUrl();
        $m = $this->meta->forEntry($entry);
        $org = array_filter([
            '@type' => $this->meta->settings('org_type', 'Organization'),
            '@id' => $site.'/#organization',
            'name' => $this->meta->settings('org_name') ?: config('app.name'),
            'url' => $site.'/',
            'logo' => $this->meta->absolute($this->cms->hooks()->filter('media.url', null, $this->meta->settings('org_logo'))),
            'sameAs' => array_values(array_filter((array) $this->meta->settings('same_as', []))) ?: null,
        ]);

        $page = array_filter([
            '@type' => $m['schema'],
            '@id' => $m['canonical'].'#page',
            'url' => $m['canonical'],
            'name' => $entry->title,
            'headline' => $m['schema'] === 'Article' ? $entry->title : null,
            'description' => $m['description'] ?: null,
            'image' => $m['image'],
            'inLanguage' => $entry->locale,
            'isPartOf' => ['@id' => $site.'/#website'],
            'datePublished' => $m['published'],
            'dateModified' => $m['modified'],
            'author' => $m['schema'] === 'Article' ? $this->author($entry) : null,
            'publisher' => $m['schema'] === 'Article' ? ['@id' => $site.'/#organization'] : null,
            'breadcrumb' => ! Meta::isHome($entry) ? ['@id' => $m['canonical'].'#breadcrumb'] : null,
        ]);

        $graph = [
            ['@type' => 'WebSite', '@id' => $site.'/#website', 'url' => $site.'/', 'name' => config('app.name'), 'publisher' => ['@id' => $site.'/#organization'], 'inLanguage' => $entry->locale],
            $org,
            $page,
        ];

        if (! Meta::isHome($entry) && ($crumbs = $this->breadcrumbs($entry)) !== []) {
            $graph[] = ['@type' => 'BreadcrumbList', '@id' => $m['canonical'].'#breadcrumb', 'itemListElement' => $crumbs];
        }

        foreach ($this->extra as $data) {
            $graph[] = $data;
        }

        return ['@context' => 'https://schema.org', '@graph' => array_values($graph)];
    }

    private function author(Entry $entry): ?array
    {
        $user = $entry->authorId ? \Cms\Users\User::query()->find($entry->authorId) : null;

        return $user ? ['@type' => 'Person', 'name' => $user->name] : ['@id' => $this->meta->siteUrl().'/#organization'];
    }

    private function breadcrumbs(Entry $entry): array
    {
        $chain = [$entry];

        for ($p = $entry->parentId ? $this->cms->entries()->find($entry->parentId) : null, $i = 0; $p && $i < 10; $p = $p->parentId ? $this->cms->entries()->find($p->parentId) : null, $i++) {
            if ($p->isLive() && ! Meta::isHome($p)) {
                array_unshift($chain, $p);
            }
        }

        // The trail starts at the home page of the language being read, under the name that page has.
        $collection = $entry->collection();
        $homeUri = (string) $this->cms->hooks()->filter('entry.uri', '/', $entry, $collection);
        $home = $this->cms->entries()->findByUri($homeUri, $entry->locale);
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => $home !== null ? $home->title : 'Home', 'item' => $this->meta->absolute($homeUri)]];

        foreach ($chain as $i => $e) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $e->title, 'item' => $this->meta->absolute($e->uri)];
        }

        return $items;
    }
}
