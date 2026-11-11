<?php

declare(strict_types=1);

namespace Cms\Import\Sources;

use Cms\Abilities\AbilityException;
use Cms\Content\Entries\Frontmatter;
use Cms\Import\Record;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * A folder of Markdown files, each with YAML front matter (Jekyll, Hugo, Eleventy, Astro, Obsidian).
 * `title`, `slug`, `date`, `draft` or `status`, and `redirect_from` or `aliases` are the entry's own;
 * every other key goes to the field of the same name. A file is told apart by its path in the folder.
 */
final class MarkdownFolder
{
    public const int MAX_FILES = 5000;

    public const int MAX_BYTES = 2_097_152;

    /** @return array{records: list<Record>, media: array<string, array>, warnings: list<string>} */
    public static function read(string $folder, string $collection): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            // A link could lead out of the folder: only what is really in it.
            if ($file->isFile() && ! $file->isLink() && in_array(strtolower($file->getExtension()), ['md', 'markdown', 'mdx'], true)) {
                $files[] = $file->getPathname();
            }

            if (count($files) > self::MAX_FILES) {
                throw AbilityException::invalid(__('The folder holds more than :count Markdown files.', ['count' => self::MAX_FILES]));
            }
        }

        sort($files);
        $records = [];
        $warnings = [];

        foreach ($files as $file) {
            $relative = ltrim(str_replace('\\', '/', substr($file, strlen($folder))), '/');

            if (filesize($file) > self::MAX_BYTES) {
                $warnings[] = __(':file is larger than :size MB and was left out.', ['file' => $relative, 'size' => intdiv(self::MAX_BYTES, 1_048_576)]);

                continue;
            }

            try {
                [$meta, $body] = Frontmatter::parse((string) file_get_contents($file));
            } catch (Throwable $e) {
                $warnings[] = __(':file has front matter that could not be read and was left out.', ['file' => $relative]);

                continue;
            }

            $name = pathinfo($file, PATHINFO_FILENAME);
            // Jekyll's dated file names: 2024-05-01-hello.md is "hello", published that day.
            $dated = preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $name, $m) === 1;
            $slugFromName = $dated ? $m[2] : ($name === 'index' || $name === '_index' ? basename(dirname($file)) : $name);
            $title = trim((string) ($meta['title'] ?? ''));
            $own = [];

            // Without a title in the front matter, the first heading is it, and no longer part of the text.
            if ($title === '' && preg_match('/\A\s*#\s+(.+)\n?/', $body, $h)) {
                $title = trim($h[1]);
                $body = ltrim(substr($body, strlen($h[0])));
            }

            $date = $meta['date'] ?? $meta['published_at'] ?? $meta['publishDate'] ?? $meta['pubDate'] ?? ($dated ? $m[1] : null);
            $date = $date instanceof \DateTimeInterface ? $date->format('c') : (is_int($date) ? '@'.$date : (is_string($date) ? $date : null));
            $time = $date !== null ? strtotime($date) : false;
            $draft = array_key_exists('draft', $meta) ? (bool) $meta['draft'] : (array_key_exists('published', $meta) && is_bool($meta['published']) ? ! $meta['published'] : (isset($meta['status']) ? ! in_array(strtolower((string) $meta['status']), ['published', 'publish', 'live'], true) : false));

            if ($date !== null && $time === false) {
                $own[] = __('The date “:date” could not be read.', ['date' => $date]);
            }

            $redirects = [];

            foreach (['redirect_from', 'aliases'] as $key) {
                foreach ((array) ($meta[$key] ?? []) as $path) {
                    if (is_string($path) && trim($path) !== '') {
                        $redirects[] = trim($path);
                    }
                }
            }

            $records[] = new Record(
                key: 'markdown:'.$collection.':'.$relative,
                collection: $collection,
                title: $title !== '' ? $title : ucfirst(str_replace(['-', '_'], ' ', $slugFromName)),
                slug: is_string($meta['slug'] ?? null) && trim($meta['slug']) !== '' ? trim(basename($meta['slug'])) : $slugFromName,
                status: $draft ? 'draft' : 'published',
                at: ! $draft && $time !== false ? gmdate('c', $time) : null,
                data: array_diff_key($meta, array_flip(['title', 'slug', 'date', 'published_at', 'publishDate', 'pubDate', 'draft', 'published', 'status', 'redirect_from', 'aliases', 'layout', 'permalink'])),
                body: $body,
                redirectFrom: $redirects,
                warnings: $own,
            );
        }

        return ['records' => $records, 'media' => [], 'warnings' => $warnings];
    }
}
