<?php

declare(strict_types=1);

namespace Cms\SiteSearch;

/**
 * The Search Results block, for the page a Search Field sends people to. The page itself is static: it holds a status
 * line that says search needs scripts, an empty list of filters and an empty list of results. The script reads `q`
 * (and `kind`) from the address, searches the site's index and fills them in. On the canvas it shows a sample, so
 * designers see what they style.
 */
final class SearchResults
{
    private const array LEVELS = [2, 3, 4];

    private const int PER_PAGE = 20;

    public static function definition(): array
    {
        return [
            'name' => 'site-search/results', 'title' => 'Search Results', 'icon' => 'list-magnifying-glass', 'category' => 'interactive',
            'description' => 'The results of a search, with filters by kind of page.',
            'keywords' => ['search', 'results', 'find', 'query', 'filter', 'site search', 'search page'],
            'uses' => [SearchForm::FEATURE],
            'fields' => [
                ['handle' => 'heading_level', 'type' => 'select', 'label' => 'Heading Level', 'options' => ['2' => 'H2', '3' => 'H3', '4' => 'H4'], 'default' => '2', 'display' => 'segmented', 'about' => 'Of each result’s title, below the page’s own headings.'],
                ['handle' => 'per_page', 'type' => 'number', 'label' => 'Results Per Page', 'min' => 1, 'max' => 100, 'default' => self::PER_PAGE],
                ['handle' => 'show_filters', 'type' => 'toggle', 'label' => 'Show Filters', 'default' => true],
                ['handle' => 'no_query', 'type' => 'text', 'label' => 'No Query', 'placeholder' => 'Type what you are looking for.'],
                ['handle' => 'none', 'type' => 'text', 'label' => 'No Results', 'placeholder' => 'No results for “{query}”', 'about' => '{query} is what was searched for.'],
                ['handle' => 'one', 'type' => 'text', 'label' => 'One Result', 'placeholder' => '1 result for “{query}”'],
                ['handle' => 'many', 'type' => 'text', 'label' => 'Many Results', 'placeholder' => '{count} results for “{query}”', 'about' => '{count} is how many were found.'],
                ['handle' => 'all_label', 'type' => 'text', 'label' => 'All Label', 'placeholder' => 'All results'],
                ['handle' => 'filters_label', 'type' => 'text', 'label' => 'Filters Label', 'placeholder' => 'Filter results', 'about' => 'Read by screen readers for the list of filters.'],
                ['handle' => 'no_script', 'type' => 'text', 'label' => 'Without JavaScript', 'placeholder' => 'Search needs JavaScript. Browse the site’s menu instead.'],
                ['handle' => 'more_label', 'type' => 'text', 'label' => 'More Label', 'placeholder' => 'Show More Results'],
                ['handle' => 'more_style', 'type' => 'select', 'label' => 'More Button Style', 'options' => [], 'dynamic' => 'button-variants', 'default' => 'secondary'],
            ],
            'defaults' => ['heading_level' => '2', 'per_page' => self::PER_PAGE, 'show_filters' => true, 'more_style' => 'secondary'],
            'render' => self::render(...),
            'example' => ['heading_level' => '2', 'per_page' => 20, 'show_filters' => true],
        ];
    }

    /** @return array{noQuery: string, none: string, one: string, many: string, all: string, filters: string, noScript: string, more: string} */
    public static function texts(array $b): array
    {
        return [
            'noQuery' => SearchForm::text($b['no_query'] ?? null, __('Type what you are looking for.')),
            'none' => SearchForm::text($b['none'] ?? null, __('No results for “{query}”')),
            'one' => SearchForm::text($b['one'] ?? null, __('1 result for “{query}”')),
            'many' => SearchForm::text($b['many'] ?? null, __('{count} results for “{query}”')),
            'all' => SearchForm::text($b['all_label'] ?? null, __('All results')),
            'filters' => SearchForm::text($b['filters_label'] ?? null, __('Filter results')),
            'noScript' => SearchForm::text($b['no_script'] ?? null, __('Search needs JavaScript. Browse the site’s menu instead.')),
            'more' => SearchForm::text($b['more_label'] ?? null, __('Show More Results')),
        ];
    }

