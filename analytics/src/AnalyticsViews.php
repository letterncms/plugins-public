<?php

declare(strict_types=1);

namespace Cms\Analytics;

use Cms\Cms;

/**
 * The Analytics place, as data (see Cms\Admin\Views): a tab per report, each drawn by the plugin. Overview
 * (the numbers, the chart, and where visits come from, go and convert), Journeys (funnels and paths;
 * visitors mode), Search (Google Search, AI assistants and page speed), and the settings' own tab.
 * The period and the filters are in the address (?period=, ?f=), the same on every tab.
 */
final class AnalyticsViews
{
    public function __construct(private readonly Cms $cms, private readonly Reports $reports) {}

    public function register(): void
    {
        $components = [
            'analytics/overview' => ['Analytics overview', 'The period’s headline numbers against the period before, one of them over time, and where visits come from, go and convert. Every row narrows the report to it.'],
            'analytics/journeys' => ['Analytics journeys', 'Funnels from the settings step by step, and the paths visits take through the site.'],
            'analytics/search' => ['Analytics search', 'Google Search from Search Console, the visitors AI assistants send, and how fast the pages are for visitors.'],
        ];

        foreach ($components as $name => [$label, $description]) {
            $this->cms->admin()->component($name, ['label' => $label, 'description' => $description, 'surface' => false, 'permission' => 'analytics.view',
                'props' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false]]);
        }

        $this->cms->views()->register('analytics', '/analytics', fn () => $this->view(), permission: 'analytics.view');
    }

    private function view(): array
    {
        $tab = static fn (string $id, string $label, array $children) => ['type' => 'ui/tab', 'props' => ['id' => $id, 'label' => $label], 'children' => [['type' => 'ui/stack', 'children' => $children]]];
        $counting = $this->reports->counting();

        return ['type' => 'ui/page', 'props' => ['title' => __('Analytics'), 'icon' => 'chart', 'width' => 'wide'], 'children' => [
            ['type' => 'ui/tabs', 'children' => [
                $tab('overview', __('Overview'), $this->overview()),
                ...($counting ? [] : [$tab('journeys', __('Journeys'), [['type' => 'analytics/journeys']])]),
                $tab('search', __('Search||results'), [['type' => 'analytics/search']]),
            ]],
        ]];
    }

    private function overview(): array
    {
        $settings = ['label' => __('Open Settings'), 'to' => '/analytics?tab=settings', 'can' => 'settings.manage'];

        if (! $this->reports->tracked()) {
            return $this->reports->enabled()
                ? [['type' => 'ui/empty', 'props' => ['icon' => 'chart', 'title' => __('No visits yet')]]]
                : [['type' => 'ui/empty', 'props' => ['icon' => 'chart', 'title' => __('Analytics is off'), 'action' => [...$settings, 'variant' => 'primary']]]];
        }

        return [
            ...($this->reports->enabled() ? [] : [['type' => 'ui/notice', 'props' => ['tone' => 'neutral', 'title' => __('Counting visits is paused.'), 'action' => [...$settings, 'label' => __('Settings')]]]]),
            ['type' => 'analytics/overview'],
        ];
    }
}
