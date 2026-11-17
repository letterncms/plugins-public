<?php

declare(strict_types=1);

namespace Cms\SiteSearch;

use Cms\Modules\Blocks\Elements;
use Cms\Modules\Blocks\Icons;

/**
 * The Search Field block: a search form that sends what was typed to the results page as `?q=`, a plain GET, so it
 * works without scripts. Its field looks like every field of the site (`cms-input`) and its button like the site's
 * buttons (`cms-button btn-<style>`); the size is the control size they share (`data-size`). The label is always
 * there for screen readers, and shown only when asked.
 */
final class SearchForm
{
    /** The plugin's one browser feature: its stylesheet, and the script that searches on the results page. */
    public const string FEATURE = 'site-search/search';

    /** Where the results are when nobody chose a page. */
    public const string RESULTS = '/search';

    private const array SIZES = ['sm', 'md', 'lg'];

    public static function definition(): array
    {
        $link = ['type' => 'url', 'component' => 'link', 'reference' => true];

        return [
            'name' => 'site-search/form', 'title' => 'Search Field', 'icon' => 'magnifying-glass', 'category' => 'interactive',
            'description' => 'A search field that opens the search results page.',
            'keywords' => ['search', 'find', 'query', 'lookup', 'form', 'field', 'input', 'site search'],
            'uses' => [self::FEATURE],
            'fields' => [
                ['handle' => 'label', 'type' => 'text', 'label' => 'Label', 'placeholder' => 'Search'],
                ['handle' => 'show_label', 'type' => 'toggle', 'label' => 'Show Label', 'default' => false],
                ['handle' => 'placeholder', 'type' => 'text', 'label' => 'Placeholder'],
                ['handle' => 'button_label', 'type' => 'text', 'label' => 'Button Label', 'placeholder' => 'Search'],
                ['handle' => 'button_style', 'type' => 'select', 'label' => 'Button Style', 'options' => [], 'dynamic' => 'button-variants', 'default' => 'primary'],
                ['handle' => 'icon_only', 'type' => 'toggle', 'label' => 'Icon Only Button', 'default' => false],
                ['handle' => 'size', 'type' => 'select', 'label' => 'Size', 'options' => ['sm' => 'S', 'md' => 'M', 'lg' => 'L'], 'default' => 'md', 'display' => 'segmented'],
                ['handle' => 'results_page', ...$link, 'label' => 'Results Page', 'placeholder' => self::RESULTS],
            ],
            'defaults' => ['button_style' => 'primary', 'size' => 'md'],
            'render' => self::render(...),
            'example' => ['label' => 'Search the site', 'placeholder' => 'Bins, parking, council tax…', 'results_page' => self::RESULTS],
        ];
    }

    public static function render(array $d): string
    {
        $b = $d['block'];
        $id = $d['renderer']->nextId('site-search-');
        $label = self::text($b['label'] ?? null, __('Search'));
        $buttonLabel = self::text($b['button_label'] ?? null, __('Search'));
        $size = in_array($b['size'] ?? null, self::SIZES, true) ? $b['size'] : 'md';
        $iconOnly = ! empty($b['icon_only']);

        $button = '<button type="submit" class="cms-button '.self::buttonClass($b['button_style'] ?? null).' cms-site-search-form__button"'
            .($iconOnly ? ' data-icon-only aria-label="'.e($buttonLabel).'">'.Icons::svg('magnifying-glass', 'cms-button__icon') : '>'.e($buttonLabel))
            .'</button>';

        return '<form '.$d['attrs'].' role="search" method="get" action="'.e(self::action($b['results_page'] ?? null)).'" data-size="'.$size.'" data-cms-site-search-form>'
            .'<label class="cms-site-search-form__label" for="'.$id.'"'.(empty($b['show_label']) ? ' data-hidden' : '').'>'.e($label).'</label>'
            .'<div class="cms-site-search-form__row">'
            .'<input class="cms-input cms-site-search-form__input" type="search" id="'.$id.'" name="q" placeholder="'.e(trim((string) ($b['placeholder'] ?? ''))).'" autocomplete="off" enterkeyhint="search">'
            .$button
            .'</div></form>';
    }

    /** Where the form goes: the page chosen, else /search. A link that leads nowhere on the site counts as none. */
    public static function action(mixed $page): string
    {
        $url = Elements::url($page);

        return $url !== null && ! str_starts_with($url, '#') && ! preg_match('#^(mailto|tel):#i', $url) ? $url : self::RESULTS;
    }

    /** The class of a button's look: btn-<style>, of the site's theme; btn-primary for a name that can't be one. */
    public static function buttonClass(mixed $style): string
    {
        $style = is_string($style) ? trim($style) : '';

        return 'btn-'.(preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $style) ? $style : 'primary');
    }

    /**
     * Every look a button can have here, as the classes this plugin's PHP writes: the site's CSS holds only the classes
     * its content names, and a block's setting is no class.
     *
     * @return list<string>
     */
    public static function buttonClasses(): array
    {
        $styles = array_keys((array) cms()->hooks()->filter('fields.options.button-variants', []));

        return array_values(array_unique(['btn-primary', 'btn-secondary', ...array_map(static fn ($style) => self::buttonClass((string) $style), $styles)]));
    }

    /** A setting's words, or the default when it has none. */
    public static function text(mixed $value, string $default): string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : $default;
    }
}
