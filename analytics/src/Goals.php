<?php

declare(strict_types=1);

namespace Cms\Analytics;

use Illuminate\Database\Query\Builder;
use Cms\Cms;

/**
 * What counts as reaching a goal: every event (the snippet's own, a site's, a plugin's) is a goal by its
 * name, and the Goal Pages setting names pages that are one (Signed Up: /thank-you, or /welcome/*). Funnels
 * are steps, each a page (it starts with /) or a goal's name.
 */
final readonly class Goals
{
    public function __construct(private Cms $cms) {}

    /** @return array<string, string> a goal page's name => its path, `*` for anything */
    public function pages(): array
    {
        $out = [];

        foreach ((array) $this->cms->setting(Collector::SETTINGS, 'goals', []) as $goal) {
            $name = Collector::name((string) ($goal['name'] ?? ''));
            $path = trim((string) ($goal['path'] ?? ''));

            if ($name !== null && str_starts_with($path, '/')) {
                $out[$name] = $path === '/' ? '/' : rtrim($path, '/');
            }
        }

        return $out;
    }

    /** @return array<string, list<string>> a funnel's name => its steps */
    public function funnels(): array
    {
        $out = [];

        foreach ((array) $this->cms->setting(Collector::SETTINGS, 'funnels', []) as $funnel) {
            $name = Collector::name((string) ($funnel['name'] ?? ''));
            $steps = array_values(array_filter(array_map(static fn ($s) => trim((string) $s), (array) ($funnel['steps'] ?? [])), static fn ($s) => $s !== ''));

            if ($name !== null && count($steps) >= 2) {
                $out[$name] = array_slice($steps, 0, 8);
            }
        }

        return $out;
    }

    /** A step's or goal's page path, when it is a page: itself, or the Goal Page of that name. */
    public function path(string $goal): ?string
    {
        return str_starts_with($goal, '/') ? $goal : ($this->pages()[$goal] ?? null);
    }

    /** Narrow a subquery to a visit (`$visit`, a column) that reached a goal: viewed its page, or had its event. */
    public function reached(Builder $query, ?string $goal, string $visit): Builder
    {
        $path = $goal === null ? null : $this->path($goal);

        return $path !== null
            ? Filters::compare($query->selectRaw('1')->from('analytics_views as gv')->whereColumn('gv.visit_id', $visit), 'gv.path', 'is', $path)
            : $query->selectRaw('1')->from('analytics_events as ge')->whereColumn('ge.visit_id', $visit)->where('ge.name', (string) $goal);
    }
}
