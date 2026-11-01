<?php

declare(strict_types=1);

namespace Cms\Analytics;

use Cms\Abilities\AbilityRegistry;

/** Reading the numbers back: the summary and the breakdowns. */
final class AnalyticsAbilities
{
    public function __construct(private readonly Reports $reports) {}

    public function register(AbilityRegistry $a): void
    {
        $period = ['type' => 'string', 'enum' => array_keys(Reports::PERIODS), 'default' => '30d', 'description' => 'The last 7, 30 or 90 days, today included.'];

        $a->register('analytics/summary', [
            'label' => 'Analytics Summary',
            'description' => 'Page views for a period, the same number for the period before (to compare) and one row per day. In visitors mode also visitors, views per visit, bounce rate and how many people are on the site now; in counts mode (the default, see `mode`) those are null.',
            'permission' => 'analytics.view',
            'annotations' => ['readOnly' => true],
            'input' => ['type' => 'object', 'properties' => ['period' => $period]],
            'handler' => fn (array $in) => $this->reports->summary($in['period']),
        ]);

        $a->register('analytics/breakdown', [
            'label' => 'Analytics Breakdown',
            'description' => 'Views per page (with the entry it shows) or language for a period, most first. In visitors mode also visitors per page, entry page (where visits started), source (referring site or campaign; "Direct" when none), device, browser or operating system; in counts mode (the default) those answer {unavailable: "mode"}.',
            'permission' => 'analytics.view',
            'annotations' => ['readOnly' => true],
            'input' => ['type' => 'object', 'properties' => [
                'period' => $period,
                'dimension' => ['type' => 'string', 'enum' => Reports::DIMENSIONS],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10],
            ], 'required' => ['dimension']],
            'handler' => fn (array $in) => $this->reports->breakdown($in['period'], $in['dimension'], $in['limit']),
        ]);
    }
}
