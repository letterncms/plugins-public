<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Cms\Extend\Hooks;

/**
 * Compiles a node's style map into atomic classes.
 *
 *   style: {
 *     base:          { display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: "token:space.8" },
 *     tablet:        { gridTemplateColumns: "1fr 1fr" },        tablet and smaller (desktop-first)
 *     mobile:        { gridTemplateColumns: "1fr" },            phones
 *     md:            { … },                                     768px and up (mobile-first)
 *     hover:         { transform: "translateY(-2px)" },          states…
 *     "mobile:hover":{ … },                                     …per breakpoint
 *     "is-open":     { opacity: 1 },                            custom state set by scripts or data
 *     "in-open":     { rotate: "45deg" },                       an ancestor is in that state
 *     before:        { content: "''", position: "absolute" },
 *   }
 *
 * Keys are CSS properties in camelCase (any of PROPERTIES). Each declaration
 * becomes one short content-hashed class, so identical styles share CSS. Rules
 * are handed to the renderer through the `render.style` hook.
 */
final class StyleCompiler
{
    /** camelCase key => [css property, inspector group]. */
    public const array PROPERTIES = [
        // Layout
        'display' => ['display', 'layout'], 'flexDirection' => ['flex-direction', 'layout'], 'flexWrap' => ['flex-wrap', 'layout'],
        'justifyContent' => ['justify-content', 'layout'], 'alignItems' => ['align-items', 'layout'], 'alignContent' => ['align-content', 'layout'],
        'alignSelf' => ['align-self', 'layout'], 'justifySelf' => ['justify-self', 'layout'], 'justifyItems' => ['justify-items', 'layout'],
        'placeItems' => ['place-items', 'layout'], 'placeContent' => ['place-content', 'layout'], 'placeSelf' => ['place-self', 'layout'],
        'gap' => ['gap', 'layout'], 'rowGap' => ['row-gap', 'layout'], 'columnGap' => ['column-gap', 'layout'],
        'gridTemplateColumns' => ['grid-template-columns', 'layout'], 'gridTemplateRows' => ['grid-template-rows', 'layout'],
        'gridTemplateAreas' => ['grid-template-areas', 'layout'], 'gridAutoFlow' => ['grid-auto-flow', 'layout'],
        'gridAutoColumns' => ['grid-auto-columns', 'layout'], 'gridAutoRows' => ['grid-auto-rows', 'layout'],
        'gridColumn' => ['grid-column', 'layout'], 'gridRow' => ['grid-row', 'layout'], 'gridArea' => ['grid-area', 'layout'],
        'order' => ['order', 'layout'], 'flex' => ['flex', 'layout'], 'flexGrow' => ['flex-grow', 'layout'], 'flexShrink' => ['flex-shrink', 'layout'],
        'flexBasis' => ['flex-basis', 'layout'], 'boxSizing' => ['box-sizing', 'layout'], 'isolation' => ['isolation', 'layout'],
        'float' => ['float', 'layout'], 'clear' => ['clear', 'layout'], 'columns' => ['columns', 'layout'], 'columnRule' => ['column-rule', 'layout'],
        'containerType' => ['container-type', 'layout'],
        // Spacing
        'padding' => ['padding', 'spacing'], 'paddingTop' => ['padding-top', 'spacing'], 'paddingRight' => ['padding-right', 'spacing'],
        'paddingBottom' => ['padding-bottom', 'spacing'], 'paddingLeft' => ['padding-left', 'spacing'],
        'paddingInline' => ['padding-inline', 'spacing'], 'paddingBlock' => ['padding-block', 'spacing'],
        'margin' => ['margin', 'spacing'], 'marginTop' => ['margin-top', 'spacing'], 'marginRight' => ['margin-right', 'spacing'],
        'marginBottom' => ['margin-bottom', 'spacing'], 'marginLeft' => ['margin-left', 'spacing'],
        'marginInline' => ['margin-inline', 'spacing'], 'marginBlock' => ['margin-block', 'spacing'],
        // Size
        'width' => ['width', 'size'], 'height' => ['height', 'size'], 'minWidth' => ['min-width', 'size'], 'maxWidth' => ['max-width', 'size'],
        'minHeight' => ['min-height', 'size'], 'maxHeight' => ['max-height', 'size'], 'aspectRatio' => ['aspect-ratio', 'size'],
        'objectFit' => ['object-fit', 'size'], 'objectPosition' => ['object-position', 'size'],
        'overflow' => ['overflow', 'size'], 'overflowX' => ['overflow-x', 'size'], 'overflowY' => ['overflow-y', 'size'],
        'overscrollBehavior' => ['overscroll-behavior', 'size'], 'scrollSnapType' => ['scroll-snap-type', 'size'],
        'scrollSnapAlign' => ['scroll-snap-align', 'size'], 'scrollMarginTop' => ['scroll-margin-top', 'size'], 'scrollPadding' => ['scroll-padding', 'size'],
        'scrollbarWidth' => ['scrollbar-width', 'size'],
        // Position
        'position' => ['position', 'position'], 'top' => ['top', 'position'], 'right' => ['right', 'position'], 'bottom' => ['bottom', 'position'],
        'left' => ['left', 'position'], 'inset' => ['inset', 'position'], 'zIndex' => ['z-index', 'position'],
        // Typography
        'fontFamily' => ['font-family', 'typography'], 'fontSize' => ['font-size', 'typography'], 'fontWeight' => ['font-weight', 'typography'],
        'fontStyle' => ['font-style', 'typography'], 'lineHeight' => ['line-height', 'typography'], 'letterSpacing' => ['letter-spacing', 'typography'],
        'textAlign' => ['text-align', 'typography'], 'textTransform' => ['text-transform', 'typography'], 'textDecoration' => ['text-decoration', 'typography'],
        'textDecorationColor' => ['text-decoration-color', 'typography'], 'textDecorationThickness' => ['text-decoration-thickness', 'typography'],
        'textUnderlineOffset' => ['text-underline-offset', 'typography'], 'textWrap' => ['text-wrap', 'typography'], 'whiteSpace' => ['white-space', 'typography'],
        'wordBreak' => ['word-break', 'typography'], 'overflowWrap' => ['overflow-wrap', 'typography'], 'hyphens' => ['hyphens', 'typography'],
        'textIndent' => ['text-indent', 'typography'], 'textOverflow' => ['text-overflow', 'typography'], 'textShadow' => ['text-shadow', 'typography'],
        'fontFeatureSettings' => ['font-feature-settings', 'typography'], 'fontVariationSettings' => ['font-variation-settings', 'typography'],
        'fontVariantNumeric' => ['font-variant-numeric', 'typography'], 'listStyle' => ['list-style', 'typography'],
        'color' => ['color', 'typography'], 'textStroke' => ['-webkit-text-stroke', 'typography'], 'lineClamp' => ['-webkit-line-clamp', 'typography'],
        'verticalAlign' => ['vertical-align', 'typography'],
        // Fill
        'background' => ['background', 'fill'], 'backgroundColor' => ['background-color', 'fill'], 'backgroundImage' => ['background-image', 'fill'],
        'backgroundSize' => ['background-size', 'fill'], 'backgroundPosition' => ['background-position', 'fill'], 'backgroundRepeat' => ['background-repeat', 'fill'],
        'backgroundAttachment' => ['background-attachment', 'fill'], 'backgroundClip' => ['background-clip', 'fill'], 'backgroundBlendMode' => ['background-blend-mode', 'fill'],
        // Border
        'border' => ['border', 'border'], 'borderTop' => ['border-top', 'border'], 'borderRight' => ['border-right', 'border'],
        'borderBottom' => ['border-bottom', 'border'], 'borderLeft' => ['border-left', 'border'], 'borderWidth' => ['border-width', 'border'],
        'borderStyle' => ['border-style', 'border'], 'borderColor' => ['border-color', 'border'], 'borderRadius' => ['border-radius', 'border'],
        'borderTopLeftRadius' => ['border-top-left-radius', 'border'], 'borderTopRightRadius' => ['border-top-right-radius', 'border'],
        'borderBottomRightRadius' => ['border-bottom-right-radius', 'border'], 'borderBottomLeftRadius' => ['border-bottom-left-radius', 'border'],
        'outline' => ['outline', 'border'], 'outlineOffset' => ['outline-offset', 'border'],
        // Effects
        'opacity' => ['opacity', 'effects'], 'boxShadow' => ['box-shadow', 'effects'], 'filter' => ['filter', 'effects'],
        'backdropFilter' => ['backdrop-filter', 'effects'], 'mixBlendMode' => ['mix-blend-mode', 'effects'], 'cursor' => ['cursor', 'effects'],
        'pointerEvents' => ['pointer-events', 'effects'], 'userSelect' => ['user-select', 'effects'], 'visibility' => ['visibility', 'effects'],
        'clipPath' => ['clip-path', 'effects'], 'maskImage' => ['mask-image', 'effects'], 'accentColor' => ['accent-color', 'effects'],
        'fill' => ['fill', 'effects'], 'stroke' => ['stroke', 'effects'], 'strokeWidth' => ['stroke-width', 'effects'], 'content' => ['content', 'effects'],
        // Transform
        'transform' => ['transform', 'transform'], 'transformOrigin' => ['transform-origin', 'transform'], 'translate' => ['translate', 'transform'],
        'rotate' => ['rotate', 'transform'], 'scale' => ['scale', 'transform'], 'perspective' => ['perspective', 'transform'],
        'perspectiveOrigin' => ['perspective-origin', 'transform'], 'transformStyle' => ['transform-style', 'transform'], 'backfaceVisibility' => ['backface-visibility', 'transform'],
        // Transition
        'transition' => ['transition', 'transition'], 'transitionProperty' => ['transition-property', 'transition'], 'transitionDuration' => ['transition-duration', 'transition'],
        'transitionTimingFunction' => ['transition-timing-function', 'transition'], 'transitionDelay' => ['transition-delay', 'transition'], 'willChange' => ['will-change', 'transition'],
    ];

