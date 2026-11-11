<?php

declare(strict_types=1);

namespace Cms\Import;

use Cms\Abilities\AbilityException;
use Cms\Import\Sources\Csv;
use Cms\Import\Sources\MarkdownFolder;
use Cms\Import\Sources\Wxr;
use Cms\Support\Files;
use ZipArchive;

/**
 * Files someone hands over in the admin, into the folder sources are read from: a WordPress
 * export, a CSV file, or a zip of Markdown, which is unpacked into a folder. Checked by what
 * they hold, not by what they are called, and never written outside the folder.
 */
final class Uploads
{
    /** What a zip may unpack to in all: a folder of text. */
    public const int MAX_UNPACKED = 104_857_600;

    public function __construct(private readonly Importer $importer) {}

    /** The largest file each kind may be, the same as reading it allows inside a request. */
    public static function limit(string $extension): int
    {
        return match ($extension) {
            'xml' => Wxr::MAX_BYTES_REQUEST,
            'csv', 'tsv' => Csv::MAX_BYTES,
            'zip' => self::MAX_UNPACKED,
            default => 0,
        };
    }

    /**
     * Take an uploaded file in. Returns the source as import/sources lists it.
     *
     * @return array{id: string, path: string, type: string, size: int|null}
     */
    public function store(string $tmp, string $name): array
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $limit = self::limit($extension);

        if ($limit === 0) {
            throw AbilityException::invalid(__('Upload a WordPress export (.xml), a CSV file or a zip of Markdown files.'));
        }

        if (! is_file($tmp) || is_link($tmp) || filesize($tmp) === 0) {
            throw AbilityException::invalid(__('The file is empty.'));
        }

