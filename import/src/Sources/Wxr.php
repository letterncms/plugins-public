<?php

declare(strict_types=1);

namespace Cms\Import\Sources;

use Cms\Abilities\AbilityException;
use Cms\Import\HtmlToMarkdown;
use Cms\Import\Record;
use DOMDocument;
use SimpleXMLElement;
use XMLReader;

/**
 * A WordPress export (Tools → Export, "WXR"): posts, pages and the attachments they use.
 * Read as a stream, one item at a time, without loading anything the file points to: a
 * document type or an entity declaration is refused outright, since an export has neither.
 */
final class Wxr
{
    public const int MAX_BYTES = 268_435_456;

    /** For an ability, which runs inside a request. */
    public const int MAX_BYTES_REQUEST = 20_971_520;

    /**
     * @param  array<string, string>  $collections  WordPress post type => collection handle
     * @return array{records: list<Record>, media: array<string, array{url: string, name: string, alt: string}>, warnings: list<string>}
     */
    public static function read(string $file, array $collections, int $maxBytes = self::MAX_BYTES): array
    {
        if (filesize($file) > $maxBytes) {
            throw AbilityException::invalid($maxBytes < self::MAX_BYTES
                ? __('The export is larger than :size MB. Import it from the command line: php cms import wordpress <file>.', ['size' => intdiv($maxBytes, 1_048_576)])
                : __('The export is larger than :size MB.', ['size' => intdiv($maxBytes, 1_048_576)]));
        }

        // A cheap first look: nothing an export needs, and everything an XML attack starts with.
        $handle = fopen($file, 'rb');
        $head = (string) fread($handle, 65_536);
        fclose($handle);

        if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false) {
            throw AbilityException::invalid(__('This file declares a document type or entities, which a WordPress export never does. It was not read.'));
        }

        $reader = new XMLReader();
        $previous = libxml_use_internal_errors(true);