    /** Older and shorthand keys people (and agents) reach for. */
    public const array ALIASES = [
        'direction' => 'flexDirection', 'wrap' => 'flexWrap', 'justify' => 'justifyContent', 'align' => 'alignItems',
        'paddingX' => 'paddingInline', 'paddingY' => 'paddingBlock', 'marginX' => 'marginInline', 'marginY' => 'marginBlock',
        'radius' => 'borderRadius', 'shadow' => 'boxShadow', 'colSpan' => 'gridColumn', 'bg' => 'backgroundColor',
    ];

    /** Values that are plain numbers (no px added). */
    private const array UNITLESS = ['opacity', 'zIndex', 'order', 'flex', 'flexGrow', 'flexShrink', 'fontWeight', 'lineHeight', 'lineClamp', 'scale', 'columns', 'aspectRatio'];

    /** Desktop-first variants: max-width just below a breakpoint. */
    public const array DOWN = ['tablet' => 'lg', 'mobile' => 'md'];

    /** state => selector template ("&" is the class). */
    public const array STATES = [
        'hover' => '&:hover', 'focus' => '&:focus', 'focus-visible' => '&:focus-visible', 'focus-within' => '&:focus-within',
        'active' => '&:active', 'disabled' => '&:disabled', 'checked' => '&:checked', 'open' => '&[open]',
        'placeholder' => '&::placeholder', 'before' => '&::before', 'after' => '&::after', 'selection' => '& ::selection', 'marker' => '&::marker',
        'first' => '&:first-child', 'last' => '&:last-child', 'odd' => '&:nth-child(odd)', 'even' => '&:nth-child(even)', 'empty' => '&:empty',
        'group-hover' => '[data-cms-group]:hover &', 'group-focus' => '[data-cms-group]:focus-within &',
        'current' => '&[aria-current]', 'dark' => '[data-theme=dark] &',
    ];

