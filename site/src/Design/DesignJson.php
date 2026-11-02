<?php

declare(strict_types=1);

namespace Cms\Site\Design;

use InvalidArgumentException;
use Cms\Support\Files;

/**
 * content/design/tokens.json — the design system of a site.
 *
 *   tokens       DTCG design tokens (color, font, text, leading, tracking, weight, space, radius, shadow, container, breakpoint, duration, ease)
 *   fonts        self-hosted fonts: [{family, src: "fonts/Inter.woff2", weight: "100 900", style, preload}]
 *   styles       element defaults: { body: {...}, h1: {...}, a: {...}, "a:hover": {...} } — camelCase CSS, tokens allowed
 *   prose        styles for long-form text inside Page content marked as prose: { h2: {...}, p: {...} }
 *   buttons      button variants: { primary: { base: {...}, hover: {...} }, secondary: {...} }
 *   transition   page transitions between pages: { type: fade|slide|none, duration: 0.3 }
 */
final class DesignJson
{
    /** Token groups and the CSS custom property prefix each becomes. */
    public const array GROUPS = [
        'color' => 'color',
        'font' => 'font',
        'text' => 'text',
        'leading' => 'leading',
        'tracking' => 'tracking',
        'weight' => 'font-weight',
        'space' => 'space',
        'radius' => 'radius',
        'shadow' => 'shadow',
        'container' => 'container',
        'breakpoint' => 'breakpoint',
        'duration' => 'duration',
        'ease' => 'ease',
    ];

    private function __construct(private array $data, private readonly ?string $file) {}

    public static function load(string $file, ?string $fallback = null): self
    {
        $source = is_file($file) ? $file : $fallback;

        if ($source === null || ! is_file($source)) {
            return new self(['tokens' => []], $file);
        }

        $data = json_decode((string) file_get_contents($source), true);

        if (! is_array($data)) {
            throw new InvalidArgumentException(__(':file is not valid JSON.', ['file' => $source]));
        }

        return new self($data, $file);
    }

    public static function fromArray(array $data): self
    {
        return new self($data, null);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function name(): string
    {
        return (string) ($this->data['name'] ?? 'Design');
    }

    /** Flat token list: ["color.primary" => ["value" => …, "dark" => …, "type" => …, "description" => …]]. */
    public function tokens(): array
    {
        $out = [];

        foreach ((array) ($this->data['tokens'] ?? []) as $group => $items) {
            if (! isset(self::GROUPS[$group]) || ! is_array($items)) {
                continue;
            }

            $type = $items['$type'] ?? null;

            foreach ($items as $name => $token) {
                if (str_starts_with((string) $name, '$')) {
                    continue;
                }

                $token = is_array($token) && array_key_exists('$value', $token) ? $token : ['$value' => $token];

                $out["{$group}.{$name}"] = [
                    'group' => $group,
                    'name' => (string) $name,
                    'value' => $token['$value'],
                    'dark' => $token['$extensions']['cms']['dark'] ?? null,
                    'type' => $token['$type'] ?? $type,
                    'description' => $token['$description'] ?? null,
                ];
            }
        }

        return $out;
    }

    public function token(string $path): mixed
    {
        return $this->tokens()[$path]['value'] ?? null;
    }

    /** Set one token value (DTCG shape preserved). */
    public function setToken(string $path, mixed $value, mixed $dark = null): void
    {
        [$group, $name] = explode('.', $path, 2) + [1 => ''];

        if (! isset(self::GROUPS[$group]) || ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
            throw new InvalidArgumentException(__('Invalid token path :path.', ['path' => $path]));
        }

        $current = $this->data['tokens'][$group][$name] ?? null;
        $token = is_array($current) && array_key_exists('$value', $current) ? $current : [];
        $token['$value'] = $value;

        if ($dark !== null) {
            $token['$extensions']['cms']['dark'] = $dark;
        }

        $this->data['tokens'][$group][$name] = $token;
    }

    /** Set or clear (null, '') a token's dark-mode value. */
    public function setDark(string $path, mixed $dark): void
    {
        [$group, $name] = explode('.', $path, 2) + [1 => ''];

        if (! isset($this->data['tokens'][$group][$name])) {
            if ($dark === null || $dark === '') {
                return;
            }

            throw new InvalidArgumentException(__('Unknown token :path.', ['path' => $path]));
        }

        if (! is_array($this->data['tokens'][$group][$name]) || ! array_key_exists('$value', $this->data['tokens'][$group][$name])) {
            $this->setToken($path, $this->token($path));
        }

        if ($dark === null || $dark === '') {
            unset($this->data['tokens'][$group][$name]['$extensions']['cms']['dark']);
            $this->data['tokens'][$group][$name] = $this->prune($this->data['tokens'][$group][$name]);

            return;
        }

        $this->data['tokens'][$group][$name]['$extensions']['cms']['dark'] = $dark;
    }

    public function removeToken(string $path): void
    {
        [$group, $name] = explode('.', $path, 2) + [1 => ''];
        unset($this->data['tokens'][$group][$name]);
    }

    /**
     * Rename a token within its group, keeping its place, and point every
     * reference in the design system (token:old → token:new) at the new name.
     */
    public function renameToken(string $from, string $to): void
    {
        [$group, $old] = explode('.', $from, 2) + [1 => ''];
        [$toGroup, $new] = explode('.', $to, 2) + [1 => ''];

        if ($group !== $toGroup || ! isset($this->data['tokens'][$group][$old]) || ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $new)) {
            throw new InvalidArgumentException(__('Cannot rename :from to :to.', ['from' => $from, 'to' => $to]));
        }

        if ($old === $new) {
            return;
        }

        if (isset($this->data['tokens'][$group][$new])) {
            throw new InvalidArgumentException(__(':path already exists.', ['path' => $to]));
        }

        $items = [];

        foreach ($this->data['tokens'][$group] as $name => $token) {
            $items[$name === $old ? $new : $name] = $token;
        }

        $this->data['tokens'][$group] = $items;

        $json = (string) json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = (string) preg_replace('/token:'.preg_quote($from, '/').'(?![a-z0-9-])/', 'token:'.$to, $json);
        $this->data = json_decode($json, true);
    }

