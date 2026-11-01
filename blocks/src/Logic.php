<?php

declare(strict_types=1);

namespace Cms\Blocks;


/**
 * Scripts as data. A node's `logic` compiles to Alpine.js (CSP build)
 * attributes; actions run through the `$cms` runtime, so nothing here is
 * ever evaluated as free-form JavaScript.
 *
 *   logic: {
 *     data:   { open: false },                                   state for this element and everything inside
 *     component: "pricing",                                      …or an Alpine component registered in custom.js (instead of data)
 *     on:     [{ event: "click", mods: ["outside"], actions: [{ do: "set", var: "open", value: "false" }] }],
 *     init:   [actions],                                         when the element starts
 *     show:   "open",  transition: "fade",                       show only while true
 *     states: { open: "open" },                                  adds class is-open while true (style it with the is-open state)
 *     text:   "count", attrs: { "aria-expanded": "open" }, model: "query",
 *     target: ["b1…"], push: true,  merge: "append",             links and forms load into these blocks (alpine-ajax)
 *     behaviors: { scroll: { threshold: 40, hide: true }, spy: {}, group: true, menu: true },
 *   }
 */
final class Logic
{
    public const array EVENTS = [
        'click', 'dblclick', 'mouseenter', 'mouseleave', 'pointerdown', 'pointerup', 'focus', 'blur', 'focusin', 'focusout',
        'input', 'change', 'submit', 'keydown', 'keyup', 'scroll', 'resize', 'enter', 'leave', 'ajax:before', 'ajax:after', 'ajax:success', 'ajax:error',
    ];

    public const array MODS = ['prevent', 'stop', 'outside', 'window', 'document', 'once', 'self', 'debounce', 'throttle', 'passive'];

    public const array ACTIONS = ['set', 'toggle', 'add', 'if', 'wait', 'play', 'scroll', 'copy', 'go', 'theme', 'focus', 'dispatch', 'fetch', 'lock', 'run'];

    public const array TRANSITIONS = ['none', 'fade', 'scale', 'collapse'];

    public const array MERGES = ['replace', 'update', 'append', 'prepend', 'before', 'after', 'morph'];

    private const string NAME = '/^[a-zA-Z_$][a-zA-Z0-9_$]{0,40}$/';

    private const string VAR = '/^[a-zA-Z_][a-zA-Z0-9_]{0,40}(\.[a-zA-Z0-9_]{1,40}){0,3}$/';

    private const string ID = '/^[a-z0-9_-]{3,40}$/i';

