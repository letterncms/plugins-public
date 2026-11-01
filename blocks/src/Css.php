<?php

declare(strict_types=1);

namespace Cms\Blocks;

/**
 * CSS values written by people and agents, made safe to print.
 *
 *   token:space.8               → var(--space-8)
 *   token:color.primary/40      → color-mix(in srgb, var(--color-primary) 40%, transparent)
 *   0 8px 24px token:color.ink/20  tokens work inside longer values too
 *   media:01j…                  → url("/media/…") (an uploaded file)
 *   linear-gradient(…)          → as written
 *
 * Anything that could leave a declaration (braces, semicolons, angle brackets,
 * backslashes, comments, @import, url() written by hand) is refused.
 */
final class Css
{
    private const string TOKEN = '/token:([a-z]+)\.([a-z0-9-]+)(?:\/(\d{1,3}))?/';

    private const string MEDIA = '/media:([0-9a-z]{26})/i';

    /** Group → CSS custom property prefix (see letterncms/site DesignJson::GROUPS). */
    private const array PREFIX = ['weight' => 'font-weight'];

    public static function value(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > 600 || preg_match('/[{};<>\\\\]|url\s*\(|expression\s*\(|@import|\/\*|javascript:/i', $value)) {
            return null;
        }

        $value = preg_replace_callback(self::TOKEN, static function (array $m): string {
            $var = 'var(--'.(self::PREFIX[$m[1]] ?? $m[1]).'-'.$m[2].')';

            return isset($m[3]) ? "color-mix(in srgb, {$var} {$m[3]}%, transparent)" : $var;
        }, $value) ?? $value;

        if (str_contains($value, 'media:')) {
            $value = preg_replace_callback(self::MEDIA, static function (array $m): string {
                $url = Media::url($m[1]);

                return $url ? 'url("'.str_replace(['"', '\\'], '', $url).'")' : 'none';
            }, $value) ?? $value;
        }

        return str_contains($value, 'token:') ? null : $value;
    }

    /** A token reference as its custom property name, e.g. --color-primary (or null). */
    public static function variable(string $ref): ?string
    {
        return preg_match('/^token:([a-z]+)\.([a-z0-9-]+)$/', $ref, $m) ? '--'.(self::PREFIX[$m[1]] ?? $m[1]).'-'.$m[2] : null;
    }
}