    /** @var array<string, string> class => CSS (emitted this request) */
    private array $emitted = [];

    public function __construct(private readonly Hooks $hooks) {}

    /** @return array<string, string> breakpoint => min-width, ascending */
    public function breakpoints(): array
    {
        return (array) $this->hooks->filter('blocks.breakpoints', ['sm' => '640px', 'md' => '768px', 'lg' => '1024px', 'xl' => '1280px']);
    }

    /** Is this a known variant key ("base", "tablet", "hover", "mobile:hover", "is-open"…)? */
    public function validVariant(string $variant): bool
    {
        return $this->parse($variant) !== null;
    }

    /** Clean a style map: known keys (aliases resolved), valid variants, safe values. */
    public function sanitize(mixed $style): array
    {
        if (! is_array($style)) {
            return [];
        }

        $out = [];

        foreach ($style as $variant => $declarations) {
            if (! is_string($variant) || ! is_array($declarations) || ! $this->validVariant($variant)) {
                continue;
            }

            foreach ($declarations as $key => $value) {
                $key = self::ALIASES[$key] ?? $key;

                if (isset(self::PROPERTIES[$key]) && (is_string($value) || is_int($value) || is_float($value)) && $this->value((string) $key, $value) !== null) {
                    $out[$variant][$key] = is_string($value) ? trim($value) : $value;
                }
            }
        }

        return $out;
    }

