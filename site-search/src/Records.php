<?php

declare(strict_types=1);

namespace Cms\SiteSearch;

use Cms\Content\Entries\Entry;

/**
 * What the plugin adds to a page's record in /search.json: its kind (the collection's name for one entry, "Service",
 * "News"), the day it was published for a dated collection, and the words an editor gave it in a field named
 * `keywords`, which find it as well as its own ("bin" for a page on waste collection). Records stay small: a key is
 * only there when it has something to say.
 */
final class Records
{
    /** How long the keywords may be: a list of synonyms, not a second text. */
    private const int KEYWORDS_LIMIT = 300;

    public static function add(array $record, Entry $entry): array
    {
        $collection = cms()->collections()->find($entry->collection);

        if ($collection !== null) {
            $kind = trim($collection->singular) !== '' ? trim($collection->singular) : trim($collection->title);
            $kind !== '' && $record['kind'] = $kind;

            if ($collection->structure === 'dated' && $entry->publishedAt !== null) {
                $record['date'] = $entry->publishedAt->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d');
            }
        }

        $keywords = self::keywords($entry->data['keywords'] ?? null);
        $keywords !== '' && $record['keywords'] = $keywords;

        return $record;
    }

    /** A field's words as one line: a text as it is, a list joined by commas. */
    private static function keywords(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map(static fn ($item) => is_scalar($item) ? trim((string) $item) : '', $value), static fn (string $item) => $item !== ''));
        }

        if (! is_string($value)) {
            return '';
        }

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags($value))), 0, self::KEYWORDS_LIMIT);
    }
}
