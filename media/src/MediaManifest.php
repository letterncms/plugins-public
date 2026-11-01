<?php

declare(strict_types=1);

namespace Cms\Media;

use Carbon\CarbonImmutable;
use Cms\Support\Files;
use Cms\Support\Yaml;
use Throwable;

/**
 * Media records as content: one YAML file per item in content/media, next to the
 * binaries in public/media. Both go in git, and the `media` table is an index that
 * can be rebuilt from them, so a fresh clone, a reset or a deploy keeps every image.
 *
 *   content/media/<id>.yaml   id and path are required; what is left out is read from the file
 *
 * Everything that changes media writes through here, and import() brings the table
 * in line after files changed by git, FTP or a tool. It runs on `php cms sync`, on
 * install, before a full build, after an admin request and when a page asks for an
 * id the table doesn't know, whichever comes first.
 */
final class MediaManifest
{
    private const array COLUMNS = ['path', 'name', 'filename', 'mime', 'size', 'width', 'height', 'hash', 'folder', 'alt', 'caption', 'focal_x', 'focal_y', 'meta'];

    public const string ID = '/^[0-9a-z]{26}$/';

    public function __construct(private readonly ImageProcessor $images, private readonly SvgSanitizer $svg, private readonly Variants $variants) {}

    public function dir(): string
    {
        return content_path('media');
    }

