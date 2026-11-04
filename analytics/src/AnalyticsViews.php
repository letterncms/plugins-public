<?php

declare(strict_types=1);

namespace Cms\Analytics;

use Cms\Cms;

/**
 * The Analytics place, as data (see Cms\Admin\Views): the period's headline numbers and
 * chart, drawn by the plugin's `analytics/overview`, and where visits go and come from,
 * as lists of the analytics/breakdown ability for the period in the address (?period=).
 */
final class AnalyticsViews
{
    public function __construct(private readonly Cms $cms, private readonly Reports $reports) {}

    public function register(): void
    {
        $this->cms->admin()->component('analytics/overview', ['label' => 'Analytics overview', 'description' => 'The period’s visitors, views, views per visit and bounce rate, each switching the chart, with who is on the site now.', 'surface' => false, 'permission' => 'analytics.view',
            'props' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false]]);
        $this->cms->views()->register('analytics', '/analytics', fn () => $this->view(), permission: 'analytics.view');
    }

    private function view(): array
    {
        return ['type' => 'ui/page', 'props' => ['title' => __('Analytics'), 'icon' => 'chart', 'width' => 'wide'], 'children' => [
            ['type' => 'ui/tabs', 'children' => [
                ['type' => 'ui/tab', 'props' => ['id' => 'overview', 'label' => __('Overview')], 'children' => [['type' => 'ui/stack', 'children' => $this->overview()]]],
            ]],
        ]];
    }

    private function overview(): array
    {
        $settings = ['label' => __('Open Settings'), 'to' => '/analytics?tab=settings', 'can' => 'settings.manage'];

        if (! $this->reports->tracked()) {
            return $this->reports->enabled()
                ? [['type' => 'ui/empty', 'props' => ['icon' => 'chart', 'title' => __('No visits yet'), 'text' => __('Visits appear here as people open your published site.')]]]
                : [['type' => 'ui/empty', 'props' => ['icon' => 'chart', 'title' => __('Analytics is off'), 'text' => __('Turn it on to see who visits your site.'), 'action' => [...$settings, 'variant' => 'primary']]]];
        }

        $counting = $this->reports->counting();
        // Short at a glance (1.2K), the whole number on hover.
        $count = [...($counting ? ['key' => 'views', 'label' => __('Views')] : ['key' => 'visitors', 'label' => __('Visitors')]), 'width' => '76px', 'align' => 'end', 'format' => 'compact'];
        $share = ['key' => 'share', 'label' => __('Share'), 'width' => '112px', 'format' => 'share'];
        // The name gives way first, so the bar and the number stay in a half-width list.
        $name = ['width' => 'minmax(120px,1fr)'];
        $pages = fn (string $dimension, string $title) => $this->list($title, $dimension, [
            ['key' => 'label', 'label' => __('Page'), 'muted' => 'path', ...$name],
            $share,
            $count,
        ], ['label' => __('Open'), 'href' => '{row.url}']);

        return [
            ...($this->reports->enabled() ? [] : [['type' => 'ui/notice', 'props' => ['tone' => 'neutral', 'title' => __('Counting visits is paused.'), 'action' => [...$settings, 'label' => __('Settings')]]]]),
            ['type' => 'analytics/overview'],
            ['type' => 'ui/grid', 'props' => ['columns' => 2], 'children' => $counting ? [
                $pages('pages', __('Top Pages')),
                $this->list(__('Languages'), 'languages', [['key' => 'label', 'label' => __('Language'), ...$name], $share, $count]),
            ] : [
                $pages('pages', __('Top Pages')),
                $this->list(__('Sources'), 'sources', [['key' => 'label', 'label' => __('Source'), 'labels' => ['Direct' => __('Direct')], ...$name], $share, $count]),
                $pages('entries', __('Entry Pages')),
                $this->list(__('Devices'), 'devices', [
                    ['key' => 'label', 'label' => __('Device'), 'icon' => 'icon', ...$name, 'labels' => ['desktop' => __('Desktop'), 'mobile' => __('Mobile'), 'tablet' => __('Tablet'), 'Other' => __('Other')]],
                    $share,
                ], filters: [['param' => 'dimension', 'label' => __('Show'), 'all' => __('Type'), 'options' => self::options(['browsers' => __('Browser'), 'os' => __('System')])]]),
            ]],
            ...($counting ? [
                ['type' => 'ui/text', 'props' => ['text' => __('Counting page views only: nothing is read from the browser or kept about visitors.'), 'muted' => true]],
                ['type' => 'ui/actions', 'can' => 'settings.manage', 'props' => ['items' => [[...$settings, 'label' => __('Change what is counted'), 'variant' => 'ghost']]]],
            ] : []),
        ];
    }

    /** @param array<string, string> $labels value => words */
    private static function options(array $labels): array
    {
        return array_map(static fn ($value, $label) => ['value' => $value, 'label' => $label], array_keys($labels), $labels);
    }

    /**
     * One breakdown for the period on view, most first: few enough to read at a glance, with nothing to search.
     * The period is the address's (?period=), which the overview above keeps to one it knows.
     */
    private function list(string $title, string $dimension, array $columns, ?array $open = null, array $filters = []): array
    {
        return ['type' => 'ui/section', 'props' => ['title' => $title], 'children' => [
            ['type' => 'ui/records', 'props' => [
                'id' => "analytics.{$dimension}", 'label' => $title, 'key' => 'key',
                'source' => ['ability' => 'analytics/breakdown', 'input' => ['period' => '{query.period}', 'dimension' => $dimension, 'limit' => 8]],
                'columns' => $columns,
                'search' => false, 'display' => false,
                ...($open ? ['open' => $open] : []),
                ...($filters ? ['filters' => $filters] : []),
                'empty' => ['icon' => 'chart', 'title' => __('No visits in this period'), 'size' => 'sm'],
            ]],
        ]];
    }
}
