<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Cms\Content\Entries\Entry;
use Cms\Extend\Hooks;
use Cms\Cms;

/**
 * Page templates are entries of the `templates` collection (content/collections/templates/*.md):
 * block documents that frame an entry, with a Page content block (`outlet`) where
 * the entry's own blocks go. Being entries, they have drafts, publishing, history
 * and comments, and agents edit them with the entries abilities.
 *
 * A collection names its template (`template: post`, the template's slug); an
 * entry may choose another. `default` frames anything else and `404` renders
 * missing pages.
 */
final class Templates
{
    public const string COLLECTION = 'templates';

    /** @var array<string, array<string, array>> */
    private array $cache = [];

    public function __construct(private readonly Cms $cms, private readonly Hooks $hooks) {}

    /**
     * @param  bool  $working  include drafts and unpublished changes (previews, the builder)
     * @return array<string, array{handle: string, id: string, title: string, description: string, collections: list<string>, blocks: list<array>}>
     */
    public function all(bool $working = false): array
    {
        $key = $working ? 'working' : 'live';

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        if (! $this->cms->collections()->find(self::COLLECTION)) {
            return [];
        }

        $query = $this->cms->entries()->query()->collection(self::COLLECTION);
        $out = [];

        foreach (($working ? $query : $query->live())->get() as $entry) {
            $out[$entry->slug] = self::shape($working ? $entry->withWorkingCopy() : $entry);
        }

        ksort($out);

        return $this->cache[$key] = $out;
    }

    public function find(string $handle, bool $working = false): ?array
    {
        return $this->all($working)[$handle] ?? null;
    }

    /** The template for a page: the entry's choice, the collection's, then `default`. */
    public function resolve(array $candidates, bool $working = false): ?array
    {
        // Any template change can change which template a page gets.
        $this->hooks->dispatch('render.depend', 'collection:'.self::COLLECTION);

        foreach ([...$candidates, 'default'] as $handle) {
            if (is_string($handle) && $handle !== '' && ($template = $this->find($handle, $working))) {
                return $template;
            }
        }

        return null;
    }

    /** @return array<string, string> handle => title, for template pickers */
    public function options(?string $collection = null): array
    {
        $out = [];

        foreach ($this->all(true) as $handle => $template) {
            if ((string) $handle !== '404' && ($collection === null || $template['collections'] === [] || in_array($collection, $template['collections'], true))) {
                $out[(string) $handle] = $template['title'];
            }
        }

        return $out;
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    public static function shape(Entry $entry): array
    {
        return [
            'handle' => $entry->slug,
            'id' => $entry->id,
            'title' => $entry->title,
            'description' => (string) ($entry->data['description'] ?? ''),
            'collections' => array_values(array_filter((array) ($entry->data['collections'] ?? []), 'is_string')),
            'blocks' => array_values((array) ($entry->data['blocks'] ?? [])),
        ];
    }
}
