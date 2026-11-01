<?php

declare(strict_types=1);

namespace Cms\Blocks;

use ArrayAccess;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Stringable;

/**
 * The data a document is rendered with: `entry`, `site`, `globals`, `item`
 * inside a Repeat, `props` inside a component… Bindings read it by path:
 *
 *   entry.title            item.image.url         globals.footer.tagline
 *   entry.date|date:M j    item.excerpt|excerpt:120|default:No summary
 *
 * Scopes are immutable; Repeat and components render their children with a
 * child scope (`with`).
 */
final class Scope
{
    public const string PATH = '/^[a-z_][a-z0-9_]*(\.[a-z0-9_-]+)*(\|[a-z]+(:[^|]{0,60})?){0,4}$/i';

    /** @param array<string, mixed> $vars */
    public function __construct(public readonly array $vars = []) {}

    public function with(array $vars): self
    {
        return new self([...$this->vars, ...$vars]);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->vars);
    }

    /** Resolve "path|filter:arg|filter" to a value (null when anything along the way is missing). */
    public function get(string $expression): mixed
    {
        if (! preg_match(self::PATH, $expression)) {
            return null;
        }

        $filters = explode('|', $expression);
        $path = array_shift($filters);
        $value = $this->vars;

        foreach (explode('.', $path) as $segment) {
            $value = self::step($value, $segment);

            if ($value === null) {
                break;
            }
        }

        foreach ($filters as $filter) {
            [$name, $arg] = array_pad(explode(':', $filter, 2), 2, null);
            $value = $this->filter($value, $name, $arg);
        }

        return $value;
    }

    /** Is this a text with {placeholders} rather than a single path? */
    public static function isTemplate(string $binding): bool
    {
        return str_contains($binding, '{');
    }

    /** A valid binding: a path ("entry.title|upper") or a text with {path} placeholders. */
    public static function valid(string $binding): bool
    {
        if (! self::isTemplate($binding)) {
            return (bool) preg_match(self::PATH, $binding);
        }

        if (strlen($binding) > 400 || ! preg_match_all('/\{([^{}]*)\}/', $binding, $m)) {
            return false;
        }

        foreach ($m[1] as $path) {
            if (! preg_match(self::PATH, trim($path))) {
                return false;
            }
        }

        return true;
    }

    /** Fill {path|filter} placeholders with text: "© {site.year} {site.name}". */
    public function interpolate(string $template): string
    {
        return (string) preg_replace_callback('/\{([^{}]*)\}/', fn (array $m) => self::text($this->get(trim($m[1]))), $template);
    }

    /** Resolve a binding: a path gives the value itself, a template gives text. */
    public function resolve(string $binding): mixed
    {
        return self::isTemplate($binding) ? $this->interpolate($binding) : $this->get($binding);
    }

    /** Plain text for a value (dates formatted, HTML stripped, lists joined). */
    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null, $value === false => '',
            $value === true => '1',
            $value instanceof DateTimeInterface => $value->format('F j, Y'),
            is_array($value) => implode(', ', array_map(self::text(...), array_filter($value, static fn ($v) => ! is_array($v)))),
            $value instanceof Htmlable => trim(html_entity_decode(strip_tags($value->toHtml()), ENT_QUOTES | ENT_HTML5)),
            is_scalar($value), $value instanceof Stringable => (string) $value,
            is_object($value) && isset($value->title) => (string) $value->title,
            default => '',
        };
    }

    /** Is a bound value "set" (for visibility and states)? */
    public static function truthy(mixed $value): bool
    {
        return match (true) {
            is_array($value) => $value !== [],
            $value instanceof \Countable => count($value) > 0,
            is_string($value) => trim($value) !== '' && $value !== '0',
            default => (bool) $value,
        };
    }

    /** A date in the language being rendered: `date` alone is that language's long date, `date:M j` a format of your own. */
    private static function date(mixed $value, ?string $format): mixed
    {
        $date = match (true) {
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value),
            is_string($value) && strtotime($value) => CarbonImmutable::createFromTimestamp((int) strtotime($value), date_default_timezone_get()),
            default => null,
        };

        if ($date === null) {
            return $value;
        }

        $date = $date->locale(app('translator')->getLocale());

        return $format ? $date->translatedFormat($format) : $date->isoFormat('LL');
    }

    private static function step(mixed $value, string $segment): mixed
    {
        if (is_array($value)) {
            return $value[$segment] ?? null;
        }

        if ($value instanceof ArrayAccess && isset($value[$segment])) {
            return $value[$segment];
        }

        if (is_object($value)) {
            if (isset($value->{$segment})) {
                return $value->{$segment};
            }

            // Lazily resolved values (relations, children…) answer through __get.
            if (method_exists($value, '__get')) {
                return $value->{$segment};
            }
        }

        return null;
    }

    private function filter(mixed $value, string $name, ?string $arg): mixed
    {
        return match (strtolower($name)) {
            'date' => self::date($value, $arg),
            'excerpt' => Str::limit(self::text($value), max(10, (int) ($arg ?: 160))),
            'upper' => mb_strtoupper(self::text($value)),
            'lower' => mb_strtolower(self::text($value)),
            'default' => self::truthy($value) ? $value : (string) $arg,
            'count' => is_countable($value) ? count($value) : 0,
            'first' => is_array($value) ? (array_values($value)[0] ?? null) : $value,
            'not' => ! self::truthy($value),
            'is' => self::text($value) === (string) $arg,
            'raw' => $value,
            default => $value,
        };
    }
}