    /** Run a change to media with nothing else changing it at the same time. */
    public function locked(callable $work): mixed
    {
        $dir = storage_path('locks');
        Files::ensureDir($dir);
        $lock = fopen($dir.'/media.lock', 'c');
        $lock && flock($lock, LOCK_EX);

        try {
            return $work();
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function write(MediaItem $item): void
    {
        $file = $this->file($item->id);

        try {
            Yaml::write($file, $this->record($item));
        } catch (Throwable $e) {
            throw new \RuntimeException(__('Could not write :file. Make sure content/media is writable.', ['file' => 'content/media/'.$item->id.'.yaml']), previous: $e);
        }

        $this->remember();
    }

    public function forget(string $id): void
    {
        @unlink($this->file($id));
        $this->remember();
    }

    /** Has anything in content/media changed since this install last looked? */
    public function stale(): bool
    {
        $state = storage_path('media-manifest.json');

        return (is_file($state) ? (string) file_get_contents($state) : '') !== $this->signature();
    }

    public function signature(): string
    {
        $parts = [];

        foreach (glob($this->dir().'/*.yaml') ?: [] as $file) {
            $parts[] = basename($file).':'.(int) @filemtime($file).':'.(int) @filesize($file);
        }

        sort($parts);

        return sha1(implode('|', $parts));
    }

    /**
     * Bring the table in line with the files. One bad record never stops the rest,
     * and a file that doesn't parse stops anything from being deleted, so a bad merge
     * can't lose rows. Out of time: the next trigger carries on.
     *
     * @return array{added: list<string>, updated: list<string>, removed: list<string>, exported: list<string>, missing_files: list<string>, refused: list<array{id: string, reason: string}>, parse_errors: list<string>, finished: bool}
     */
    public function import(?float $budget = null): array
    {
        return $this->locked(function () use ($budget): array {
            $report = ['added' => [], 'updated' => [], 'removed' => [], 'exported' => [], 'missing_files' => [], 'refused' => [], 'parse_errors' => [], 'finished' => true];
            $deadline = $budget !== null ? microtime(true) + $budget : null;
            $first = ! is_file(storage_path('media-manifest.json'));
            $records = [];

            foreach (glob($this->dir().'/*.yaml') ?: [] as $file) {
                $name = basename($file, '.yaml');

                try {
                    $record = Yaml::read($file);
                } catch (Throwable $e) {
                    $report['parse_errors'][] = 'content/media/'.basename($file).': '.$e->getMessage();

                    continue;
                }

                if (($record['id'] ?? null) !== $name || ! preg_match(self::ID, $name)) {
                    $report['parse_errors'][] = 'content/media/'.basename($file).': the id must equal the file name, a lowercase ULID.';

                    continue;
                }

                $records[$name] = $record;
            }

            foreach ($records as $id => $record) {
                if ($deadline !== null && microtime(true) > $deadline) {
                    $report['finished'] = false;

                    return $report;
                }

                $reason = $this->validate($record);

                if ($reason !== null) {
                    $report['refused'][] = ['id' => $id, 'reason' => $reason];

                    continue;
                }

                try {
                    $this->apply($id, $record, $report);
                } catch (Throwable $e) {
                    report($e);
                    $report['parse_errors'][] = "content/media/{$id}.yaml: ".$e->getMessage();
                }
            }

            foreach (MediaItem::query()->whereNotIn('id', array_keys($records))->cursor() as $item) {
                if ($first) {
                    // Uploaded before this install kept records: keep it by writing one.
                    Yaml::write($this->file($item->id), $this->record($item));
                    $report['exported'][] = $item->id;
                } elseif ($report['parse_errors'] === []) {
                    $this->variants->forget($item);
                    $item->delete();
                    $report['removed'][] = $item->id;
                    cms()->hooks()->dispatch('media.deleted', $item);
                }
            }

            $report['parse_errors'] === [] && $this->remember();

            return $report;
        });
    }

    /** Why a record may not be imported, or null. The same bar as an upload. */
    public function validate(array $record): ?string
    {
        $path = $record['path'] ?? null;

        if (! is_string($path) || ! preg_match('#^media/[^\\\\]+$#', $path) || str_contains($path, '..')) {
            return 'invalid_path';
        }

        try {
            $file = Files::within(public_path(), $path);
        } catch (Throwable) {
            return 'invalid_path';
        }

        if (! is_file($file)) {
            return null;
        }

        $mime = $this->detect($file, $path);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ((MediaStore::TYPES[$mime] ?? null) !== $ext) {
            return 'refused_type';
        }

        if ($mime === 'image/svg+xml' && $this->svg->clean((string) file_get_contents($file)) !== (string) file_get_contents($file)) {
            return 'unsafe_svg';
        }

        return null;
    }

    private function apply(string $id, array $record, array &$report): void
    {
        $file = public_path($record['path']);
        $exists = is_file($file);
        $exists || $report['missing_files'][] = $id;
        $values = $this->values($record, $file, $exists);
        $item = MediaItem::query()->find($id);
        $current = $item ? array_intersect_key($item->getAttributes(), array_flip(self::COLUMNS)) : [];
        $casted = $item ? ['meta' => $item->meta] + $current : [];
        $changed = $item === null;

        foreach (self::COLUMNS as $column) {
            if (! $changed && ($casted[$column] ?? null) != ($values[$column] ?? null)) {
                $changed = true;
            }
        }

        if (! $changed) {
            return;
        }

        $pixels = $item && ($item->hash !== $values['hash'] || $item->path !== $values['path'] || $item->focal_x !== $values['focal_x'] || $item->focal_y !== $values['focal_y']);
        $item && $pixels && $this->variants->forget($item);

        $new = $item === null;
        $item ??= new MediaItem;
        $item->timestamps = false;
        $item->forceFill(['id' => $id, ...$values, 'created_at' => $this->date($record['created_at'] ?? null) ?? $item->created_at ?? now(), 'updated_at' => $this->date($record['updated_at'] ?? null) ?? $item->updated_at ?? now()]);
        $item->exists = ! $new;
        $item->save();
        $item->timestamps = true;

        $report[$new ? 'added' : 'updated'][] = $id;
        $new || cms()->hooks()->dispatch('media.updated', $item);
    }

    /** What the table should hold: the record, with the binary filling in and winning on what it knows. */
    private function values(array $record, string $file, bool $exists): array
    {
        $path = (string) $record['path'];
        $name = basename($path);
        $values = [
            'path' => $path,
            'name' => (string) ($record['name'] ?? pathinfo($name, PATHINFO_FILENAME)),
            'filename' => (string) ($record['filename'] ?? $name),
            'mime' => (string) ($record['mime'] ?? 'application/octet-stream'),
            'size' => (int) ($record['size'] ?? 0),
            'width' => isset($record['width']) ? (int) $record['width'] : (isset($record['w']) ? (int) $record['w'] : null),
            'height' => isset($record['height']) ? (int) $record['height'] : (isset($record['h']) ? (int) $record['h'] : null),
            'hash' => (string) ($record['hash'] ?? ''),
            'folder' => (string) ($record['folder'] ?? ''),
            'alt' => isset($record['alt']) ? (string) $record['alt'] : null,
            'caption' => isset($record['caption']) ? (string) $record['caption'] : null,
            'focal_x' => (int) ($record['focal']['x'] ?? 50),
            'focal_y' => (int) ($record['focal']['y'] ?? 50),
            'meta' => ($record['meta'] ?? null) ?: null,
        ];

        if ($exists) {
            $size = (int) filesize($file);

            if ($values['hash'] === '' || $values['size'] !== $size) {
                $values['hash'] = (string) hash_file('sha256', $file);
                $values['size'] = $size;
                $values['mime'] = $this->detect($file, $path);
                $values['width'] = $values['height'] = null;
            }

            if ($values['width'] === null && str_starts_with($values['mime'], 'image/') && $values['mime'] !== 'image/svg+xml') {
                [$values['width'], $values['height']] = $this->images->dimensions($file) ?? [null, null];
            }
        }

        return $values;
    }

    private function record(MediaItem $item): array
    {
        return array_filter([
            'id' => $item->id,
            'path' => $item->path,
            'name' => $item->name,
            'filename' => $item->filename,
            'mime' => $item->mime,
            'size' => (int) $item->size,
            'width' => $item->width,
            'height' => $item->height,
            'hash' => $item->hash,
            'folder' => $item->folder,
            'alt' => $item->alt,
            'caption' => $item->caption,
            'focal' => ['x' => (int) ($item->focal_x ?? 50), 'y' => (int) ($item->focal_y ?? 50)],
            'meta' => $item->meta ?: null,
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    private function detect(string $file, string $name): string
    {
        return app(MediaStore::class)->detect($file, $name);
    }

    /** Timestamps come as a quoted string or, when written by hand without quotes, as an integer. */
    private function date(mixed $value): ?CarbonImmutable
    {
        try {
            return match (true) {
                is_int($value) => CarbonImmutable::createFromTimestampUTC($value),
                is_string($value) && $value !== '' => CarbonImmutable::parse($value),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function file(string $id): string
    {
        return $this->dir().'/'.$id.'.yaml';
    }

    private function remember(): void
    {
        Files::put(storage_path('media-manifest.json'), $this->signature());
    }
}
