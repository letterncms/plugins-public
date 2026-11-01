<?php

declare(strict_types=1);

namespace Cms\Blocks;

/**
 * Icons for the Icon and Button blocks: Phosphor (regular, bold, fill, light)
 * rendered inline as SVG, so they take the text color and need no requests.
 * Names: "arrow-right", "arrow-right-bold", or with the set prefix "ph:arrow-right".
 */
final class Icons
{
    private static ?array $icons = null;

    public static function svg(string $name, string $class = ''): string
    {
        $body = self::body($name);

        if ($body === null) {
            return '';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="1em" height="1em"'.($class !== '' ? ' class="'.e($class).'"' : '').' aria-hidden="true" focusable="false">'.$body.'</svg>';
    }

    public static function body(string $name): ?string
    {
        $name = strtolower(trim(preg_replace('/^ph:/', '', $name) ?? ''));

        if ($name === '' || ! preg_match('/^[a-z0-9-]{1,60}$/', $name)) {
            return null;
        }

        self::$icons ??= require dirname(__DIR__).'/resources/icons/phosphor.php';

        return self::$icons[$name] ?? null;
    }

    /** Names for the picker (regular weight; append -bold, -fill or -light for the others). */
    public static function search(string $query = '', int $limit = 120): array
    {
        $names = json_decode((string) file_get_contents(dirname(__DIR__).'/resources/icons/phosphor.json'), true) ?: [];
        $query = strtolower(trim($query));

        if ($query !== '') {
            $words = preg_split('/[\s-]+/', $query) ?: [];
            $names = array_values(array_filter($names, static fn ($n) => array_reduce($words, static fn ($ok, $w) => $ok && str_contains($n, $w), true)));
            usort($names, static fn ($a, $b) => (int) ! str_starts_with($a, $query) <=> (int) ! str_starts_with($b, $query) ?: strlen($a) <=> strlen($b));
        }

        return array_map(static fn ($n) => ['name' => $n, 'svg' => self::svg($n)], array_slice($names, 0, $limit));
    }
}