        if (! $reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT)) {
            libxml_use_internal_errors($previous);
            throw AbilityException::invalid(__('The export could not be read.'));
        }

        $host = '';
        $records = [];
        $attachments = [];
        $thumbnails = [];
        $skipped = [];
        $sawChannel = false;

        try {
            while (@$reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE || $reader->nodeType === XMLReader::ENTITY_REF || $reader->nodeType === XMLReader::ENTITY) {
                    throw AbilityException::invalid(__('This file declares a document type or entities, which a WordPress export never does. It was not read.'));
                }

                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($reader->name === 'channel') {
                    $sawChannel = true;
                } elseif ($host === '' && in_array($reader->name, ['wp:base_site_url', 'wp:base_blog_url', 'link'], true) && $reader->depth === 2) {
                    $host = (string) parse_url(trim($reader->readString()), PHP_URL_HOST);
                } elseif ($reader->name === 'item') {
                    $node = $reader->expand();

                    if ($node === false) {
                        break;
                    }

                    $document = new DOMDocument();
                    $item = simplexml_import_dom($document->importNode($node, true));

                    if (! $item instanceof SimpleXMLElement) {
                        continue;
                    }

                    $wp = self::children($item, 'wp');
                    $type = (string) ($wp->post_type ?? 'post');
                    $id = (string) ($wp->post_id ?? '');

                    if ($type === 'attachment') {
                        $url = trim((string) ($wp->attachment_url ?? ''));

                        if ($url !== '') {
                            $attachments[$id] = ['url' => $url, 'name' => trim((string) $item->title) ?: basename((string) parse_url($url, PHP_URL_PATH)), 'alt' => self::meta($wp, '_wp_attachment_image_alt') ?? ''];
                        }

                        continue;
                    }

                    if (! isset($collections[$type])) {
                        if (! in_array($type, ['nav_menu_item', 'revision', 'custom_css', 'wp_global_styles', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_block', 'customize_changeset', 'oembed_cache'], true)) {
                            $skipped[$type] = ($skipped[$type] ?? 0) + 1;
                        }

                        continue;
                    }

                    $status = (string) ($wp->status ?? 'draft');

                    if (in_array($status, ['trash', 'auto-draft', 'inherit'], true) || $id === '') {
                        continue;
                    }

                    $record = self::record($item, $wp, $host, $collections[$type], $id, $status);
                    $thumbnail = self::meta($wp, '_thumbnail_id');

                    if ($thumbnail !== null) {
                        $thumbnails[$record->key] = $thumbnail;
                    }

                    $records[] = $record;
                }
            }

            $error = libxml_get_last_error();

            if (! $sawChannel || ($error !== false && $error->level === LIBXML_ERR_FATAL)) {
                throw AbilityException::invalid(__('This is not a WordPress export: :error', ['error' => $error !== false ? trim($error->message) : __('it has no channel.')]));
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // Only the files something shows: an image in the text, or a featured image.
        $media = [];
        $byUrl = array_column($attachments, null, 'url');

        foreach ($records as $record) {
            if (isset($thumbnails[$record->key], $attachments[$thumbnails[$record->key]])) {
                $record->image = $attachments[$thumbnails[$record->key]]['url'];
                $media[$record->image] = $attachments[$thumbnails[$record->key]];
            }

            foreach (self::images((string) $record->body) as $src) {
                $original = self::original($src);

                foreach ([$src, $original] as $candidate) {
                    if (isset($byUrl[$candidate])) {
                        $media[$candidate] = $byUrl[$candidate];

                        continue 2;
                    }
                }

                // An image of the same site that the export has no attachment for is fetched all the same.
                if ($host !== '' && parse_url($src, PHP_URL_HOST) === $host) {
                    $media[$original] = ['url' => $original, 'name' => basename((string) parse_url($original, PHP_URL_PATH)), 'alt' => ''];
                }
            }
        }

        // Parents before their children, so a page finds the page it sits under.
        $byKey = array_column($records, null, 'key');
        $depths = array_map(static fn (Record $r) => self::depth($r, $byKey), $byKey);
        usort($records, static fn (Record $a, Record $b) => $depths[$a->key] <=> $depths[$b->key]);

        $warnings = array_map(static fn (string $type, int $count) => __(':count of type “:type” left out: no collection was named for it.', ['count' => $count, 'type' => $type]), array_keys($skipped), $skipped);

        return ['records' => $records, 'media' => $media, 'warnings' => $warnings];
    }

    private static function record(SimpleXMLElement $item, SimpleXMLElement $wp, string $host, string $collection, string $id, string $status): Record
    {
        $html = (string) (self::children($item, 'content')->encoded ?? '');
        $warnings = [];

        if (preg_match_all('/\[([a-z][a-z0-9_-]*)(?=[\s\]])[^\]]*\]/i', (string) preg_replace('/\[\/?caption[^\]]*\]/', '', $html), $m)) {
            $warnings[] = __('Shortcodes are kept as text: :names', ['names' => implode(', ', array_slice(array_unique($m[1]), 0, 8))]);
        }

        $tags = [];
        $categories = [];

        foreach ($item->category as $term) {
            $name = trim((string) $term);

            if ($name === '' || $name === 'Uncategorized') {
                continue;
            }

            if ((string) $term['domain'] === 'post_tag') {
                $tags[] = $name;
            } elseif ((string) $term['domain'] === 'category') {
                $categories[] = $name;
            }
        }

        $date = trim((string) ($wp->post_date_gmt ?? ''));
        $date = $date === '' || str_starts_with($date, '0000') ? trim((string) ($wp->post_date ?? '')) : $date.' UTC';
        $time = $date === '' || str_starts_with($date, '0000') ? false : strtotime($date);
        $parent = (string) ($wp->post_parent ?? '0');
        $link = (string) parse_url(trim((string) $item->link), PHP_URL_PATH);
        $query = (string) parse_url(trim((string) $item->link), PHP_URL_QUERY);

        return new Record(
            key: 'wordpress:'.($host ?: 'site').':'.$id,
            collection: $collection,
            title: trim(html_entity_decode((string) $item->title, ENT_QUOTES | ENT_HTML5)) ?: __('Untitled'),
            slug: rawurldecode(trim((string) ($wp->post_name ?? ''))) ?: null,
            // A future or private post is not public there, so it isn't here either.
            status: $status === 'publish' ? 'published' : 'draft',
            at: $status === 'publish' && $time !== false ? gmdate('c', $time) : null,
            data: array_filter([
                'excerpt' => trim(strip_tags((string) (self::children($item, 'excerpt')->encoded ?? ''))),
                'tags' => $tags,
                'categories' => $categories,
            ], static fn ($v) => $v !== '' && $v !== []),
            body: HtmlToMarkdown::convert($html),
            // Plain permalinks (?p=42) have no path to redirect from.
            redirectFrom: $status === 'publish' && $link !== '' && $link !== '/' && $query === '' ? [$link] : [],
            parentKey: $parent !== '' && $parent !== '0' ? 'wordpress:'.($host ?: 'site').':'.$parent : null,
            warnings: $warnings,
        );
    }

    /** A namespace's children by its prefix, whatever version of the export format declared it. */
    private static function children(SimpleXMLElement $item, string $prefix): SimpleXMLElement
    {
        $namespaces = $item->getNamespaces(true);

        return isset($namespaces[$prefix]) ? $item->children($namespaces[$prefix]) : new SimpleXMLElement('<none/>');
    }

    private static function meta(SimpleXMLElement $wp, string $key): ?string
    {
        foreach ($wp->postmeta as $meta) {
            if ((string) $meta->meta_key === $key && trim((string) $meta->meta_value) !== '') {
                return trim((string) $meta->meta_value);
            }
        }

        return null;
    }

    /** @return list<string> The addresses of the images in a Markdown text. */
    public static function images(string $markdown): array
    {
        preg_match_all('/!\[[^\]]*\]\((\S+?)\)/', $markdown, $m);

        return array_values(array_unique(array_map(static fn (string $u) => str_replace(['%20', '%28', '%29'], [' ', '(', ')'], $u), $m[1])));
    }

    /** The file WordPress cut a size from: photo-300x200.jpg is photo.jpg. */
    public static function original(string $url): string
    {
        return (string) preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url);
    }

    /** @param array<string, Record> $byKey */
    private static function depth(Record $record, array $byKey): int
    {
        $depth = 0;

        while ($record->parentKey !== null && isset($byKey[$record->parentKey]) && $depth < 20) {
            $record = $byKey[$record->parentKey];
            $depth++;
        }

        return $depth;
    }
}
