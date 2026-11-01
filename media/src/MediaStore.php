<?php

declare(strict_types=1);

namespace Cms\Media;

use Illuminate\Support\Str;
use Cms\Abilities\AbilityException;
use Cms\Support\Files;
use Cms\Support\SafeFetcher;
use Cms\Users\User;
use Throwable;

/** Everything that adds, changes or removes media files. */
final class MediaStore
{
    /** Detected MIME type => stored extension. Anything else is refused. */
    public const array TYPES = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf', 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
        'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
        'text/plain' => 'txt', 'text/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/zip' => 'zip',
    ];

    public function __construct(
        private readonly ImageProcessor $images,
        private readonly SvgSanitizer $svg,
        private readonly Variants $variants,
    ) {}

    private function manifest(): MediaManifest
    {
        return app(MediaManifest::class);
    }

    public function maxBytes(): int
    {
        return (int) cms()->settings('media', 'max_upload_mb', 32) * 1024 * 1024;
    }

    /** Store an uploaded or downloaded file. The temp file is moved or removed. */
    public function store(string $tmp, string $originalName, ?User $user = null, string $folder = ''): MediaItem
    {
        try {
            if (! is_file($tmp) || filesize($tmp) === 0) {
                throw AbilityException::invalid(__('The file is empty.'));
            }

            if (filesize($tmp) > $this->maxBytes()) {
                throw AbilityException::invalid(__('The file is larger than :size MB.', ['size' => cms()->settings('media', 'max_upload_mb', 32)]));
            }

            $mime = $this->detect($tmp, $originalName);
            $ext = self::TYPES[$mime] ?? throw AbilityException::invalid(__('Files of type :type are not allowed.', ['type' => $mime]));

            if ($mime === 'image/svg+xml') {
                file_put_contents($tmp, $this->svg->clean((string) file_get_contents($tmp)));
            } elseif (str_starts_with($mime, 'image/')) {
                if (! $this->images->dimensions($tmp)) {
                    throw AbilityException::invalid(__('This image could not be read.'));
                }

                // Remove EXIF (location, camera) by re-encoding photos.
                if ($mime === 'image/jpeg' && cms()->settings('media', 'strip_metadata', true)) {
                    $this->images->strip($tmp, 'jpg');
                }
            }

            $hash = hash_file('sha256', $tmp);
            $folder = $this->folder($folder);

            if ($existing = MediaItem::query()->where('hash', $hash)->where('folder', $folder)->first()) {
                return $existing;
            }

            $base = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'file';
            $relative = 'media/'.date('Y/m').'/'.mb_substr($base, 0, 80).'-'.Str::lower(Str::random(4)).'.'.$ext;
            $target = Files::within(public_path(), $relative);

            Files::ensureDir(dirname($target));

            if (! @rename($tmp, $target) && ! @copy($tmp, $target)) {
                throw new AbilityException(__('Could not save the file.'));
            }

            @chmod($target, 0o644);
            [$width, $height] = $this->dimensions($target, $mime);

            $item = MediaItem::query()->create([
                'path' => $relative,
                'name' => Str::of(pathinfo($originalName, PATHINFO_FILENAME))->replace(['-', '_'], ' ')->squish()->limit(120, '')->ucfirst()->toString() ?: 'Untitled',
                'filename' => basename($relative),
                'mime' => $mime,
                'size' => filesize($target),
                'width' => $width,
                'height' => $height,
                'folder' => $folder,
                'hash' => $hash,
                'user_id' => $user?->getKey(),
            ]);

            try {
                $this->manifest()->locked(fn () => $this->manifest()->write($item));
            } catch (Throwable $e) {
                $item->delete();
                @unlink($target);

                throw new AbilityException($e->getMessage());
            }

            return $item;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function importUrl(string $url, ?User $user = null, string $folder = ''): MediaItem
    {
        try {
            $response = SafeFetcher::get($url, $this->maxBytes());
        } catch (Throwable $e) {
            throw AbilityException::invalid($e->getMessage());
        }

        $tmp = $this->tmp();
        file_put_contents($tmp, $response['body']);
        $name = basename((string) parse_url($response['url'], PHP_URL_PATH)) ?: 'download';

        return $this->store($tmp, $name, $user, $folder);
    }

    public function importBase64(string $filename, string $data, ?User $user = null, string $folder = ''): MediaItem
    {
        $data = preg_replace('#^data:[^;]+;base64,#', '', $data) ?? $data;
        $binary = base64_decode($data, true);

        if ($binary === false) {
            throw AbilityException::invalid(__('The data is not valid base64.'));
        }

        $tmp = $this->tmp();
        file_put_contents($tmp, $binary);

        return $this->store($tmp, $filename, $user, $folder);
    }

    public function update(MediaItem $item, array $attributes): MediaItem
    {
        $focalChanged = (isset($attributes['focal']['x']) && (int) $attributes['focal']['x'] !== $item->focal_x) || (isset($attributes['focal']['y']) && (int) $attributes['focal']['y'] !== $item->focal_y);

        $item->fill(array_filter([
            'name' => $attributes['name'] ?? null,
            'alt' => array_key_exists('alt', $attributes) ? (string) $attributes['alt'] : null,
            'caption' => array_key_exists('caption', $attributes) ? (string) $attributes['caption'] : null,
            'folder' => isset($attributes['folder']) ? $this->folder($attributes['folder']) : null,
            'focal_x' => isset($attributes['focal']['x']) ? max(0, min(100, (int) $attributes['focal']['x'])) : null,
            'focal_y' => isset($attributes['focal']['y']) ? max(0, min(100, (int) $attributes['focal']['y'])) : null,
        ], static fn ($v) => $v !== null));

        $this->manifest()->locked(function () use ($item) {
            $item->save();
            $this->manifest()->write($item);
        });

        if ($focalChanged) {
            $this->variants->forget($item);
        }

        cms()->hooks()->dispatch('media.updated', $item);

        return $item;
    }

    public function delete(MediaItem $item): void
    {
        $this->manifest()->locked(function () use ($item) {
            $this->variants->forget($item);
            @unlink(Files::within(public_path(), $item->path));
            $this->manifest()->forget($item->id);
            $item->delete();
        });

        cms()->hooks()->dispatch('media.deleted', $item);
    }

    public function detect(string $file, string $name): string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file);

        // SVGs are often reported as text; trust content, not the name.
        if (in_array($mime, ['text/plain', 'text/xml', 'application/xml', 'image/svg'], true) && preg_match('/<svg[\s>]/i', (string) file_get_contents($file, false, null, 0, 4096))) {
            return 'image/svg+xml';
        }

        // Office formats are zip containers; keep their real type when the name matches.
        if ($mime === 'application/zip' || $mime === 'application/octet-stream') {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $office = array_search($ext, self::TYPES, true);

            if ($office && str_starts_with((string) $office, 'application/vnd.openxmlformats')) {
                return (string) $office;
            }
        }

        if ($mime === 'text/plain' && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'csv') {
            return 'text/csv';
        }

        return $mime;
    }

    /** @return array{0: ?int, 1: ?int} */
    private function dimensions(string $file, string $mime): array
    {
        if ($mime === 'image/svg+xml') {
            $svg = (string) file_get_contents($file, false, null, 0, 4096);

            if (preg_match('/viewBox="[\d.\-]+\s+[\d.\-]+\s+([\d.]+)\s+([\d.]+)"/i', $svg, $m)) {
                return [(int) round((float) $m[1]), (int) round((float) $m[2])];
            }

            return [null, null];
        }

        return str_starts_with($mime, 'image/') ? ($this->images->dimensions($file) ?? [null, null]) : [null, null];
    }

    private function folder(string $folder): string
    {
        $parts = array_filter(array_map(static fn ($p) => Str::slug($p), explode('/', $folder)));

        return implode('/', array_slice($parts, 0, 6));
    }

    private function tmp(): string
    {
        $dir = storage_path('app/uploads');

        Files::ensureDir($dir);

        return $dir.'/'.bin2hex(random_bytes(12));
    }
}