    /** Classes for a style map; CSS rules are emitted once per class per request. */
    public function classes(array $style): string
    {
        $classes = [];

        foreach ($style as $variant => $declarations) {
            $parsed = is_string($variant) ? $this->parse($variant) : null;

            if ($parsed === null) {
                continue;
            }

            foreach ((array) $declarations as $key => $value) {
                $key = self::ALIASES[$key] ?? $key;
                $property = self::PROPERTIES[$key][0] ?? null;
                $css = $property ? $this->value((string) $key, $value) : null;

                if ($css === null) {
                    continue;
                }

                $class = 's'.substr(base_convert(hash('crc32b', "{$variant}|{$property}|{$css}"), 16, 36), 0, 6);
                $classes[] = $class;

                if (! isset($this->emitted[$class])) {
                    $rule = $this->rule($class, $parsed, $this->declaration((string) $key, $property, $css));
                    $this->emitted[$class] = $rule;
                    $this->hooks->dispatch('render.style', $class, $rule, $this->order($parsed));
                }

                // Pseudo-elements need content to exist.
                if (in_array($parsed['state'], ['before', 'after'], true)) {
                    $base = 'p'.substr(base_convert(hash('crc32b', "{$variant}|content"), 16, 36), 0, 6);

                    if (! isset($this->emitted[$base])) {
                        $this->emitted[$base] = $this->rule($base, $parsed, 'content:""');
                        $this->hooks->dispatch('render.style', $base, $this->emitted[$base], $this->order($parsed) - 1);
                    }

                    $classes[] = $base;
                }
            }
        }

        return implode(' ', array_unique($classes));
    }

    /** All rules emitted so far (for renderers that don't listen to the hook). */
    public function css(): string
    {
        return implode('', $this->emitted);
    }

    public function reset(): void
    {
        $this->emitted = [];
    }

    /** The inspector group of a property (catalog for editors and agents). */
    public static function group(string $key): ?string
    {
        return self::PROPERTIES[self::ALIASES[$key] ?? $key][1] ?? null;
    }

