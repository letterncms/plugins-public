<?php

declare(strict_types=1);

namespace Cms\Analytics;

use Illuminate\Database\Query\Builder;
use Cms\Abilities\AbilityException;

/**
 * What a report is narrowed to: visits from Google, views of /pricing, visitors who reached a goal. Each
 * filter is a dimension, how it compares (`is`, `is_not`, `contains`) and a value; `*` in a page's value
 * stands for anything (`/blog/*`), and a null value is "none" (no source: Direct). All of them hold at once.
 */
final readonly class Filters
{
    /** Dimension => the visit's column it narrows, or null for what is looked up in its views or events. */
    public const array DIMENSIONS = [
        'page' => null, 'goal' => null,
        'entry' => 'entry', 'exit' => 'exit', 'channel' => 'channel', 'source' => 'source', 'medium' => 'medium', 'campaign' => 'campaign',
        'country' => 'country', 'device' => 'device', 'browser' => 'browser', 'os' => 'os',
    ];

    public const array OPERATORS = ['is', 'is_not', 'contains'];

    /** @param list<array{dimension: string, op: string, value: ?string}> $all */
    private function __construct(public array $all) {}

    /** @param mixed $input what an ability was given: a list of {dimension, op?, value} */
    public static function of(mixed $input): self
    {
        $all = [];

        foreach (is_array($input) ? $input : [] as $i => $filter) {
            $dimension = is_array($filter) ? ($filter['dimension'] ?? null) : null;
            $op = is_array($filter) ? ($filter['op'] ?? 'is') : null;
            $value = is_array($filter) ? ($filter['value'] ?? null) : null;

            if (! is_string($dimension) || ! array_key_exists($dimension, self::DIMENSIONS) || ! in_array($op, self::OPERATORS, true) || ! (is_string($value) || $value === null) || ($value === null && $op === 'contains')) {
                throw AbilityException::invalid('A filter is a dimension (page, goal, entry, exit, channel, source, medium, campaign, country, device, browser or os), an op (is, is_not or contains) and a value: a text, or null for none.', ['filter' => (string) $i]);
            }

            $all[] = ['dimension' => $dimension, 'op' => $op, 'value' => $value === null ? null : mb_substr($value, 0, 400)];
        }

        return new self($all);
    }

    public function without(string $dimension): self
    {
        return new self(array_values(array_filter($this->all, static fn (array $f) => $f['dimension'] !== $dimension)));
    }

    public function has(string $dimension): bool
    {
        return $this->only($dimension) !== [];
    }

    /** @return list<array{dimension: string, op: string, value: ?string}> */
    public function only(string $dimension): array
    {
        return array_values(array_filter($this->all, static fn (array $f) => $f['dimension'] === $dimension));
    }

    /** Whether counts mode, which knows pages and nothing else, can answer it. */
    public function pagesOnly(): bool
    {
        return array_filter($this->all, static fn (array $f) => $f['dimension'] !== 'page') === [];
    }

    /** Narrow a query of visits (aliased `v`) to the visits that match. */
    public function visits(Builder $query, Goals $goals): Builder
    {
        foreach ($this->all as $filter) {
            $column = self::DIMENSIONS[$filter['dimension']];

            if ($column !== null) {
                self::compare($query, 'v.'.$column, $filter['op'], $filter['value']);
            } elseif ($filter['dimension'] === 'page') {
                // Visits with a view of the page; for `is_not`, with a view of another page (whose views are the ones counted).
                $query->whereExists(static fn (Builder $q) => self::compare($q->selectRaw('1')->from('analytics_views as fp')->whereColumn('fp.visit_id', 'v.id'), 'fp.path', $filter['op'], $filter['value']));
            } else {
                $exists = static fn (Builder $q) => $goals->reached($q, $filter['value'], 'v.id');
                $filter['op'] === 'is_not' ? $query->whereNotExists($exists) : $query->whereExists($exists);
            }
        }

        return $query;
    }

    /** Narrow the page views themselves (aliased `w`) to the pages asked for. */
    public function views(Builder $query, string $column = 'w.path'): Builder
    {
        foreach ($this->only('page') as $filter) {
            self::compare($query, $column, $filter['op'], $filter['value']);
        }

        return $query;
    }

    /** `=` or `like` for `*`, its opposite for `is_not`, `%…%` for `contains`, and null for none. */
    public static function compare(Builder $query, string $column, string $op, ?string $value, bool $contains = false): Builder
    {
        if ($value === null) {
            return $op === 'is_not' ? $query->whereNotNull($column) : $query->whereNull($column);
        }

        if ($op === 'contains' || $contains) {
            return $query->where($column, 'like', '%'.$value.'%');
        }

        $like = str_contains($value, '*');
        $value = $like ? str_replace('*', '%', $value) : $value;

        return $op === 'is_not'
            ? $query->where(static fn (Builder $q) => $q->where($column, $like ? 'not like' : '!=', $value)->orWhereNull($column))
            : $query->where($column, $like ? 'like' : '=', $value);
    }

    /** @return list<array{dimension: string, op: string, value: ?string}> */
    public function toArray(): array
    {
        return $this->all;
    }
}
