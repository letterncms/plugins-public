<?php

declare(strict_types=1);

namespace Cms\Site\Search;

use Cms\Content\Entries\Entry;
use Cms\Extend\Hooks;
use Cms\Cms;
use Cms\Site\Rendering\AugmentedEntry;

/**
 * /search.json — every live page as a small record (title, URL, description,
 * headings, plain text) so static sites get instant client-side search.
 * Plugins and themes adjust records with the `site.search.record` filter
 * (return null to leave a page out).
 */
final class SearchIndex
{
    private const int TEXT_LIMIT = 3000;

    public function __construct(private readonly Cms $cms, private readonly Hooks $hooks) {}

    public function json(): string
    {
        return (string) json_encode([
            'site' => config('app.name'),
            'generatedAt' => now()->toIso8601String(),
            'pages' => $this->records(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @return list<array> */
    public function records(): array
    {
        $records = [];

        foreach ($this->cms->collections()->all() as $collection) {
            if (! $collection->routable()) {
                continue;
            }

            foreach ($this->cms->entries()->query()->collection($collection->handle)->live()->get() as $entry) {
                if ($entry->uri !== null && ($record = $this->hooks->filter('site.search.record', $this->record($entry), $entry)) !== null) {
                    $records[] = $record;
                }
            }
        }

        return $records;
    }

    private function record(Entry $entry): array
    {
        $augmented = new AugmentedEntry($entry);
        $headings = [];
        $text = [];

        foreach ($entry->data as $value) {
            if (is_array($value) && array_is_list($value) && isset($value[0]['type']) && class_exists(\Cms\Modules\Blocks\BlocksDocument::class)) {
                $document = new \Cms\Modules\Blocks\BlocksDocument(app(\Cms\Site\Rendering\Renderer::class)->materialize($entry, $value));
                array_push($headings, ...$document->headings(3));
                $text[] = $document->text();
            } elseif (is_string($value) && mb_strlen($value) > 40) {
                $text[] = trim(strip_tags($value));
            }
        }

        $description = '';
        foreach (['seo.description', 'excerpt', 'summary', 'description'] as $key) {
            $candidate = data_get($entry->data, $key);
            if (is_string($candidate) && trim($candidate) !== '') {
                $description = trim($candidate);
                break;
            }
        }

        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'url' => $augmented->url(),
            'section' => $augmented->root()->is($entry) ? '' : $augmented->root()->title,
            'collection' => $entry->collection,
            'locale' => $entry->locale,
            'description' => $description,
            'headings' => array_map(static fn ($h) => ['text' => $h['text'], 'id' => $h['id'], 'level' => $h['level']], $headings),
            'text' => mb_substr(trim((string) preg_replace('/\s+/', ' ', implode(' ', $text))), 0, self::TEXT_LIMIT),
        ];
    }
}
