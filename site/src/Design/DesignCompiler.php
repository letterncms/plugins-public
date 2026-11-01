<?php

declare(strict_types=1);

namespace Cms\Site\Design;

use Cms\Blocks\Css;
use Cms\Blocks\StyleCompiler;

/**
 * Turns the design system into one stylesheet: a small reset, @font-face rules,
 * tokens as custom properties (+ dark mode), element defaults, prose styles,
 * button variants and page transitions. custom.css is appended by Design.
 */
final class DesignCompiler
{
    /** Short element-style keys from earlier versions (and handy for agents). */
    private const array SHORT = [
        'font' => 'font-family', 'size' => 'font-size', 'weight' => 'font-weight', 'leading' => 'line-height',
        'tracking' => 'letter-spacing', 'transform' => 'text-transform', 'decoration' => 'text-decoration',
        'underlineOffset' => 'text-underline-offset', 'wrap' => 'text-wrap', 'style' => 'font-style', 'features' => 'font-feature-settings',
        'background' => 'background-color', 'radius' => 'border-radius',
    ];

    public const array ELEMENTS = [
        'html', 'body', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'a', 'a:hover', 'small', 'strong', 'em', 'mark', 'code', 'kbd', 'pre', 'blockquote',
        'hr', 'ul', 'ol', 'li', 'img', 'figcaption', 'table', 'th', 'td', 'button', 'input', 'textarea', 'select', 'label', '::selection', ':focus-visible',
        'h1 em', 'h2 em', 'h3 em', 'h1 strong', 'h2 strong',
    ];

    /** @param callable(string): ?string $fontUrl resolves a font src to a public URL */
    public function css(DesignJson $design, callable $fontUrl): string
    {
        $out = ['@layer theme, base, elements, components, utilities;'];
        $out[] = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/reset.css');

        foreach ($design->fonts() as $font) {
            $src = isset($font['src']) ? $fontUrl((string) $font['src']) : null;

            if ($src === null || ! isset($font['family']) || ! preg_match('/^[\w\s-]{1,60}$/u', (string) $font['family'])) {
                continue;
            }

            $out[] = sprintf(
                '@font-face{font-family:"%s";src:url("%s") format("woff2");font-weight:%s;font-style:%s;font-display:%s}',
                $font['family'], str_replace('"', '', $src),
                preg_match('/^\d{3}( \d{3})?$/', (string) ($font['weight'] ?? '400')) ? $font['weight'] ?? '400' : '400',
                ($font['style'] ?? 'normal') === 'italic' ? 'italic' : 'normal',
                // A face the page doesn't preload can arrive late: it must not move text that's already there.
                in_array($font['display'] ?? null, ['swap', 'optional', 'fallback', 'block'], true) ? $font['display'] : (empty($font['preload']) ? 'optional' : 'swap'),
            );
        }

        $light = [];
        $dark = [];

        foreach ($design->tokens() as $token) {
            $value = $this->value($token);

            if ($value === null) {
                continue;
            }

            $var = '--'.DesignJson::GROUPS[$token['group']].'-'.$token['name'];
            $light[] = "{$var}:{$value}";

            if ($token['dark'] !== null && ($darkValue = $this->value([...$token, 'value' => $token['dark']])) !== null) {
                $dark[] = "{$var}:{$darkValue}";
            }
        }

        $out[] = ':root{'.implode(';', $light).'}';

        if ($dark !== []) {
            $block = implode(';', $dark);
            $out[] = "@media (prefers-color-scheme:dark){:root:not([data-theme=light]){{$block};color-scheme:dark}}";
            $out[] = "[data-theme=dark]{{$block};color-scheme:dark}";
        }

        $elements = [];

        foreach ($design->styles() as $selector => $declarations) {
            if (in_array($selector, self::ELEMENTS, true) && is_array($declarations) && ($css = $this->declarations($declarations)) !== '') {
                $elements[] = "{$selector}{{$css}}";
            }
        }

        foreach ($design->prose() as $selector => $declarations) {
            if (in_array($selector, self::ELEMENTS, true) && is_array($declarations) && ($css = $this->declarations($declarations)) !== '') {
                $elements[] = ".cms-prose {$selector}{{$css}}";
            }
        }

        if ($elements !== []) {
            $out[] = '@layer elements{'.implode('', $elements).'}';
        }

        $buttons = [];

        foreach ($design->buttons() as $variant => $states) {
            if (! is_string($variant) || ! preg_match('/^[a-z][a-z0-9-]{0,30}$/', $variant) || ! is_array($states)) {
                continue;
            }

            $states = isset($states['base']) || isset($states['hover']) ? $states : ['base' => $states];

            foreach (['base' => '', 'hover' => ':hover', 'active' => ':active', 'focus-visible' => ':focus-visible'] as $state => $pseudo) {
                if (is_array($states[$state] ?? null) && ($css = $this->declarations($states[$state])) !== '') {
                    $rule = ".cms-button.cms-button[data-variant=\"{$variant}\"]{$pseudo}{{$css}}";
                    $buttons[] = $state === 'hover' ? "@media (hover:hover){{$rule}}" : $rule;
                }
            }
        }

        if ($buttons !== []) {
            $out[] = '@layer components{'.implode('', $buttons).'}';
        }

        $transition = $design->transition();

        if ($transition['type'] !== 'none') {
            $d = $transition['duration'].'s';
            $out[] = '@view-transition{navigation:auto}';
            $out[] = match ($transition['type']) {
                'slide' => "@keyframes cms-vt-out{to{opacity:0;transform:translateY(-12px)}}@keyframes cms-vt-in{from{opacity:0;transform:translateY(16px)}}::view-transition-old(root){animation:cms-vt-out {$d} cubic-bezier(.4,0,.2,1) both}::view-transition-new(root){animation:cms-vt-in {$d} cubic-bezier(.2,.8,.2,1) both}",
                'scale' => "@keyframes cms-vt-out{to{opacity:0;transform:scale(.98)}}@keyframes cms-vt-in{from{opacity:0;transform:scale(1.02)}}::view-transition-old(root){animation:cms-vt-out {$d} ease both}::view-transition-new(root){animation:cms-vt-in {$d} ease both}",
                default => "::view-transition-old(root),::view-transition-new(root){animation-duration:{$d}}",
            };
            $out[] = '@media (prefers-reduced-motion:reduce){::view-transition-group(*),::view-transition-old(*),::view-transition-new(*){animation:none!important}}';
        }

        return implode("\n", $out)."\n";
    }

