<?php

declare(strict_types=1);

namespace Cms\I18n;

use Cms\Cms;
use Cms\Support\RecordTimestamps;
use Cms\Support\Yaml;

/** The site's languages: names, URL prefixes and text direction. */
final class Languages
{
    private const array RTL = ['ar', 'fa', 'he', 'ur', 'yi', 'ps', 'dv', 'ku', 'sd', 'ug'];

    private array $snapshot = [];

    public function __construct(private readonly Cms $cms) {}

    /** Capture effective language settings before settings writes begin. */
    public function track(): void
    {
        $this->snapshot = array_column($this->all(), null, 'code');
    }

    /** Only changed languages receive an update date; unrelated site settings do not. */
    public function recordChanges(): void
    {
        $file = site_path('config/language-timestamps.yaml');
        $stored = Yaml::read($file);
        $next = [];
        $changed = false;
        foreach ($this->all() as $language) {
            $code = $language['code'];
            $previous = $this->snapshot[$code] ?? null;
            $settings = static fn (array $row) => array_diff_key($row, array_flip(['publishedAt', 'updatedAt']));
            if ($previous === null || $settings($previous) !== $settings($language)) {
                $next[$code] = RecordTimestamps::saved($previous);
                $changed = true;
            } elseif (isset($stored[$code])) {
                $next[$code] = $stored[$code];
            }
        }
        if ($changed || array_diff_key($stored, $next) !== []) {
            Yaml::write($file, $next);
        }
        $this->track();
    }

    /** @return list<string> */
    public function codes(): array
    {
        return $this->cms->locales();
    }

    public function default(): string
    {
        return $this->cms->defaultLocale();
    }

    public function enabled(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    public function multilingual(): bool
    {
        return count($this->codes()) > 1;
    }

    /** URL prefix for a language ('' = none). The default language has none unless configured. */
    public function prefix(string $code): string
    {
        if ($code === $this->default() && ! $this->cms->settings('i18n', 'prefix_default', false)) {
            return '';
        }

        return strtolower($code);
    }

    /** Name of the language in that language ("Deutsch"), for switchers. */
    public static function name(string $code, ?string $in = null): string
    {
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayName(str_replace('-', '_', $code), str_replace('-', '_', $in ?? $code));

            if ($name !== '' && $name !== $code) {
                return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
            }
        }

        return strtoupper($code);
    }

    public static function direction(string $code): string
    {
        return in_array(strtolower(explode('-', $code)[0]), self::RTL, true) ? 'rtl' : 'ltr';
    }

    /** Everything the admin and agents need about each language. */
    public function all(): array
    {
        $timestamps = Yaml::read(site_path('config/language-timestamps.yaml'));

        return array_map(fn (string $code) => [
            ...RecordTimestamps::api((array) ($timestamps[$code] ?? [])),
            'code' => $code,
            'name' => self::name($code),
            'englishName' => self::name($code, 'en'),
            'default' => $code === $this->default(),
            'prefix' => $this->prefix($code),
            'direction' => self::direction($code),
        ], $this->codes());
    }

    /** Fingerprint of everything that shapes URLs, to notice when they must be recomputed. */
    public function signature(): string
    {
        return md5(json_encode(array_map(fn ($c) => [$c, $this->prefix($c)], $this->codes())));
    }
}