    /** CSS for one value of a property, or null when it is not allowed. */
    public function value(string $key, mixed $value): ?string
    {
        $key = self::ALIASES[$key] ?? $key;

        if ($key === 'gridTemplateColumns' && (is_int($value) || ctype_digit((string) $value))) {
            $n = max(1, min(24, (int) $value));

            return "repeat({$n},minmax(0,1fr))";
        }

        if ($key === 'gridColumn' && (is_int($value) || ctype_digit((string) $value))) {
            $n = max(1, min(24, (int) $value));

            return "span {$n}/span {$n}";
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value) && $value !== '0')) {
            return in_array($key, self::UNITLESS, true) ? (string) $value : (string) $value.'px';
        }

        if ($key === 'content' && is_string($value) && ! preg_match('/^(none|normal|"[^"]*"|\'[^\']*\'|attr\([a-z-]+\)|counter\([a-z-]+\))$/i', trim($value))) {
            return null;
        }

        return Css::value($value);
    }

    /** @return array{bp: ?string, state: ?string}|null */
    private function parse(string $variant): ?array
    {
        if ($variant === 'base') {
            return ['bp' => null, 'state' => null];
        }

        $parts = explode(':', $variant);

        if (count($parts) > 2) {
            return null;
        }

        $bp = null;
        $state = null;
        $breakpoints = $this->breakpoints();

        foreach ($parts as $i => $part) {
            if ($i === 0 && (isset(self::DOWN[$part]) || isset($breakpoints[$part]))) {
                $bp = $part;
            } elseif ($state === null && (isset(self::STATES[$part]) || preg_match('/^(is|in)-[a-z][a-z0-9-]{0,30}$/', $part))) {
                $state = $part;
            } else {
                return null;
            }
        }

        return $bp === null && $state === null ? null : ['bp' => $bp, 'state' => $state];
    }

    private function declaration(string $key, string $property, string $css): string
    {
        return match ($key) {
            'lineClamp' => "display:-webkit-box;-webkit-box-orient:vertical;overflow:hidden;-webkit-line-clamp:{$css}",
            'backdropFilter' => "-webkit-backdrop-filter:{$css};backdrop-filter:{$css}",
            'backgroundClip' => "-webkit-background-clip:{$css};background-clip:{$css}",
            'maskImage' => "-webkit-mask-image:{$css};mask-image:{$css}",
            default => "{$property}:{$css}",
        };
    }

    /** @param array{bp: ?string, state: ?string} $v */
    private function rule(string $class, array $v, string $declaration): string
    {
        $state = $v['state'];
        $template = match (true) {
            $state === null => '&',
            str_starts_with($state, 'is-') => '&.'.$state,
            str_starts_with($state, 'in-') => '.is-'.substr($state, 3).' &',
            default => self::STATES[$state],
        };

        $rule = str_replace('&', '.'.$class, $template).'{'.$declaration.'}';

        if ($state === 'dark') {
            $rule .= '@media (prefers-color-scheme:dark){:root:not([data-theme=light]) .'.$class.'{'.$declaration.'}}';
        }

        if (in_array($state, ['hover', 'group-hover'], true)) {
            $rule = '@media (hover:hover){'.$rule.'}';
        }

        $breakpoints = $this->breakpoints();

        if ($v['bp'] !== null && isset($breakpoints[$v['bp']])) {
            $rule = "@media (min-width:{$breakpoints[$v['bp']]}){{$rule}}";
        } elseif ($v['bp'] !== null && isset(self::DOWN[$v['bp']])) {
            $max = $this->below($breakpoints[self::DOWN[$v['bp']]] ?? (self::DOWN[$v['bp']] === 'lg' ? '1024px' : '768px'));
            $rule = "@media (max-width:{$max}){{$rule}}";
        }

        return $rule;
    }

    private function below(string $width): string
    {
        return preg_match('/^([\d.]+)(px|rem|em)$/', $width, $m) ? ((float) $m[1] - ($m[2] === 'px' ? 0.02 : 0.001)).$m[2] : $width;
    }

    /**
     * Later rules win: base, min-width breakpoints ascending, tablet, mobile;
     * within each, plain declarations before states before custom states.
     *
     * @param  array{bp: ?string, state: ?string}  $v
     */
    private function order(array $v): int
    {
        $bp = match (true) {
            $v['bp'] === null => 0,
            $v['bp'] === 'tablet' => 500,
            $v['bp'] === 'mobile' => 600,
            default => 100 + 10 * (int) array_search($v['bp'], array_keys($this->breakpoints()), true),
        };

        $state = match (true) {
            $v['state'] === null => 0,
            str_starts_with($v['state'], 'is-') || str_starts_with($v['state'], 'in-') => 6,
            default => 3,
        };

        return $bp + $state;
    }
}
