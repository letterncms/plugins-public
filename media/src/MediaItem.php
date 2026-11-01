<?php

declare(strict_types=1);

namespace Cms\Media;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Cms\Support\RecordTimestamps;

/**
 * @property string $id
 * @property string $path      relative to public/, e.g. media/2026/10/hero-k3j2.jpg
 * @property string $name
 * @property string $filename
 * @property string $mime
 * @property int $size
 * @property ?int $width
 * @property ?int $height
 * @property ?string $alt
 * @property ?string $caption
 * @property int $focal_x
 * @property int $focal_y
 * @property string $folder
 */
class MediaItem extends Model
{
    use HasUlids;

    protected $table = 'media';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'size' => 'integer', 'width' => 'integer', 'height' => 'integer', 'focal_x' => 'integer', 'focal_y' => 'integer'];
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/') && $this->mime !== 'image/svg+xml';
    }

    public function isSvg(): bool
    {
        return $this->mime === 'image/svg+xml';
    }

    public function url(): string
    {
        return '/'.$this->path;
    }

    public function kind(): string
    {
        return match (true) {
            str_starts_with($this->mime, 'image/') => 'image',
            str_starts_with($this->mime, 'video/') => 'video',
            str_starts_with($this->mime, 'audio/') => 'audio',
            $this->mime === 'application/pdf' => 'pdf',
            default => 'file',
        };
    }

    public function toPublic(): array
    {
        $variants = app(Variants::class);

        return [
            'id' => $this->id,
            'url' => $this->url(),
            'thumb' => $this->isImage() ? $variants->url($this, 480, 'webp') : ($this->isSvg() ? $this->url() : null),
            'name' => $this->name,
            'filename' => $this->filename,
            'mime' => $this->mime,
            'kind' => $this->kind(),
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'alt' => $this->alt,
            'caption' => $this->caption,
            'focal' => ['x' => $this->focal_x, 'y' => $this->focal_y],
            'folder' => $this->folder,
            'createdAt' => $this->created_at?->toIso8601String(),
            ...RecordTimestamps::api(['published_at' => $this->created_at, 'updated_at' => $this->updated_at]),
        ];
    }
}