    /** camelCase (or short) keys → declarations. */
    public function declarations(array $declarations): string
    {
        $css = [];

        foreach ($declarations as $key => $value) {
            $key = (string) $key;
            $property = self::SHORT[$key] ?? StyleCompiler::PROPERTIES[StyleCompiler::ALIASES[$key] ?? $key][0] ?? null;
            $resolved = is_array($value) ? null : Css::value(is_numeric($value) && ! in_array($key, ['weight', 'fontWeight', 'leading', 'lineHeight', 'opacity', 'zIndex'], true) && (string) $value !== '0' ? $value.'px' : $value);

            if ($property && $resolved !== null) {
                $css[] = "{$property}:{$resolved}";
            }
        }

        return implode(';', $css);
    }

    private function value(array $token): ?string
    {
        $value = $token['value'];

        if (is_array($value) && isset($value['min'], $value['max'])) {
            return $this->fluid((string) $value['min'], (string) $value['max']);
        }

        if ($token['group'] === 'font' && is_array($value)) {
            $families = array_map(static fn ($f) => preg_match('/\s/', (string) $f) && ! str_contains((string) $f, '"') ? "\"{$f}\"" : (string) $f, $value);
            $value = implode(', ', $families);
        }

        if (is_array($value)) {
            return null;
        }

        return Css::value((string) $value);
    }

    /** clamp() between 360px and 1280px viewports. */
    public function fluid(string $min, string $max): ?string
    {
        $toRem = static fn (string $v): ?float => match (true) {
            str_ends_with($v, 'rem') => (float) $v,
            str_ends_with($v, 'px') => (float) $v / 16,
            is_numeric($v) => (float) $v / 16,
            default => null,
        };

        $a = $toRem($min);
        $b = $toRem($max);

        if ($a === null || $b === null) {
            return null;
        }

        $from = 22.5;
        $to = 80;
        $slope = ($b - $a) / ($to - $from);
        $intercept = $a - $slope * $from;

        return sprintf('clamp(%srem, %srem + %svw, %srem)', $this->n(min($a, $b)), $this->n($intercept), $this->n($slope * 100), $this->n(max($a, $b)));
    }

    private function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }
}
