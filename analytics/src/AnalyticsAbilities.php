<?php

declare(strict_types=1);

namespace Cms\Analytics;

use Cms\Abilities\AbilityRegistry;
use Cms\Support\Schema\Shape;

/** Reading the numbers back: the summary and the breakdowns. */
final class AnalyticsAbilities
{
    public function __construct(private readonly Reports $reports) {}

    public function register(AbilityRegistry $a): void
    {
        $period = ['type' => 'string', 'enum' => array_keys(Reports::PERIODS), 'default' => '30d', 'description' => 'The last 7, 30 or 90 days, today included.'];

        // What they answer with. What counts mode cannot know is null, never zero.
        $metrics = ['visitors' => Shape::INTEGER_OR_NULL, 'views' => Shape::INTEGER, 'viewsPerVisit' => Shape::NUMBER_OR_NULL, 'bounceRate' => Shape::NUMBER_OR_NULL];

        $a->register('analytics/summary', [
            'label' => 'Analytics Summary',
            'description' => 'Page views for a period, the same number for the period before (to compare) and one row per day. In visitors mode also visitors, views per visit, bounce rate and how many people are on the site now; in counts mode (the default, see `mode`) those are null.',
            'permission' => 'analytics.view',
            'annotations' => ['readOnly' => true],
            'input' => ['type' => 'object', 'properties' => ['period' => $period]],
            'output' => Shape::of([
                'period' => Shape::enum(...array_keys(Reports::PERIODS)), 'from' => Shape::STRING, 'to' => Shape::STRING, 'mode' => Shape::enum(Collector::COUNTS, Collector::VISITORS),
                ...$metrics, 'previous' => $metrics, 'series' => [['date' => Shape::STRING, ...$metrics]],
                'current' => Shape::INTEGER_OR_NULL, 'enabled' => Shape::BOOLEAN, 'tracked' => Shape::BOOLEAN,
            ]),
            'handler' => fn (array $in) => $this->reports->summary($in['period']),
        ]);

        $a->register('analytics/breakdown', [
            'label' => 'Analytics Breakdown',
            'description' => 'Views per page (with the entry it shows) or language for a period, most first. In visitors mode also visitors per page, entry page (where visits started), source (referring site or campaign; "Direct" when none), device, browser or operating system; in counts mode (the default) those answer {unavailable: "mode"}. Each item has its share of the whole, from 0 to 1.',
            'permission' => 'analytics.view',
            'annotations' => ['readOnly' => true],
            'input' => ['type' => 'object', 'properties' => [
                'period' => $period,
                'dimension' => ['type' => 'string', 'enum' => Reports::DIMENSIONS],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10],
            ], 'required' => ['dimension']],
            'output' => Shape::of([
                'dimension' => Shape::enum(...Reports::DIMENSIONS), 'total' => Shape::INTEGER,
                'items' => [[
                    'key' => Shape::STRING_OR_NULL, 'label' => Shape::STRING, 'visitors' => Shape::INTEGER_OR_NULL, 'share' => Shape::NUMBER,
                    'views?' => Shape::INTEGER, 'path?' => Shape::STRING, 'url?' => Shape::STRING, 'icon?' => Shape::STRING,
                    'entry?' => Shape::nullable(['id' => Shape::STRING, 'collection' => Shape::STRING]),
                ]],
                'unavailable?' => Shape::enum('mode'),
            ]),
            'handler' => fn (array $in) => $this->reports->breakdown($in['period'], $in['dimension'], $in['limit']),
        ]);
    }
}