        if (filesize($tmp) > $limit) {
            throw AbilityException::invalid(__('The file is larger than :size MB.', ['size' => intdiv($limit, 1_048_576)]));
        }

        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(\Illuminate\Support\Str::ascii(pathinfo($name, PATHINFO_FILENAME)))), '-') ?: 'import';
        $base = substr($base, 0, 80);
        $root = $this->importer->root();
        Files::ensureDir($root);

        if ($extension === 'zip') {
            $path = $this->free($root, $base, '');
            $this->unpack($tmp, $path);
        } else {
            $this->looksLike($tmp, $extension);
            $path = $this->free($root, $base, '.'.$extension);

            if (! @rename($tmp, $path) && ! @copy($tmp, $path)) {
                throw AbilityException::invalid(__('The file could not be saved.'));
            }

            @chmod($path, 0o644);
        }

        return ['id' => Importer::id(basename($path)), 'path' => basename($path), 'type' => $extension === 'zip' ? 'markdown' : ($extension === 'xml' ? 'wordpress' : 'csv'), 'size' => is_dir($path) ? null : (int) filesize($path)];
    }

    /** Remove a source from the folder: a file, or a folder with what it holds. Returns how many files went. */
    public function delete(string $path): int
    {
        $real = $this->importer->resolve($path);
        $count = $this->count($real);
        is_dir($real) ? Files::deleteDirectory($real) : @unlink($real);

        return $count;
    }

    /** How many files a source is. */
    public function count(string $real): int
    {
        if (! is_dir($real)) {
            return 1;
        }

        $count = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $count += $file->isFile() ? 1 : 0;
        }

        return $count;
    }

    /** A name in the folder that nothing has yet: `posts.csv`, then `posts-2.csv`. */
    private function free(string $root, string $base, string $suffix): string
    {
        for ($i = 1; ; $i++) {
            $path = $root.'/'.$base.($i === 1 ? '' : '-'.$i).$suffix;

            if (! file_exists($path) && ! is_link($path)) {
                return $path;
            }
        }
    }

    /** What the file holds is what its name says: an export is XML with a feed in it, a CSV is text. */
    private function looksLike(string $file, string $extension): void
    {
        $head = (string) file_get_contents($file, false, null, 0, 65_536);

        if ($extension === 'xml') {
            if (! preg_match('/^\s*(\xEF\xBB\xBF)?\s*<(\?xml|rss)\b/', $head) || ! str_contains($head, '<rss') || preg_match('/<!(DOCTYPE|ENTITY)/i', $head)) {
                throw AbilityException::invalid(__('This is not a WordPress export.'));
            }

            return;
        }

        // Text, line by line: no control characters but tabs and line ends (any byte is some Latin-1 letter, so the
        // encoding alone says nothing), and a first line that names at least one column.
        $first = strtok(ltrim($head, "\xEF\xBB\xBF\r\n"), "\r\n");
        $delimiter = $first === false ? ',' : array_search(max($counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")]), $counts, true);

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $head) || $first === false || array_filter(str_getcsv($first, (string) $delimiter, '"', ''), static fn ($cell) => trim((string) $cell) !== '') === []) {
            throw AbilityException::invalid(__('This is not a CSV file.'));
        }
    }

    /**
     * Unpack a zip of Markdown into a folder of its own: only Markdown files, by the names they have
     * inside it made safe, nothing above the folder, nothing linked, and no more than a folder may hold.
     */
    private function unpack(string $zipFile, string $folder): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw AbilityException::invalid(__('This server cannot open zip files.'));
        }

        $zip = new ZipArchive;

        if ($zip->open($zipFile, ZipArchive::RDONLY) !== true) {
            throw AbilityException::invalid(__('The zip file could not be opened.'));
        }

        $wanted = [];
        $bytes = 0;
        $written = 0;

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));

                // Folders, what a Mac adds, and anything that is not Markdown stay in the zip.
                if ($name === '' || str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || ! preg_match('/\.(md|markdown)$/i', $name)) {
                    continue;
                }

                $parts = array_values(array_filter(explode('/', $name), static fn (string $p) => $p !== '' && $p !== '.'));

                if (in_array('..', $parts, true) || str_starts_with($name, '/') || preg_match('/^[a-z]:/i', $name)) {
                    throw AbilityException::invalid(__('The zip holds a file outside its own folder.'));
                }

                // A link inside a zip is a file whose mode says so: never unpacked.
                $zip->getExternalAttributesIndex($i, $system, $attributes);

                if ($system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0o170000) === 0o120000) {
                    continue;
                }

                $bytes += (int) $stat['size'];

                if ((int) $stat['size'] > MarkdownFolder::MAX_BYTES || $bytes > self::MAX_UNPACKED) {
                    throw AbilityException::invalid(__('The zip unpacks to more than :size MB.', ['size' => intdiv(self::MAX_UNPACKED, 1_048_576)]));
                }

                if (count($wanted) >= MarkdownFolder::MAX_FILES) {
                    throw AbilityException::invalid(__('The folder holds more than :count Markdown files.', ['count' => MarkdownFolder::MAX_FILES]));
                }

                $wanted[$i] = implode('/', array_map(static fn (string $p) => trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', \Illuminate\Support\Str::ascii($p)), '-.') ?: 'file', $parts));
            }

            if ($wanted === []) {
                throw AbilityException::invalid(__('The zip holds no Markdown files.'));
            }

            // One folder around everything is the folder itself: `site/posts/a.md` becomes `posts/a.md`.
            $tops = array_unique(array_map(static fn (string $p) => str_contains($p, '/') ? explode('/', $p)[0] : '', $wanted));
            $strip = count($tops) === 1 && $tops[0] !== '' ? strlen($tops[0]) + 1 : 0;
            Files::ensureDir($folder);

            foreach ($wanted as $i => $relative) {
                $target = Files::within($folder, substr($relative, $strip));
                Files::ensureDir(dirname($target));
                $in = $zip->getStream((string) $zip->getNameIndex($i));
                $out = $in ? fopen($target, 'wb') : false;

                if (! $in || ! $out) {
                    throw AbilityException::invalid(__('The zip file could not be opened.'));
                }

                // The size a zip states is its own word: what is written is counted, file by file and in all.
                $cap = min(MarkdownFolder::MAX_BYTES, self::MAX_UNPACKED - $written);
                $copied = stream_copy_to_stream($in, $out, $cap + 1);
                fclose($in);
                fclose($out);
                $written += (int) $copied;

                if ($copied === false || $copied > $cap) {
                    throw AbilityException::invalid(__('The zip unpacks to more than :size MB.', ['size' => intdiv(self::MAX_UNPACKED, 1_048_576)]));
                }
            }
        } catch (\Throwable $e) {
            Files::deleteDirectory($folder);

            throw $e;
        } finally {
            $zip->close();
        }
    }
}
