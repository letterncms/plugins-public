<?php

declare(strict_types=1);

namespace Cms\Blocks;

/**
 * Animations as data, compiled here to what the browser runs by itself.
 *
 *   motion: [{
 *     id: "m1", name: "Fade Up",
 *     trigger: { type: "appear", start: "top 85%", once: true, node: "#b1" },   appear · scroll · hover · press · load · loop · event
 *     on: ["desktop", "tablet"],                                                 absent = every screen size
 *     steps: [{
 *       target: "self",                                                          self · children · words · #<node id>
 *       mask: false,                                                             words: clip each word, so it rises from behind a line
 *       from: { opacity: 0, y: 32 }, to: {},                                     empty side = the element's own styles
 *       duration: 0.8, delay: 0, ease: "expo.out", stagger: 0.05, at: null,
 *     }],
 *     repeat: 0, yoyo: false,                                                    repeat -1 = forever
 *     hold: 2,                                                                   with appear and repeat -1: the steps play as one timeline that loops, resting this long
 *   }]
 *
 * load, scroll and loop become CSS: they need no script, so they can't hold back the
 * first paint, and without CSS the content is simply there. appear, hover, press and
 * event become a Web Animations description for the small `motion` runtime chunk, which
 * shows everything as designed if it never runs. Scroll start and end read "<element
 * edge> <viewport position>": "top 80%", "center center", "bottom top".
 */
final class Motion
{
    public const array TRIGGERS = ['appear', 'scroll', 'hover', 'press', 'load', 'loop', 'event'];

    public const array SCREENS = ['desktop', 'tablet', 'mobile'];

    /** Animatable properties: name => kind. */
    public const array PROPERTIES = [
        'opacity' => 'number', 'x' => 'length', 'y' => 'length', 'xPercent' => 'number', 'yPercent' => 'number', 'z' => 'length',
        'scale' => 'number', 'scaleX' => 'number', 'scaleY' => 'number', 'rotate' => 'number', 'rotateX' => 'number', 'rotateY' => 'number',
        'skewX' => 'number', 'skewY' => 'number', 'perspective' => 'length', 'blur' => 'number', 'brightness' => 'number', 'grayscale' => 'number',
        'clipPath' => 'css', 'backgroundColor' => 'css', 'color' => 'css', 'borderColor' => 'css', 'borderRadius' => 'css', 'boxShadow' => 'css',
        'letterSpacing' => 'css', 'transformOrigin' => 'css',
    ];

    /** Ease names => CSS timing functions. `linear()` draws what a bezier can't. */
    public const array EASES = [
        'none' => 'linear', 'linear' => 'linear',
        'power1.in' => 'cubic-bezier(.11,0,.5,0)', 'power1.out' => 'cubic-bezier(.5,1,.89,1)', 'power1.inOut' => 'cubic-bezier(.45,0,.55,1)',
        'power2.in' => 'cubic-bezier(.32,0,.67,0)', 'power2.out' => 'cubic-bezier(.33,1,.68,1)', 'power2.inOut' => 'cubic-bezier(.65,0,.35,1)',
        'power3.in' => 'cubic-bezier(.5,0,.75,0)', 'power3.out' => 'cubic-bezier(.25,1,.5,1)', 'power3.inOut' => 'cubic-bezier(.76,0,.24,1)',
        'power4.in' => 'cubic-bezier(.64,0,.78,0)', 'power4.out' => 'cubic-bezier(.22,1,.36,1)', 'power4.inOut' => 'cubic-bezier(.83,0,.17,1)',
        'sine.in' => 'cubic-bezier(.12,0,.39,0)', 'sine.out' => 'cubic-bezier(.61,1,.88,1)', 'sine.inOut' => 'cubic-bezier(.37,0,.63,1)',
        'expo.in' => 'cubic-bezier(.7,0,.84,0)', 'expo.out' => 'cubic-bezier(.16,1,.3,1)', 'expo.inOut' => 'cubic-bezier(.87,0,.13,1)',
        'circ.in' => 'cubic-bezier(.55,0,1,.45)', 'circ.out' => 'cubic-bezier(0,.55,.45,1)', 'circ.inOut' => 'cubic-bezier(.85,0,.15,1)',
        'back.in' => 'cubic-bezier(.36,0,.66,-.56)', 'back.out' => 'cubic-bezier(.34,1.56,.64,1)', 'back.inOut' => 'cubic-bezier(.68,-.6,.32,1.6)',
        'elastic.out' => 'linear(0,.361,.832,1.2,1.364,1.336,1.193,1.029,.912,.868,.889,.944,1,1.036,1.047,1.036,1.016,.997,.986,.984,.989,.996,1.002,1.005,1.006,1.004,1.001,.999,.998,.998,.999,1,1)',
        'bounce.out' => 'linear(0,.007,.03,.066,.118,.185,.266,.362,.473,.598,.739,.894,.97,.897,.838,.794,.766,.752,.752,.768,.798,.843,.903,.977,.973,.948,.938,.942,.962,.996,.987,.986,1)',
    ];