    /** Clean a logic map; unknown keys and unsafe expressions are dropped. */
    public static function sanitize(mixed $logic): array
    {
        if (! is_array($logic)) {
            return [];
        }

        $out = [];

        if (is_string($logic['component'] ?? null) && preg_match(self::NAME, $logic['component'])) {
            $out['component'] = $logic['component'];
        }

        if (is_array($logic['data'] ?? null)) {
            foreach (array_slice($logic['data'], 0, 40, true) as $name => $value) {
                if (is_string($name) && preg_match(self::NAME, $name) && (is_scalar($value) || $value === null || (is_array($value) && strlen((string) json_encode($value)) < 4000))) {
                    $out['data'][$name] = $value;
                }
            }
        }

        foreach (['init'] as $key) {
            if (is_array($logic[$key] ?? null) && ($actions = self::actions($logic[$key])) !== []) {
                $out[$key] = $actions;
            }
        }

        foreach (array_slice((array) ($logic['on'] ?? []), 0, 20) as $handler) {
            if (! is_array($handler) || ! is_string($handler['event'] ?? null)) {
                continue;
            }

            $event = strtolower($handler['event']);

            if (! in_array($event, self::EVENTS, true) && ! preg_match('/^[a-z][a-z0-9-]{1,30}$/', $event)) {
                continue;
            }

            $clean = ['event' => $event];
            $mods = array_values(array_intersect((array) ($handler['mods'] ?? []), self::MODS));
            $mods !== [] && $clean['mods'] = $mods;

            if (is_string($handler['key'] ?? null) && preg_match('/^[a-z0-9]+([.-][a-z0-9]+){0,3}$/', strtolower($handler['key']))) {
                $clean['key'] = strtolower($handler['key']);
            }

            $clean['actions'] = self::actions((array) ($handler['actions'] ?? []));
            $out['on'][] = $clean;
        }

        foreach (['show', 'text'] as $key) {
            if (($expr = self::expression($logic[$key] ?? null)) !== null) {
                $out[$key] = $expr;
            }
        }

        if (isset($out['show']) && in_array($logic['transition'] ?? null, self::TRANSITIONS, true)) {
            $out['transition'] = $logic['transition'];
        }

        foreach (['states' => '/^[a-z][a-z0-9-]{0,30}$/', 'attrs' => '/^(aria-[a-z-]+|data-(?!cms(?:-|$))[a-z0-9-]+|href|src|alt|title|value|placeholder|disabled|hidden|tabindex|role|inert|open|checked|selected)$/'] as $key => $pattern) {
            foreach ((array) ($logic[$key] ?? []) as $name => $expr) {
                if (is_string($name) && preg_match($pattern, $name) && ($expr = self::expression($expr)) !== null) {
                    $out[$key][$name] = $expr;
                }
            }
        }

        if (is_string($logic['model'] ?? null) && preg_match(self::VAR, $logic['model'])) {
            $out['model'] = $logic['model'];
        }

        $targets = array_values(array_filter((array) ($logic['target'] ?? []), static fn ($id) => is_string($id) && preg_match(self::ID, $id)));

        if ($targets !== []) {
            $out['target'] = array_slice($targets, 0, 5);
            ! empty($logic['push']) && $out['push'] = true;
        }

        if (in_array($logic['merge'] ?? null, self::MERGES, true)) {
            $out['merge'] = $logic['merge'];
        }

        $behaviors = (array) ($logic['behaviors'] ?? []);

        if (is_array($behaviors['scroll'] ?? null)) {
            $out['behaviors']['scroll'] = ['threshold' => max(0, min(5000, (int) ($behaviors['scroll']['threshold'] ?? 20))), 'hide' => ! empty($behaviors['scroll']['hide'])];
        }

        if (is_array($behaviors['spy'] ?? null)) {
            $out['behaviors']['spy'] = ['offset' => max(0, min(1000, (int) ($behaviors['spy']['offset'] ?? 96)))];
        }

        if (! empty($behaviors['group'])) {
            $out['behaviors']['group'] = true;
        }

        if (($behaviors['menu'] ?? false) !== false) {
            $out['behaviors']['menu'] = [];
        }

        if (($behaviors['carousel'] ?? false) !== false) {
            $out['behaviors']['carousel'] = [];
        }

        if (is_array($behaviors['marquee'] ?? null)) {
            $out['behaviors']['marquee'] = ['speed' => max(5, min(400, (int) ($behaviors['marquee']['speed'] ?? 60))), 'reverse' => ! empty($behaviors['marquee']['reverse']), 'pause' => ! empty($behaviors['marquee']['pause'])];
        }

        return $out;
    }