    /**
     * Apply a change set (the design/update input): removals first, then
     * renames, token values, dark values (null clears) and whole sections.
     */
    public function apply(array $in): void
    {
        foreach ((array) ($in['remove'] ?? []) as $path) {
            $this->removeToken((string) $path);
        }

        foreach ((array) ($in['rename'] ?? []) as $from => $to) {
            $this->renameToken((string) $from, (string) $to);
        }

        foreach ((array) ($in['tokens'] ?? []) as $path => $value) {
            $this->setToken((string) $path, $value);
        }

        foreach ((array) ($in['dark'] ?? []) as $path => $value) {
            $this->setDark((string) $path, $value);
        }

        foreach (['styles', 'prose', 'buttons', 'fonts', 'transition'] as $section) {
            if (isset($in[$section])) {
                $this->set($section, (array) $in[$section]);
            }
        }
    }

    /** Drop empty $extensions left behind after clearing a value. */
    private function prune(array $token): array
    {
        if (($token['$extensions']['cms'] ?? null) === []) {
            unset($token['$extensions']['cms']);
        }

        if (($token['$extensions'] ?? null) === []) {
            unset($token['$extensions']);
        }

        return $token;
    }

    /** @return list<array{family: string, src: string, weight?: string, style?: string, preload?: bool, display?: string}> */
    public function fonts(): array
    {
        return array_values(array_filter((array) ($this->data['fonts'] ?? []), 'is_array'));
    }

    public function setFonts(array $fonts): void
    {
        $this->data['fonts'] = array_values($fonts);
    }

    /** @return array<string, array<string, mixed>> element => declarations */
    public function styles(): array
    {
        return (array) ($this->data['styles'] ?? []);
    }

    public function setStyles(array $styles): void
    {
        $this->data['styles'] = $styles;
    }

    public function prose(): array
    {
        return (array) ($this->data['prose'] ?? []);
    }

    public function buttons(): array
    {
        return (array) ($this->data['buttons'] ?? []);
    }

    /** @return array{type: string, duration: float} */
    public function transition(): array
    {
        $t = (array) ($this->data['transition'] ?? []);

        return [
            'type' => in_array($t['type'] ?? 'none', ['none', 'fade', 'slide', 'scale'], true) ? ($t['type'] ?? 'none') : 'none',
            'duration' => max(0.05, min(2, (float) ($t['duration'] ?? 0.3))),
        ];
    }

    /** Replace a top-level section (styles, prose, buttons, transition, fonts). */
    public function set(string $section, array $value): void
    {
        if (! in_array($section, ['styles', 'prose', 'buttons', 'transition', 'fonts'], true)) {
            throw new InvalidArgumentException(__('Unknown design section :section.', ['section' => $section]));
        }

        $this->data[$section] = $value;
    }

    /** @return array<string, string> e.g. ["sm" => "640px", …] in ascending order */
    public function breakpoints(): array
    {
        $out = [];

        foreach ($this->tokens() as $token) {
            if ($token['group'] === 'breakpoint' && is_string($token['value'])) {
                $out[$token['name']] = $token['value'];
            }
        }

        $out = $out ?: ['sm' => '640px', 'md' => '768px', 'lg' => '1024px', 'xl' => '1280px'];
        uasort($out, static fn ($a, $b) => (float) $a <=> (float) $b);

        return $out;
    }

    /** Is there a dark value for any token (the site supports dark mode)? */
    public function hasDark(): bool
    {
        foreach ($this->tokens() as $token) {
            if ($token['dark'] !== null) {
                return true;
            }
        }

        return false;
    }

    public function save(): void
    {
        if ($this->file === null) {
            throw new InvalidArgumentException(__('This design has no file.'));
        }

        Files::put($this->file, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }
}