    public static function level(mixed $level): int
    {
        return in_array((int) $level, self::LEVELS, true) ? (int) $level : 2;
    }

    public static function render(array $d): string
    {
        $b = $d['block'];
        $texts = self::texts($b);
        $level = self::level($b['heading_level'] ?? null);
        $perPage = is_numeric($b['per_page'] ?? null) ? max(1, min(100, (int) $b['per_page'])) : self::PER_PAGE;
        $filters = (bool) ($b['show_filters'] ?? true);
        $moreClass = SearchForm::buttonClass($b['more_style'] ?? 'secondary');
        $config = json_encode(['level' => $level, 'perPage' => $perPage, 'filters' => $filters, 'moreClass' => $moreClass, 'texts' => $texts], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // The button that shows more results is the script's: without scripts there is nothing it could do.
        $more = '<button type="button" class="cms-button '.$moreClass.' cms-site-search-results__more">'.e($texts['more']).'</button>';

        if ($d['editor']) {
            return self::sample($d, $texts, $level, $filters, $more);
        }

        return '<div '.$d['attrs'].' data-cms-site-search-results="'.e((string) $config).'">'
            .'<p class="cms-site-search-results__status" role="status" aria-live="polite">'.e($texts['noScript']).'</p>'
            .'<nav class="cms-site-search-results__filters" aria-label="'.e($texts['filters']).'" hidden></nav>'
            .'<ol class="cms-site-search-results__list"></ol>'
            .'</div>';
    }

    /** Canvas only: what a search shows, with two results and the button for more, so a designer sees every part they style. */
    private static function sample(array $d, array $texts, int $level, bool $filters, string $more): string
    {
        $query = __('recycling');
        $status = strtr($texts['many'], ['{count}' => '2', '{query}' => $query]);
        $service = __('Service');
        $news = __('News');
        $nav = $filters
            ? '<nav class="cms-site-search-results__filters" aria-label="'.e($texts['filters']).'"><ul>'
                .'<li><a href="#" aria-current="true">'.e($texts['all']).' (2)</a></li>'
                .'<li><a href="#">'.e($service).' (1)</a></li>'
                .'<li><a href="#">'.e($news).' (1)</a></li>'
                .'</ul></nav>'
            : '';
        $mark = static fn (string $text) => (string) preg_replace('/'.preg_quote(e($query), '/').'/iu', '<mark>$0</mark>', e($text));

        return '<div '.$d['attrs'].'>'
            .'<p class="cms-site-search-results__status" role="status">'.e($status).'</p>'
            .$nav
            .'<ol class="cms-site-search-results__list">'
            .self::result($level, $service, __('Bins and recycling collection'), null, $mark(__('Find out which bin goes out this week, and what goes in it for recycling.')))
            .self::result($level, $news, __('A new recycling centre opens in March'), '2026-03-02', $mark(__('The centre takes garden waste, furniture and electricals for recycling, six days a week.')))
            .'</ol>'
            .$more
            .'</div>';
    }

    /** One result, as the script draws it: its title as a link in a heading, then its kind, its date and a snippet. */
    private static function result(int $level, string $kind, string $title, ?string $date, string $snippet): string
    {
        $time = $date !== null ? '<time class="cms-site-search-results__date" datetime="'.$date.'">'.e(\Carbon\CarbonImmutable::parse($date)->locale(app()->getLocale())->isoFormat('LL')).'</time>' : '';

        return '<li class="cms-site-search-results__item"><article class="cms-site-search-results__result">'
            ."<h{$level} class=\"cms-site-search-results__title\"><a href=\"#\">".e($title)."</a></h{$level}>"
            .'<p class="cms-site-search-results__kind">'.e($kind).'</p>'
            .$time
            .'<p class="cms-site-search-results__snippet">'.$snippet.'</p>'
            .'</article></li>';
    }
}
