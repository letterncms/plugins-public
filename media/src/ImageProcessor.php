<?php

declare(strict_types=1);

namespace Cms\Media;

use GdImage;
use RuntimeException;

/** Resize, crop to focal point and encode images with GD. */
final class ImageProcessor
{
    public function supports(string $format): bool
    {
        return match ($format) {
            'avif' => function_exists('imageavif') && (gd_info()['AVIF Support'] ?? false),
            'webp' => function_exists('imagewebp'),
            'jpg', 'png' => true,
            default => false,
        };
    }

    /** @return array{0: int, 1: int}|null */
    public function dimensions(string $file): ?array
    {
        $info = @getimagesize($file);

        return $info ? [(int) $info[0], (int) $info[1]] : null;
    }

    /**
     * Write a resized copy. With $ratio (w/h) the image is cropped around the focal point.
     */
    public function resize(string $source, string $target, int $width, string $format, int $quality = 80, ?float $ratio = null, array $focal = [50, 50]): void
    {
        $image = $this->load($source);
        $srcW = imagesx($image);
        $srcH = imagesy($image);

        [$cropX, $cropY, $cropW, $cropH] = [0, 0, $srcW, $srcH];

        if ($ratio !== null && $ratio > 0) {
            if ($srcW / $srcH > $ratio) {
                $cropW = (int) round($srcH * $ratio);
                $cropX = (int) round(max(0, min($srcW - $cropW, $srcW * $focal[0] / 100 - $cropW / 2)));
            } else {
                $cropH = (int) round($srcW / $ratio);
                $cropY = (int) round(max(0, min($srcH - $cropH, $srcH * $focal[1] / 100 - $cropH / 2)));
            }
        }

        $width = min($width, $cropW);
        $height = (int) max(1, round($cropH * $width / $cropW));

        $out = imagecreatetruecolor($width, $height);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $image, 0, 0, $cropX, $cropY, $width, $height, $cropW, $cropH);

        $this->save($out, $target, $format, $quality);
        imagedestroy($image);
        imagedestroy($out);
    }

    /** Re-encode an image in place (drops EXIF/GPS metadata). */
    public function strip(string $file, string $format): void
    {
        $image = $this->load($file);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $this->save($image, $file, $format, 90);
        imagedestroy($image);
    }

    private function load(string $file): GdImage
    {
        $info = @getimagesize($file);

        if (! $info) {
            throw new RuntimeException(__('Not a readable image.'));
        }

        if ((int) $info[0] * (int) $info[1] > 60_000_000) {
            throw new RuntimeException(__('The image is too large to process (over 60 megapixels).'));
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_GIF => @imagecreatefromgif($file),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file),
            IMAGETYPE_AVIF => function_exists('imagecreatefromavif') ? @imagecreatefromavif($file) : false,
            default => false,
        };

        if (! $image instanceof GdImage) {
            throw new RuntimeException(__('Unsupported image format.'));
        }

        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $orientation = (int) (@exif_read_data($file)['Orientation'] ?? 1);
            $image = match ($orientation) {
                3 => imagerotate($image, 180, 0),
                6 => imagerotate($image, -90, 0),
                8 => imagerotate($image, 90, 0),
                default => $image,
            };
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    private function save(GdImage $image, string $target, string $format, int $quality): void
    {
        $dir = dirname($target);

        \Cms\Support\Files::ensureDir($dir);

        $tmp = $target.'.'.bin2hex(random_bytes(3)).'.tmp';
        $ok = match ($format) {
            'avif' => imageavif($image, $tmp, max(30, $quality - 25), 8),
            'webp' => imagewebp($image, $tmp, $quality),
            'png' => imagepng($image, $tmp, 7),
            default => imagejpeg($image, $tmp, $quality),
        };

        if (! $ok) {
            @unlink($tmp);
            throw new RuntimeException("Could not encode {$format}.");
        }

        rename($tmp, $target);
    }
}