    /** A page load animation may hold nothing back for long: it decides when the page looks done. */
    private const float LOAD_DELAY = 0.2;

    private const float LOAD_SPREAD = 0.3;

    /** Loops that start by themselves stop by here, unless someone can pause them (WCAG 2.2.2). */
    private const float LOOP_LIMIT = 5.0;

    /** Enough for a short demo told as one timeline. */
    private const int STEPS = 80;

    /** Property => CSS name, for what isn't composed into transform or filter. */
    private const array CSS = [
        'opacity' => 'opacity', 'clipPath' => 'clip-path', 'backgroundColor' => 'background-color', 'color' => 'color', 'borderColor' => 'border-color',
        'borderRadius' => 'border-radius', 'boxShadow' => 'box-shadow', 'letterSpacing' => 'letter-spacing', 'transformOrigin' => 'transform-origin',
        'transform' => 'transform', 'filter' => 'filter',
    ];

    private const string ID = '/^[a-z0-9_-]{1,24}$/i';

    private const string NODE = '/^#[a-z0-9_-]{1,40}$/i';

    public static function sanitize(mixed $motion): array
    {
        if (! is_array($motion) || ! array_is_list($motion)) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach (array_slice($motion, 0, 12) as $i => $animation) {
            if (! is_array($animation)) {
                continue;
            }

            $id = is_string($animation['id'] ?? null) && preg_match(self::ID, $animation['id']) && ! isset($seen[$animation['id']]) ? $animation['id'] : 'm'.($i + 1);
            $seen[$id] = true;
            $trigger = (array) ($animation['trigger'] ?? []);
            $type = in_array($trigger['type'] ?? null, self::TRIGGERS, true) ? $trigger['type'] : 'appear';

            $clean = ['id' => $id];
            is_string($animation['name'] ?? null) && $animation['name'] !== '' && $clean['name'] = mb_substr(strip_tags($animation['name']), 0, 60);
            $clean['trigger'] = array_filter([
                'type' => $type,
                'start' => self::position($trigger['start'] ?? null),
                'end' => self::position($trigger['end'] ?? null),
                'once' => isset($trigger['once']) ? (bool) $trigger['once'] : null,
                'node' => is_string($trigger['node'] ?? null) && preg_match(self::NODE, $trigger['node']) ? $trigger['node'] : null,
            ], static fn ($v) => $v !== null);

            $screens = array_values(array_intersect(self::SCREENS, array_filter((array) ($animation['on'] ?? []), 'is_string')));
            $screens !== [] && count($screens) < 3 && $clean['on'] = $screens;

            $steps = [];

            foreach (array_slice((array) ($animation['steps'] ?? []), 0, self::STEPS) as $step) {
                if (! is_array($step)) {
                    continue;
                }

                $target = $step['target'] ?? 'self';
                // Lines can't be known before layout, and letters cost too much: they read as masked words and words.
                $mask = ! empty($step['mask']) || $target === 'lines';
                $target = in_array($target, ['lines', 'chars'], true) ? 'words' : $target;
                $target = is_string($target) && (in_array($target, ['self', 'children', 'words'], true) || preg_match(self::NODE, $target)) ? $target : 'self';

                $steps[] = array_filter([
                    'target' => $target,
                    'mask' => $mask && $target === 'words' ? true : null,
                    'from' => self::props($step['from'] ?? []) ?: null,
                    'to' => self::props($step['to'] ?? []) ?: null,
                    'duration' => self::number($step['duration'] ?? 0.6, 0, 30),
                    'delay' => isset($step['delay']) ? self::number($step['delay'], 0, 30) : null,
                    'ease' => self::ease($step['ease'] ?? null),
                    'stagger' => isset($step['stagger']) ? self::number($step['stagger'], 0, 5) : null,
                    'at' => isset($step['at']) && is_numeric($step['at']) ? self::number($step['at'], 0, 120) : null,
                ], static fn ($v) => $v !== null);
            }

            if ($steps === []) {
                continue;
            }

            $clean['steps'] = $steps;

            if (isset($animation['repeat'])) {
                $clean['repeat'] = max(-1, min(100, (int) $animation['repeat']));
            }

            if (! empty($animation['yoyo'])) {
                $clean['yoyo'] = true;
            }

            if (isset($animation['hold']) && is_numeric($animation['hold'])) {
                $clean['hold'] = self::number($animation['hold'], 0, 60);
            }

            $out[] = $clean;
        }

        return $out;
    }