    /**
     * Alpine attributes for a node. `$id` is the node id (targets and plays use it).
     *
     * @return array<string, string> attribute => value (unescaped)
     */
    public static function attributes(array $logic, string $id): array
    {
        $attrs = [];

        if (isset($logic['component'])) {
            $attrs['x-data'] = $logic['component'];
        } elseif (isset($logic['data'])) {
            $attrs['x-data'] = (string) json_encode((object) $logic['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $handlers = [];

        foreach ($logic['on'] ?? [] as $i => $handler) {
            $handlers[(string) $i] = $handler['actions'];
            $call = "\$cms.run(\$el, \$event, '{$i}')";

            if (in_array($handler['event'], ['enter', 'leave'], true)) {
                $attrs['x-intersect:'.$handler['event'].(in_array('once', $handler['mods'] ?? [], true) ? '.once' : '')] = $call;

                continue;
            }

            $name = 'x-on:'.$handler['event'];
            $name .= isset($handler['key']) ? '.'.$handler['key'] : '';

            foreach ($handler['mods'] ?? [] as $mod) {
                $name .= '.'.$mod;
            }

            if (in_array($handler['event'], ['scroll', 'resize'], true) && ! array_intersect(['window', 'document'], $handler['mods'] ?? [])) {
                $name .= '.window';
            }

            $attrs[$name] = $call;
        }

        if (isset($logic['init'])) {
            $handlers['init'] = $logic['init'];
            $attrs['x-init'] = "\$cms.run(\$el, null, 'init')";
        }

        if ($handlers !== []) {
            $attrs['data-cms-on'] = (string) json_encode(array_map(self::links(...), $handlers), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (isset($logic['show'])) {
            $attrs['x-show'] = $logic['show'];
            $attrs['x-cloak'] = '';

            match ($logic['transition'] ?? 'none') {
                'fade' => $attrs['x-transition.opacity.duration.200ms'] = '',
                'scale' => $attrs['x-transition.duration.200ms'] = '',
                'collapse' => $attrs['x-collapse'] = '',
                default => null,
            };
        }

        if (isset($logic['text'])) {
            $attrs['x-text'] = $logic['text'];
        }

        if (isset($logic['states'])) {
            $pairs = [];

            foreach ($logic['states'] as $name => $expr) {
                $pairs[] = "'is-{$name}': ({$expr})";
            }

            $attrs['x-bind:class'] = '{ '.implode(', ', $pairs).' }';
        }

        foreach ($logic['attrs'] ?? [] as $name => $expr) {
            if ($name === 'href' || $name === 'src') {
                // The runtime evaluates it and drops schemes other than Elements::url allows (javascript: and friends).
                $attrs['data-cms-'.$name] = $expr;
                $attrs['x-bind:'.$name] = "\$cms.url(\$el, '$name')";
            } else {
                $attrs['x-bind:'.$name] = $expr;
            }
        }

        if (isset($logic['model'])) {
            $attrs['x-model'] = $logic['model'];
        }

        if (isset($logic['target'])) {
            $attrs['x-target'.(! empty($logic['push']) ? '.push' : '')] = implode(' ', array_map(static fn ($t) => 'cms-'.$t, $logic['target']));
        }

        if (isset($logic['merge'])) {
            $attrs['x-merge'] = $logic['merge'];
        }

        foreach ($logic['behaviors'] ?? [] as $name => $options) {
            if ($name === 'group') {
                $attrs['data-cms-group'] = '';
            } else {
                $attrs['x-cms-'.$name] = (string) json_encode((object) $options);
            }
        }

        return $attrs;
    }

    /** Node ids a logic map points at (play, focus, scroll, fetch targets). */
    public static function references(array $logic): array
    {
        $ids = $logic['target'] ?? [];

        $walk = static function (array $actions) use (&$walk, &$ids): void {
            foreach ($actions as $action) {
                foreach (['node', 'to'] as $key) {
                    if (is_string($action[$key] ?? null) && preg_match(self::ID, $action[$key])) {
                        $ids[] = $action[$key];
                    }
                }

                array_push($ids, ...(array) ($action['target'] ?? []));
                $walk((array) ($action['then'] ?? []));
                $walk((array) ($action['else'] ?? []));
            }
        };

        foreach ($logic['on'] ?? [] as $handler) {
            $walk($handler['actions']);
        }

        $walk($logic['init'] ?? []);

        return array_values(array_unique(array_filter($ids, 'is_string')));
    }

    /** True when the node needs the Alpine runtime. */
    public static function active(array $logic): bool
    {
        return $logic !== [] && array_diff(array_keys($logic), ['behaviors']) !== [] || isset($logic['behaviors']) && array_diff(array_keys($logic['behaviors']), ['group']) !== [];
    }

    /** "Go to" a page by reference (entry:<id>) goes to where the page is now. */
    private static function links(array $actions): array
    {
        return array_map(static function ($action) {
            if (! is_array($action)) {
                return $action;
            }

            if (($action['do'] ?? null) === 'go' && str_starts_with((string) ($action['url'] ?? ''), 'entry:')) {
                $action['url'] = Elements::url($action['url']) ?? '/';
            }

            foreach (['then', 'else'] as $branch) {
                isset($action[$branch]) && is_array($action[$branch]) && $action[$branch] = self::links($action[$branch]);
            }

            return $action;
        }, $actions);
    }

    private static function actions(array $actions, int $depth = 0): array
    {
        $out = [];

        foreach (array_slice($actions, 0, 30) as $action) {
            if (! is_array($action) || ! in_array($action['do'] ?? null, self::ACTIONS, true)) {
                continue;
            }

            $clean = ['do' => $action['do']];

            switch ($action['do']) {
                case 'set':
                case 'add':
                    if (! is_string($action['var'] ?? null) || ! preg_match(self::VAR, $action['var'])) {
                        continue 2;
                    }
                    $clean['var'] = $action['var'];
                    $clean['value'] = self::expression($action['value'] ?? null) ?? ($action['do'] === 'add' ? '1' : 'null');
                    break;
                case 'toggle':
                    if (! is_string($action['var'] ?? null) || ! preg_match(self::VAR, $action['var'])) {
                        continue 2;
                    }
                    $clean['var'] = $action['var'];
                    break;
                case 'if':
                    if ($depth > 3 || ($test = self::expression($action['test'] ?? null)) === null) {
                        continue 2;
                    }
                    $clean += ['test' => $test, 'then' => self::actions((array) ($action['then'] ?? []), $depth + 1), 'else' => self::actions((array) ($action['else'] ?? []), $depth + 1)];
                    break;
                case 'wait':
                    $clean['ms'] = max(0, min(30000, (int) ($action['ms'] ?? 300)));
                    break;
                case 'play':
                    $clean['node'] = self::id($action['node'] ?? null);
                    $clean['motion'] = is_string($action['motion'] ?? null) && preg_match('/^[a-z0-9_-]{1,24}$/i', $action['motion']) ? $action['motion'] : null;
                    $clean['mode'] = in_array($action['mode'] ?? null, ['play', 'reverse', 'toggle', 'restart'], true) ? $action['mode'] : 'play';
                    break;
                case 'scroll':
                    $to = $action['to'] ?? 'top';
                    $clean['to'] = is_string($to) && ($to === 'top' || $to === 'bottom' || preg_match('/^#[a-z][a-z0-9-]{0,60}$/', $to) || preg_match(self::ID, $to)) ? $to : 'top';
                    $clean['offset'] = max(-2000, min(2000, (int) ($action['offset'] ?? 0)));
                    break;
                case 'copy':
                    $clean['value'] = self::expression($action['value'] ?? null);
                    $clean['node'] = self::id($action['node'] ?? null);
                    break;
                case 'go':
                    $url = (string) ($action['url'] ?? '');
                    if (! preg_match('#^(https?://|/|\#|mailto:|tel:|entry:)[^\s"<>]*$#', $url)) {
                        continue 2;
                    }
                    $clean['url'] = $url;
                    $clean['newTab'] = ! empty($action['newTab']);
                    break;
                case 'theme':
                    $clean['mode'] = in_array($action['mode'] ?? null, ['toggle', 'light', 'dark', 'system'], true) ? $action['mode'] : 'toggle';
                    break;
                case 'focus':
                    $clean['node'] = self::id($action['node'] ?? null);
                    break;
                case 'dispatch':
                    if (! is_string($action['event'] ?? null) || ! preg_match('/^[a-z][a-z0-9:-]{1,40}$/', $action['event'])) {
                        continue 2;
                    }
                    $clean['event'] = $action['event'];
                    $clean['detail'] = self::expression($action['detail'] ?? null);
                    break;
                case 'fetch':
                    $url = (string) ($action['url'] ?? '');
                    $clean['url'] = preg_match('#^/[^\s"<>]*$#', $url) ? $url : null;
                    $clean['urlFrom'] = self::expression($action['urlFrom'] ?? null);
                    $clean['target'] = array_values(array_filter((array) ($action['target'] ?? []), static fn ($t) => is_string($t) && preg_match(self::ID, $t)));
                    if (($clean['url'] === null && $clean['urlFrom'] === null) || $clean['target'] === []) {
                        continue 2;
                    }
                    break;
                case 'lock':
                    $clean['on'] = ! array_key_exists('on', $action) || (bool) $action['on'];
                    break;
                case 'run':
                    if (($expr = self::expression($action['expr'] ?? null)) === null) {
                        continue 2;
                    }
                    $clean['expr'] = $expr;
                    break;
            }

            $out[] = array_filter($clean, static fn ($v) => $v !== null);
        }

        return $out;
    }

    private static function id(mixed $id): ?string
    {
        return is_string($id) && preg_match(self::ID, $id) ? $id : null;
    }

    /** An Alpine (CSP build) expression. Constructs the CSP evaluator would refuse anyway are refused here first. */
    public static function expression(mixed $expr): ?string
    {
        if (is_bool($expr)) {
            return $expr ? 'true' : 'false';
        }

        if (is_int($expr) || is_float($expr)) {
            return (string) $expr;
        }

        if (! is_string($expr)) {
            return null;
        }

        $expr = trim($expr);

        if ($expr === '' || strlen($expr) > 400 || preg_match('/`|=>|\bfunction\b|\bnew\b|\bimport\b|\beval\b|constructor|__proto__|prototype|<\/?script/i', $expr)) {
            return null;
        }

        return $expr;
    }
}
