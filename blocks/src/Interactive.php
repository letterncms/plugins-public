<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Illuminate\Support\Str;

/**
 * Blocks with behaviour of their own: Tabs and the filter around a Repeat. Each is real
 * HTML first. Without scripts every panel and every item is there to read, and the
 * runtime chunk only adds the switching.
 */
final class Interactive
{
    /** Tabs, by the WAI-ARIA pattern: arrow keys in the list, and panels that find-in-page can open. */
    public static function tabs(array $d): string
    {
        $b = $d['block'];
        $renderer = $d['renderer'];
        $panels = array_values(array_filter((array) ($d['node']['children'] ?? []), static fn ($c) => is_array($c) && ($c['type'] ?? '') === 'tab-panel' && empty($c['hidden'])));

        if ($panels === []) {
            return $d['editor'] ? '<div '.$d['attrs'].'></div>' : '';
        }

        // One panel needs no tabs. The canvas shows them all, so each can be selected.
        if (count($panels) === 1 || $d['editor']) {
            $renderer->tabPanel = $d['editor'] ? ['plain' => true, 'label' => true] : ['plain' => true];
            $html = $renderer->children($panels);
            $renderer->tabPanel = null;

            return '<div '.$d['attrs'].'>'.$html.'</div>';
        }

        $id = $renderer->nextId('t');
        $selected = max(0, min(count($panels) - 1, (int) ($b['default'] ?? 1) - 1));
        $list = '';
        $html = '';

        foreach ($panels as $i => $panel) {
            $on = $i === $selected;
            $label = Scope::text($panel['props']['label'] ?? '') ?: __('Tab');
            $list .= '<button type="button" role="tab" class="cms-tabs__tab" id="'.$id.'-t'.$i.'" aria-controls="'.$id.'-p'.$i.'" aria-selected="'.($on ? 'true' : 'false').'" tabindex="'.($on ? '0' : '-1').'">'.e($label).'</button>';
            $renderer->tabPanel = ['id' => "{$id}-p{$i}", 'tab' => "{$id}-t{$i}", 'selected' => $on, 'label' => true];
            $html .= $renderer->children([$panel]);
        }

        $renderer->tabPanel = null;
        $renderer->uses('tabs');

        return '<div '.$d['attrs'].' data-cms-tabs><div class="cms-tabs__list'.(in_array($b['look'] ?? '', ['segmented', 'vertical'], true) ? ' cms-tabs__list--'.$b['look'] : '').'" role="tablist" aria-label="'.e((string) ($b['label'] ?? 'Tabs')).'">'.$list.'</div>'.$html.'</div>';
    }

    public static function tabPanel(array $d): string
    {
        $panel = $d['renderer']->tabPanel ?? [];
        $label = ! empty($panel['label']) ? '<p class="cms-tabs__label">'.e(Scope::text($d['block']['label'] ?? '')).'</p>' : '';

        if (! isset($panel['id'])) {
            return '<div '.$d['attrs'].'>'.$label.$d['content'].'</div>';
        }

        return '<div '.$d['attrs'].' role="tabpanel" id="'.e($panel['id']).'" aria-labelledby="'.e($panel['tab']).'" tabindex="0"'.($panel['selected'] ? '' : ' hidden="until-found"').'>'.$label.$d['content'].'</div>';
    }

    /** A filter around a Repeat. Its Choice controls take their options, with counts, from the Repeat's items. */
    public static function listFilter(array $d): string
    {
        $renderer = $d['renderer'];
        $children = (array) ($d['node']['children'] ?? []);
        $repeat = self::findRepeat($children);
        $facets = $repeat ? $renderer->facets($repeat) : [];

        $previous = $renderer->filterFacets;
        $renderer->filterFacets = $facets;
        $html = $renderer->children($children);
        $renderer->filterFacets = $previous;
        $renderer->uses('filter');

        $status = (string) ($d['block']['status'] ?? ':count of :total');

        return '<div '.$d['attrs'].' data-cms-filter data-status="'.e($status).'">'.$html.'<p class="cms-filter__status" role="status" data-cms-filter-status></p></div>';
    }

    public static function filterSearch(array $d): string
    {
        $b = $d['block'];

        return '<label '.$d['attrs'].' data-cms-filter-control><span class="cms-filter__legend">'.e((string) ($b['label'] ?? 'Search')).'</span><input type="search" name="q" placeholder="'.e((string) ($b['placeholder'] ?? '')).'" autocomplete="off"></label>';
    }

    public static function filterChoice(array $d): string
    {
        $b = $d['block'];
        $facet = self::handle((string) ($b['facet'] ?? ''));
        $options = $d['renderer']->filterFacets[$facet] ?? [];

        if ($options === [] && ! $d['editor']) {
            return '';
        }

        $total = array_sum(array_column($options, 'count'));
        $option = static fn (string $value, string $label, int $count, bool $checked) => '<label class="cms-filter__option"><input type="radio" name="'.e($facet).'" value="'.e($value).'"'.($checked ? ' checked' : '').'><span>'.e($label).'</span> <span class="cms-filter__count">'.$count.'</span></label>';
        $html = $option('', (string) ($b['all_label'] ?? 'All'), (int) $total, true);

        foreach ($options as $value => $o) {
            $html .= $option((string) $value, $o['label'], $o['count'], false);
        }

        $look = ($b['look'] ?? 'chips') === 'list' ? ' data-look="list"' : '';

        return '<fieldset '.$d['attrs'].$look.' data-cms-filter-control data-facet="'.e($facet).'"><legend class="cms-filter__legend">'.e((string) ($b['label'] ?? $facet)).'</legend>'.$html.'</fieldset>';
    }

    public static function filterSort(array $d): string
    {
        $b = $d['block'];
        $options = '';

        foreach (array_values((array) ($b['options'] ?? [])) as $i => $option) {
            $field = self::handle((string) ($option['field'] ?? ''));
            $value = $field === '' ? '' : (Str::slug((string) ($option['label'] ?? $field)) ?: $field);
            $options .= '<option value="'.e($value).'" data-field="'.e($field).'" data-direction="'.(($option['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc').'"'.($i === 0 ? ' selected' : '').'>'.e((string) ($option['label'] ?? '')).'</option>';
        }

        return '<label '.$d['attrs'].' data-cms-filter-control><span class="cms-filter__legend">'.e((string) ($b['label'] ?? 'Sort')).'</span><select name="sort">'.$options.'</select></label>';
    }

    public static function filterEmpty(array $d): string
    {
        return '<div '.$d['attrs'].' data-cms-filter-empty'.($d['editor'] ? '' : ' hidden').'>'.$d['content'].'</div>';
    }

    /** The key a path filters by: "item.category" → category. */
    public static function handle(string $path): string
    {
        return (string) preg_replace('/[^a-z0-9_-]/', '', strtolower((string) substr((string) strrchr('.'.trim($path), '.'), 1)));
    }

    /** @return list<string> */
    public static function paths(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    public static function slug(string $value): string
    {
        return Str::slug($value) ?: 'v-'.substr(hash('xxh128', $value), 0, 8);
    }

    private static function findRepeat(array $nodes): ?array
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? '') === 'repeat') {
                return $node;
            }

            if (($found = self::findRepeat((array) ($node['children'] ?? []))) !== null) {
                return $found;
            }
        }

        return null;
    }
}