    /** Node ids the animations point at (other than their own element). */
    public static function references(array $motion): array
    {
        $ids = [];

        foreach ($motion as $animation) {
            isset($animation['trigger']['node']) && $ids[] = substr($animation['trigger']['node'], 1);

            foreach ($animation['steps'] as $step) {
                str_starts_with($step['target'], '#') && $ids[] = substr($step['target'], 1);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * What a node's animations come to.
     *
     * @param  array<string, string>  $breakpoints  name => min-width (see StyleCompiler)
     * @return array{classes: list<string>, rules: array<string, string>, runtime: list<array>, split: ?array{mask: bool}, timelines: list<string>, forever: bool}
     */
    public static function compile(array $motion, array $breakpoints = []): array
    {
        $out = ['classes' => [], 'rules' => [], 'runtime' => [], 'split' => null, 'timelines' => [], 'forever' => false];

        foreach ($motion as $animation) {
            foreach ($animation['steps'] as $step) {
                if ($step['target'] === 'words') {
                    $out['split'] = ['mask' => ($out['split']['mask'] ?? false) || ! empty($step['mask'])];
                }
            }

            $type = $animation['trigger']['type'];

            match ($type) {
                'load', 'loop' => self::keyframed($animation, $breakpoints, $out),
                'scroll' => self::scrolled($animation, $breakpoints, $out),
                default => $out['runtime'][] = self::cycles($animation) ? self::cycle($animation, $breakpoints) : self::runtime($animation, $breakpoints),
            };

            if ($type !== 'loop' && ($animation['repeat'] ?? 0) === -1) {
                $out['forever'] = true;
            }
        }

        $out['classes'] = array_values(array_unique($out['classes']));
        $out['timelines'] = array_values(array_unique($out['timelines']));

        return $out;
    }

    /**
     * Start of each step on the animation's own clock, as the timeline in the inspector shows it.
     *
     * @return list<array{step: array, start: float, end: float}>
     */
    private static function timeline(array $animation, bool $load = false): array
    {
        $cursor = 0.0;
        $out = [];

        foreach ($animation['steps'] as $i => $step) {
            $delay = (float) ($step['delay'] ?? 0);
            $start = (float) ($step['at'] ?? $cursor) + $delay;
            $load && $i === 0 && $start = min($start, self::LOAD_DELAY);
            $duration = (float) ($step['duration'] ?? 0.6);
            $cursor = $start + $duration;
            $out[] = ['step' => $step, 'start' => $start, 'end' => $cursor];
        }

        return $out;
    }

    /** load and loop: @keyframes and a class, with no script. */
    private static function keyframed(array $animation, array $breakpoints, array &$out): void
    {
        $load = $animation['trigger']['type'] === 'load';
        $repeat = (int) ($animation['repeat'] ?? 0);
        $forever = $repeat === -1;
        $forever && $out['forever'] = true;
        $seen = [];

        foreach (self::timeline($animation, $load) as ['step' => $step, 'start' => $start]) {
            $frames = self::keyframes($step);

            if ($frames === null) {
                continue;
            }

            $duration = (float) ($step['duration'] ?? 0.6);
            $iterations = 1;

            if ($forever) {
                $iterations = 'infinite';
            } elseif ($repeat > 0) {
                $iterations = max(1, min($repeat + 1, $duration > 0 ? (int) floor(self::LOOP_LIMIT / $duration) : 1));
            }

            $name = 'cms-mk-'.self::hash($frames);
            $target = $step['target'];
            // A later step's `from` must not show before it starts.
            $fill = isset($seen[$target]) ? (isset($step['to']) ? 'forwards' : 'none') : (isset($step['to']) ? 'both' : 'backwards');
            $seen[$target] = true;
            $spread = (float) ($step['stagger'] ?? 0);
            $delay = self::seconds($start);

            if ($spread > 0 && ($target === 'words' || $target === 'children')) {
                $each = (int) round($spread * 1000);
                $delay = 'calc('.($load ? 'min(var(--i,0)*'.$each.'ms,'.((int) (self::LOAD_SPREAD * 1000)).'ms)' : 'var(--i,0)*'.$each.'ms').' + '.self::seconds($start).')';
            }

            $declaration = 'animation:'.$name.' '.self::seconds($duration).' '.self::timing($step).' '.$delay.' '.$iterations.(! empty($animation['yoyo']) ? ' alternate' : '').' '.$fill;
            $class = 'cms-m-'.self::hash($declaration.$target);
            $out['classes'][] = $class;
            $target === 'children' && $out['classes'][] = 'cms-mc';

            $selector = match (true) {
                $target === 'self' => ".{$class}",
                $target === 'children' => ".{$class}>*",
                $target === 'words' => ".{$class} .cms-w__i",
                default => '[data-cms="'.substr($target, 1).'"]',
            };

            $out['rules'][$class] = self::guarded($animation, $breakpoints, "@keyframes {$name}{{$frames}}{$selector}{{$declaration}}");
        }
    }

    /** scroll: follows the scroll with CSS. A browser without it shows the design, which is the right content. */
    private static function scrolled(array $animation, array $breakpoints, array &$out): void
    {
        $node = isset($animation['trigger']['node']) ? substr($animation['trigger']['node'], 1) : null;
        $timeline = $node !== null ? '--cms-t-'.$node : 'view()';
        $start = self::range($animation['trigger']['start'] ?? 'top bottom');
        $end = self::range($animation['trigger']['end'] ?? 'bottom top');
        $steps = self::timeline($animation);
        $total = max(0.001, (float) max(array_column($steps, 'end')));

        if ($node !== null) {
            $out['timelines'][] = $timeline;
        }

        foreach ($steps as ['step' => $step, 'start' => $from, 'end' => $to]) {
            $frames = self::keyframes($step);

            if ($frames === null) {
                continue;
            }

            $target = $step['target'];
            $name = 'cms-mk-'.self::hash($frames);
            $width = "(({$end}) - ({$start}))";
            $stagger = (float) ($step['stagger'] ?? 0);
            $staggered = $stagger > 0 && ($target === 'words' || $target === 'children');
            // Staggered items take their turn within the range; ~12 items spread over it.
            $share = $staggered ? 1 / max(1, 1 + 11 * $stagger / max(0.05, $to - $from)) : 1.0;
            $offset = $staggered ? '+ var(--i,0) * '.round((1 - $share) / 11, 4).' * '.$width : '';
            $begin = 'calc(('.$start.') + '.round($from / $total, 4).' * '.$width.' '.$offset.')';
            $finish = 'calc(('.$start.') + '.round(($from + ($to - $from) * $share) / $total, 4).' * '.$width.' '.$offset.')';
            $declaration = "animation:{$name} auto ".self::timing($step)." both;animation-timeline:{$timeline};animation-range:cover {$begin} cover {$finish}";
            $class = 'cms-m-'.self::hash($declaration.$target);
            $out['classes'][] = $class;
            $target === 'children' && $out['classes'][] = 'cms-mc';

            $selector = match (true) {
                $target === 'self' => ".{$class}",
                $target === 'children' => ".{$class}>*",
                $target === 'words' => ".{$class} .cms-w__i",
                default => '[data-cms="'.substr($target, 1).'"]',
            };

            $source = $node !== null ? '[data-cms="'.$node.'"]{view-timeline:'.$timeline.'}' : '';
            $out['rules'][$class] = self::guarded($animation, $breakpoints, "@supports (animation-timeline:view()){@keyframes {$name}{{$frames}}{$source}{$selector}{{$declaration}}}");
        }
    }

    /** A timeline that loops as a whole, rather than each step looping by itself. */
    private static function cycles(array $animation): bool
    {
        return $animation['trigger']['type'] === 'appear' && ($animation['repeat'] ?? 0) === -1 && isset($animation['hold']);
    }

    /**
     * One animation per target, spanning the whole timeline and repeating: a step's own start
     * and end become offsets, and between steps a target rests where the last one left it.
     * null stands for the element's own style, which the runtime reads, since CSS has no name for it.
     * Words and children have no single element to hold such a timeline, so they aren't part of one.
     */
    private static function cycle(array $animation, array $breakpoints): array
    {
        $steps = self::timeline($animation);
        $length = max(0.001, (float) max(array_column($steps, 'end')) + (float) $animation['hold']);
        $tracks = [];

        foreach ($steps as ['step' => $step, 'start' => $start, 'end' => $end]) {
            if (in_array($step['target'], ['words', 'children'], true)) {
                continue;
            }

            $from = self::css($step['from'] ?? []);
            $to = self::css($step['to'] ?? []);
            $at = round($start / $length, 5);
            $until = round($end / $length, 5);
            $timing = self::timing($step);

            foreach (array_keys($from + $to) as $property) {
                $track = &$tracks[$step['target']][$property];
                $before = $from[$property] ?? ($track !== null ? end($track)['value'] : null);
                $track ??= [['offset' => 0.0, 'value' => $before]];
                $track[] = ['offset' => $at, 'value' => $before, 'easing' => $timing];
                $track[] = ['offset' => $until, 'value' => $to[$property] ?? null];
                unset($track);
            }
        }

        $compiled = [];

        foreach ($tracks as $target => $properties) {
            $frames = [];

            foreach ($properties as $property => $track) {
                $track[] = ['offset' => 1.0, 'value' => end($track)['value']];

                foreach ($track as $point) {
                    $key = number_format($point['offset'], 5, '.', '');
                    $frames[$key] ??= ['offset' => $point['offset']];
                    $frames[$key][$property] = $point['value'];
                    isset($point['easing']) && $frames[$key]['easing'] = $point['easing'];
                }
            }

            ksort($frames, SORT_NUMERIC);
            $compiled[] = ['t' => $target, 'k' => array_values($frames), 'd' => (int) round($length * 1000), 'f' => 'both', 'i' => -1, 'c' => 1];
        }

        return array_filter([
            'id' => $animation['id'],
            'type' => 'appear',
            'start' => $animation['trigger']['start'] ?? null,
            'node' => isset($animation['trigger']['node']) ? substr($animation['trigger']['node'], 1) : null,
            'media' => self::media($animation['on'] ?? [], $breakpoints),
            'cycle' => true,
            'steps' => $compiled,
        ], static fn ($v) => $v !== null);
    }

    /** appear, hover, press and event: what the runtime hands to element.animate(). */
    private static function runtime(array $animation, array $breakpoints): array
    {
        $steps = self::timeline($animation);
        $total = (float) max(array_column($steps, 'end'));
        $repeat = (int) ($animation['repeat'] ?? 0);
        $seen = [];
        $compiled = [];

        foreach ($steps as ['step' => $step, 'start' => $start, 'end' => $end]) {
            $frames = self::frames($step);

            if ($frames === []) {
                continue;
            }

            $target = $step['target'];
            $compiled[] = array_filter([
                't' => $target,
                'k' => $frames,
                'd' => (int) round(((float) ($step['duration'] ?? 0.6)) * 1000),
                'w' => (int) round($start * 1000),
                'r' => (int) round(($total - $end) * 1000),
                'e' => self::timing($step),
                's' => isset($step['stagger']) && $target !== 'self' ? (int) round($step['stagger'] * 1000) : null,
                'f' => isset($seen[$target]) ? 'forwards' : 'both',
                'i' => $repeat === -1 ? -1 : ($repeat > 0 ? $repeat + 1 : null),
                'y' => ! empty($animation['yoyo']) ? 1 : null,
                'h' => isset($step['to']) ? 1 : null,
            ], static fn ($v) => $v !== null && $v !== 0);
            $seen[$target] = true;
        }

        return array_filter([
            'id' => $animation['id'],
            'type' => $animation['trigger']['type'],
            'start' => $animation['trigger']['start'] ?? null,
            'once' => $animation['trigger']['once'] ?? null,
            'node' => isset($animation['trigger']['node']) ? substr($animation['trigger']['node'], 1) : null,
            'media' => self::media($animation['on'] ?? [], $breakpoints),
            'steps' => $compiled,
        ], static fn ($v) => $v !== null);
    }

    /** The CSS for one rule, only for people who don't mind motion and on the screens asked for. */
    private static function guarded(array $animation, array $breakpoints, string $css): string
    {
        $media = self::media($animation['on'] ?? [], $breakpoints);
        $media !== null && $css = "@media {$media}{{$css}}";

        return "@media (prefers-reduced-motion:no-preference){{$css}}";
    }

    /** A media query for the screens an animation plays on, or null for all of them. */
    public static function media(array $screens, array $breakpoints): ?string
    {
        if ($screens === [] || count($screens) === 3) {
            return null;
        }

        [$md, $lg] = [$breakpoints['md'] ?? '768px', $breakpoints['lg'] ?? '1024px'];
        $queries = [
            'mobile' => '(max-width:'.self::below($md).')',
            'tablet' => '(min-width:'.$md.') and (max-width:'.self::below($lg).')',
            'desktop' => '(min-width:'.$lg.')',
        ];

        return implode(',', array_map(static fn (string $screen) => $queries[$screen], $screens));
    }

    private static function below(string $width): string
    {
        return preg_match('/^([\d.]+)(px|rem|em)$/', $width, $m) ? ((float) $m[1] - ($m[2] === 'px' ? 0.02 : 0.001)).$m[2] : $width;
    }

    /** "top 80%" as an offset in the `cover` range: 0% is the element's top at the viewport's bottom, 100% its bottom at the top. */
    private static function range(string $position): string
    {
        [$edge, $view] = array_pad(explode(' ', $position, 2), 2, 'bottom');
        $v = match ($view) {
            'top' => 0.0,
            'center' => 50.0,
            'bottom' => 100.0,
            default => (float) $view,
        };

        return match ($edge) {
            'center' => 'calc(50% + '.round(50 - $v, 2).'vh)',
            'bottom' => 'calc(100% - '.round($v, 2).'vh)',
            default => round(100 - $v, 2).'vh',
        };
    }

    /** @return array<int, array<string, string|float|int>> keyframes with one side left to the element's own style */
    private static function frames(array $step): array
    {
        $from = self::css($step['from'] ?? []);
        $to = self::css($step['to'] ?? []);
        $frames = [];
        $from !== [] && $frames[] = [...$from, 'offset' => 0];
        $to !== [] && $frames[] = [...$to, 'offset' => 1];

        return $frames;
    }

    private static function keyframes(array $step): ?string
    {
        $rules = '';

        foreach (self::frames($step) as $frame) {
            $offset = $frame['offset'] === 0 ? 'from' : 'to';
            unset($frame['offset']);
            $body = [];

            foreach ($frame as $property => $value) {
                $body[] = self::CSS[$property].':'.$value;
            }

            $rules .= $offset.'{'.implode(';', $body).'}';
        }

        return $rules === '' ? null : $rules;
    }

    /** Builder properties → CSS, as camelCase names element.animate() accepts. */
    private static function css(array $props): array
    {
        $out = [];
        $transform = [];
        $number = static fn (mixed $v): string => is_numeric($v) ? ((float) $v).'px' : (string) $v;

        if (isset($props['perspective'])) {
            $transform[] = 'perspective('.$number($props['perspective']).')';
        }

        foreach (['x' => 'xPercent', 'y' => 'yPercent'] as $axis => $percent) {
            $parts = [];
            isset($props[$axis]) && $parts[] = $number($props[$axis]);
            isset($props[$percent]) && $parts[] = ((float) $props[$percent]).'%';

            if ($parts !== []) {
                $value = count($parts) === 2 ? 'calc('.$parts[0].' + '.$parts[1].')' : $parts[0];
                $transform[] = 'translate'.strtoupper($axis).'('.$value.')';
            }
        }

        isset($props['z']) && $transform[] = 'translateZ('.$number($props['z']).')';

        foreach (['rotate', 'rotateX', 'rotateY'] as $key) {
            isset($props[$key]) && $transform[] = $key.'('.((float) $props[$key]).'deg)';
        }

        foreach (['scale', 'scaleX', 'scaleY'] as $key) {
            isset($props[$key]) && $transform[] = $key.'('.((float) $props[$key]).')';
        }

        foreach (['skewX', 'skewY'] as $key) {
            isset($props[$key]) && $transform[] = $key.'('.((float) $props[$key]).'deg)';
        }

        $transform !== [] && $out['transform'] = implode(' ', $transform);

        $filter = [];
        isset($props['blur']) && $filter[] = 'blur('.((float) $props['blur']).'px)';
        isset($props['brightness']) && $filter[] = 'brightness('.((float) $props['brightness']).')';
        isset($props['grayscale']) && $filter[] = 'grayscale('.((float) $props['grayscale']).')';
        $filter !== [] && $out['filter'] = implode(' ', $filter);

        foreach (['opacity', 'clipPath', 'backgroundColor', 'color', 'borderColor', 'borderRadius', 'boxShadow', 'letterSpacing', 'transformOrigin'] as $key) {
            if (isset($props[$key])) {
                $value = is_string($props[$key]) ? Css::value($props[$key]) : $props[$key];
                $value !== null && $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function timing(array $step): string
    {
        $ease = (string) ($step['ease'] ?? 'power3.out');

        return self::EASES[preg_replace('/\(.*\)$/', '', $ease)] ?? self::EASES[$ease.'.out'] ?? $ease;
    }

    private static function seconds(float $value): string
    {
        return ((int) round($value * 1000)).'ms';
    }

    private static function hash(string $value): string
    {
        return substr(hash('xxh128', $value), 0, 8);
    }

    private static function props(mixed $props): array
    {
        $out = [];

        foreach ((array) $props as $key => $value) {
            $kind = self::PROPERTIES[$key] ?? null;

            if ($kind === null) {
                continue;
            }

            if ($kind === 'number' && is_numeric($value)) {
                $out[$key] = (float) $value;
            } elseif ($kind === 'length' && (is_numeric($value) || (is_string($value) && preg_match('/^-?[\d.]+(px|rem|em|vw|vh|%)$/', trim($value))))) {
                $out[$key] = is_numeric($value) ? (float) $value : trim($value);
            } elseif ($kind === 'css' && is_string($value) && Css::value($value) !== null) {
                $out[$key] = trim($value);
            }
        }

        return $out;
    }

    private static function number(mixed $value, float $min, float $max): float
    {
        return round(max($min, min($max, (float) $value)), 3);
    }

    /** "<element edge> <viewport position>": "top 80%", "center center", "bottom top". */
    private static function position(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(top|center|bottom) (top|center|bottom|\d{1,3}(\.\d+)?%)$/', trim($value)) ? trim($value) : null;
    }

    /** Names from EASES ("expo.out", "back.out(1.7)"), or cubic-bezier(), linear() and steps() as written. */
    private static function ease(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        $name = (string) preg_replace('/\([\d., ]{0,20}\)$/', '', $value);

        if (isset(self::EASES[$name]) || isset(self::EASES[$name.'.out'])) {
            return $name;
        }

        return preg_match('/^cubic-bezier\(\s*-?[\d.]+\s*,\s*-?[\d.]+\s*,\s*-?[\d.]+\s*,\s*-?[\d.]+\s*\)$/', $value)
            || preg_match('/^linear\([\d.,% -]{1,800}\)$/', $value)
            || preg_match('/^steps\(\s*\d{1,3}\s*(,\s*(start|end|jump-[a-z]+)\s*)?\)$/', $value) ? $value : null;
    }
}
