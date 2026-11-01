<?php

declare(strict_types=1);

namespace Cms\Site\Design;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Cms\Support\Files;
use Cms\Support\SafeFetcher;
use RuntimeException;

/**
 * Self-hosted fonts in content/design/fonts: imported from Google Fonts (no
 * requests to Google from the site afterwards) or uploaded as .woff2.
 */
final class Fonts
{
    /** Google serves woff2 only to browsers it recognizes. */
    private const string BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    public function __construct(private readonly Design $design) {}

    /** @return list<array> the font entries added to the design system */
    public function import(string $family, bool $italic = false): array
    {
        $family = trim($family);

        if (! preg_match('/^[A-Za-z0-9 ]{2,60}$/', $family)) {
            throw new InvalidArgumentException(__('Use the family name as Google Fonts shows it, like “Inter Tight”.'));
        }

        $name = str_replace(' ', '+', $family);
        $axes = $italic ? ':ital,wght@0,100..900;1,100..900' : ':wght@100..900';
        $css = null;

        // Variable weights when the family has them, else the common static weights.
        foreach ([$axes, $italic ? ':ital,wght@0,400;0,500;0,600;0,700;1,400;1,700' : ':wght@400;500;600;700', ''] as $variant) {
            try {
                $css = SafeFetcher::get("https://fonts.googleapis.com/css2?family={$name}{$variant}&display=swap", 500_000, 15, 3, self::BROWSER)['body'];
                break;
            } catch (RuntimeException) {
                continue;
            }
        }

        if ($css === null) {
            throw new InvalidArgumentException(__('Google Fonts has no family called “:family”.', ['family' => $family]));
        }

        // Only the latin subset of each face.
        preg_match_all('#/\*\s*latin\s*\*/\s*@font-face\s*\{([^}]*)\}#', $css, $faces);
        $added = [];
        $slug = Str::slug($family);

        foreach ($faces[1] as $face) {
            if (! preg_match('#src:\s*url\((https://fonts\.gstatic\.com/[^)]+\.woff2)\)#', $face, $src)) {
                continue;
            }

            $style = preg_match('/font-style:\s*italic/', $face) ? 'italic' : 'normal';
            $weight = preg_match('/font-weight:\s*([\d ]+);/', $face, $w) ? trim($w[1]) : '400';
            $file = $slug.'-'.str_replace(' ', '-', $weight).($style === 'italic' ? '-italic' : '').'.woff2';
            $data = SafeFetcher::get($src[1], 2_000_000, 20, 3, self::BROWSER)['body'];

            if (! str_starts_with($data, 'wOF2')) {
                continue;
            }

            Files::put($this->design->path('fonts/'.$file), $data);
            $added[] = ['family' => $family, 'src' => 'fonts/'.$file, 'weight' => $weight, 'style' => $style, 'preload' => $style === 'normal' && ! str_contains($weight, ' ') ? $weight === '400' : $style === 'normal'];
        }

        if ($added === []) {
            throw new InvalidArgumentException(__('Google Fonts has no Latin .woff2 files for “:family”.', ['family' => $family]));
        }

        $this->remember($family, $added);

        return $added;
    }

    public function upload(string $family, string $name, string $base64, string $weight, string $style): array
    {
        $data = base64_decode(preg_replace('#^data:[^,]*,#', '', $base64) ?? '', true);

        if ($data === false || ! str_starts_with($data, 'wOF2')) {
            throw new InvalidArgumentException(__('Upload a .woff2 font file.'));
        }

        if (! preg_match('/^\d{3}( \d{3})?$/', $weight)) {
            throw new InvalidArgumentException(__('The weight is a number like 400, or a range like 100 900.'));
        }

        $file = Str::slug($name ?: $family).'-'.str_replace(' ', '-', $weight).($style === 'italic' ? '-italic' : '').'.woff2';
        Files::put($this->design->path('fonts/'.$file), $data);
        $font = ['family' => trim($family), 'src' => 'fonts/'.$file, 'weight' => $weight, 'style' => $style === 'italic' ? 'italic' : 'normal'];
        $this->remember(trim($family), [$font], replace: false);

        return $font;
    }

    /** Add font faces to the design system (replacing earlier faces of the family). */
    private function remember(string $family, array $faces, bool $replace = true): void
    {
        $json = $this->design->json();
        $fonts = $replace ? array_values(array_filter($json->fonts(), static fn ($f) => ($f['family'] ?? null) !== $family)) : $json->fonts();
        $json->setFonts([...$fonts, ...$faces]);
        $json->save();
    }
}
