<?php

declare(strict_types=1);

namespace Cms\Analytics;

use Cms\Abilities\AbilityRegistry;
use Cms\Support\Schema\Shape;

/**
 * Everything Analytics knows, as abilities: so the admin, agents (MCP), the REST API, the CLI and other
 * plugins read the same numbers. Each takes a period (or two dates) and filters, and answers with
 * aggregates only: nothing about one visitor is ever answered.
 */
final class AnalyticsAbilities
{
    public function __construct(
        private readonly Reports $reports,
        private readonly Journeys $journeys,
        private readonly Vitals $vitals,
        private readonly SearchConsole $search,
        private readonly Goals $goals,
        private readonly Collector $collector,
    ) {}

    public function register(AbilityRegistry $a): void
    {
        $filters = ['filters' => ['type' => 'array', 'maxItems' => 10, 'default' => [], 'description' => 'All must hold. page is visits that viewed it (and, for page numbers, those views); goal is visits that reached it (a goal page or an event by its name); the rest are of the visit. * in a page stands for anything; value null is none (source null is Direct).', 'items' => [
            'type' => 'object', 'required' => ['dimension', 'value'], 'additionalProperties' => false, 'properties' => [
                'dimension' => ['type' => 'string', 'enum' => array_keys(Filters::DIMENSIONS)],
                'op' => ['type' => 'string', 'enum' => Filters::OPERATORS, 'default' => 'is'],
                'value' => ['type' => ['string', 'null'], 'maxLength' => 400],
            ],
        ]]];
        $input = static fn (array $properties, array $required = []) => ['type' => 'object', 'properties' => $properties, ...($required ? ['required' => $required] : [])];

        // What they answer with. What counts mode cannot know is null, never zero.
        $metrics = [
            'visitors' => Shape::INTEGER_OR_NULL, 'visits' => Shape::INTEGER_OR_NULL, 'views' => Shape::INTEGER_OR_NULL, 'viewsPerVisit' => Shape::NUMBER_OR_NULL,
            'bounceRate' => Shape::NUMBER_OR_NULL, 'visitDuration' => Shape::INTEGER_OR_NULL, 'timeOnPage' => Shape::NUMBER_OR_NULL, 'scrollDepth' => Shape::INTEGER_OR_NULL,
            'conversions' => Shape::INTEGER_OR_NULL, 'conversionRate' => Shape::NUMBER_OR_NULL,
        ];
        $filtersOut = [['dimension' => Shape::enum(...array_keys(Filters::DIMENSIONS)), 'op' => Shape::enum(...Filters::OPERATORS), 'value' => Shape::STRING_OR_NULL]];
        $entry = Shape::nullable(['id' => Shape::STRING, 'collection' => Shape::STRING]);
        $period = Shape::enum(...Range::PERIODS);
        $common = $this->rangeInput();

        $a->register('analytics/summary', [
            'label' => 'Analytics Summary',
            'description' => 'The headline numbers of the published site for a period: visitors, visits, page views, views per visit, bounce rate, visit duration (seconds in front of the visitor), time on page (seconds) and scroll depth (percent), the same for the period before, and a row per step (minute, hour, day, week or month by the period\'s length; `date` is the step: 2026-11-17 14:05, 2026-11-17 14:00, 2026-11-17, a week\'s Monday, 2026-11). With a goal filter also conversions and the conversion rate. `current` is how many people are on the site now. In counts mode (the default, see `mode`) only page views are known, by page; the rest are null, and anything narrower answers `unavailable: mode`.',
            'permission' => 'analytics.view',
            'annotations' => ['readOnly' => true],
            'input' => $input([...$common, ...$filters, 'compare' => ['type' => 'boolean', 'default' => false, 'description' => 'Also previousSeries: the period before, step by step.']]),
            'output' => Shape::of([
                'period' => $period, 'from' => Shape::STRING, 'to' => Shape::STRING, 'interval' => Shape::enum(...Range::INTERVALS), 'mode' => Shape::enum(Collector::COUNTS, Collector::VISITORS), 'filters' => $filtersOut,
                ...$metrics, 'previous' => $metrics, 'series' => [['date' => Shape::STRING, ...$metrics]], 'previousSeries?' => [['date' => Shape::STRING, ...$metrics]],
                'current' => Shape::INTEGER_OR_NULL, 'enabled' => Shape::BOOLEAN, 'tracked' => Shape::BOOLEAN, 'unavailable?' => Shape::enum('mode'),
            ]),
            'handler' => fn (array $in) => $this->reports->summary($this->range($in), Filters::of($in['filters'] ?? []), (bool) $in['compare']),
        ]);

        $a->register('analytics/breakdown', [
            'label' => 'Analytics Breakdown',
            'description' => 'The visitors per value of a dimension for a period and filters, most first, each with its share of all visitors (0 to 1). pages: also views, time on page and scroll depth; entries and exits: the pages visits started and ended on; channels (direct, search, ai, social, email, paid, referral, campaign); sources (the referring site or a link\'s utm_source; key null is Direct); campaigns (utm_campaign); countries (ISO codes, from the visitor\'s time zone); devices, browsers, os: also visits, bounce rate and visit duration; languages: views per language; goals: every event by its name (Outbound Link, File Download, Form Sent, Page Not Found and the site\'s own) and every goal page, with visitors, how often and the conversion rate; props: the properties of the events of the goal filtered on. In counts mode only pages, languages and goals answer; the rest say `unavailable: mode`.',
            'permission' => 'analytics.view',
            'annotations' => ['readOnly' => true],
            'input' => $input([...$common, ...$filters,
                'dimension' => ['type' => 'string', 'enum' => Reports::DIMENSIONS],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10],
            ], ['dimension']),
            'output' => Shape::of([
                'dimension' => Shape::enum(...Reports::DIMENSIONS), 'total' => Shape::INTEGER,
                'items' => [[
                    'key' => Shape::STRING_OR_NULL, 'label' => Shape::STRING, 'visitors' => Shape::INTEGER_OR_NULL, 'share' => Shape::NUMBER,
                    'views?' => Shape::INTEGER, 'visits?' => Shape::INTEGER, 'bounceRate?' => Shape::NUMBER, 'visitDuration?' => Shape::INTEGER,
                    'timeOnPage?' => Shape::NUMBER_OR_NULL, 'scrollDepth?' => Shape::INTEGER_OR_NULL,
                    'events?' => Shape::INTEGER, 'conversionRate?' => Shape::NUMBER_OR_NULL, 'kind?' => Shape::enum('event', 'page'), 'prop?' => Shape::STRING,
                    'path?' => Shape::STRING, 'url?' => Shape::STRING, 'icon?' => Shape::STRING, 'entry?' => $entry,
                ]],
                'unavailable?' => Shape::enum('mode', 'goal'),
            ]),
            'handler' => fn (array $in) => $this->reports->breakdown($this->range($in), Filters::of($in['filters'] ?? []), $in['dimension'], $in['limit']),
        ]);

        $a->register('analytics/funnel', [
            'label' => 'Analytics Funnel',
            'description' => 'How many visitors took each step of a funnel, in order, within one visit: a funnel of the settings by its name, or steps given here, each a page (starting with /, * for anything) or a goal\'s name. Each step has its visitors, its share of the first step\'s and how many of the step before dropped off (0 to 1). Visitors mode.',
            'permission' => 'analytics.view',
            'annotations' => ['readOnly' => true],
            'input' => $input([...$common, ...$filters,
                'funnel' => ['type' => 'string', 'description' => 'A funnel of the settings, by its name.'],
                'steps' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 400], 'minItems' => 2, 'maxItems' => 8],
            ]),
            'output' => Shape::of([
                'name' => Shape::STRING_OR_NULL, 'conversionRate' => Shape::NUMBER,
                'steps' => [['label' => Shape::STRING, 'page' => Shape::STRING_OR_NULL, 'visitors' => Shape::INTEGER, 'share' => Shape::NUMBER, 'dropoff' => Shape::NUMBER]],
            ]),
            'handler' => fn (array $in) => $this->journeys->funnel($this->range($in), Filters::of($in['filters'] ?? []), $in['funnel'] ?? null, $in['steps'] ?? []),
        ]);

        $a->register('analytics/paths', [
            'label' => 'Analytics Paths',
            'description' => 'The ways visits go through the site: the sequences of pages visits of two pages or more start with most (`length` pages at most), or, given a `page`, where its visitors were just before it and where they went next (path null: they came from outside, or left). Visitors mode.',
            'permission' => 'analytics.view',
            'annotations' => ['readOnly' => true],
            'input' => $input([...$common, ...$filters,
                'page' => ['type' => 'string', 'maxLength' => 400],
                'length' => ['type' => 'integer', 'minimum' => 2, 'maximum' => 5, 'default' => 3],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
            ]),
            'output' => Shape::of([
                'page' => Shape::STRING_OR_NULL,
                'paths?' => [['pages' => [['path' => Shape::STRING, 'label' => Shape::STRING]], 'visitors' => Shape::INTEGER]],
                'before?' => [['key' => Shape::STRING_OR_NULL, 'label' => Shape::STRING, 'path' => Shape::STRING_OR_NULL, 'entry?' => $entry, 'visitors' => Shape::INTEGER]],
                'after?' => [['key' => Shape::STRING_OR_NULL, 'label' => Shape::STRING, 'path' => Shape::STRING_OR_NULL, 'entry?' => $entry, 'visitors' => Shape::INTEGER]],
            ]),
            'handler' => fn (array $in) => $this->journeys->paths($this->range($in), Filters::of($in['filters'] ?? []), $in['length'], $in['limit'], $in['page'] ?? null),
        ]);

        $vital = ['lcp' => Shape::INTEGER_OR_NULL, 'lcpRating' => Shape::enum('good', 'needs-improvement', 'poor', null), 'inp' => Shape::INTEGER_OR_NULL, 'inpRating' => Shape::enum('good', 'needs-improvement', 'poor', null),
            'cls' => Shape::NUMBER_OR_NULL, 'clsRating' => Shape::enum('good', 'needs-improvement', 'poor', null), 'rating' => Shape::enum('good', 'needs-improvement', 'poor', null), 'samples' => Shape::INTEGER];

        $a->register('analytics/vitals', [
            'label' => 'Page Speed',
            'description' => 'How fast the site is for its visitors, measured in their browsers: the Core Web Vitals Google ranks by, each the 75th percentile with Google\'s rating. lcp (Largest Contentful Paint, milliseconds; good up to 2500, poor over 4000), inp (Interaction to Next Paint, milliseconds; 200 and 500), cls (Cumulative Layout Shift; 0.1 and 0.25). For the site and per page, the pages that need it most first. `rating` is the worst of the three, once there are 10 measurements. Visitors mode.',
            'permission' => 'analytics.view',
            'annotations' => ['readOnly' => true],
            'input' => $input([...$common, ...$filters, 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10]]),
            'output' => Shape::of([
                'site' => $vital,
                'pages' => [['key' => Shape::STRING, 'label' => Shape::STRING, 'path' => Shape::STRING, 'entry' => $entry, ...$vital]],
            ]),
            'handler' => fn (array $in) => $this->vitals->report($this->range($in), Filters::of($in['filters'] ?? []), $in['limit']),
        ]);

        $search = ['clicks' => Shape::INTEGER, 'impressions' => Shape::INTEGER, 'ctr' => Shape::NUMBER, 'position' => Shape::NUMBER_OR_NULL];

        $a->register('analytics/search', [
            'label' => 'Search Console',
            'description' => 'What Google Search says about the site, from Google Search Console: report summary (clicks, impressions, click-through rate 0 to 1 and average position, for the period and the one before, a row per step), queries, pages, countries or devices (most clicked first), opportunities (searches the site shows up for at positions 4 to 20, most shown first: kind top-ten or page-two), or status (whether it is connected; problem: key, access or unreachable). Narrow to one page (a path) or one query. Google is a day or two behind; answers are kept for an hour.',
            'permission' => 'analytics.view',
            'annotations' => ['readOnly' => true],
            'input' => $input([...$common,
                'report' => ['type' => 'string', 'enum' => ['status', 'summary', ...array_keys(SearchConsole::DIMENSIONS), 'opportunities'], 'default' => 'summary'],
                'page' => ['type' => 'string', 'maxLength' => 400], 'query' => ['type' => 'string', 'maxLength' => 400],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10],
            ]),
            'output' => Shape::either(
                ['connected' => Shape::BOOLEAN, 'property' => Shape::STRING_OR_NULL, 'problem' => Shape::enum('key', 'access', 'unreachable', null)],
                ['period' => $period, 'from' => Shape::STRING, 'to' => Shape::STRING, 'interval' => Shape::enum('day', 'week', 'month'), 'property' => Shape::STRING, ...$search, 'previous' => $search, 'series' => [['date' => Shape::STRING, ...$search]]],
                ['dimension' => Shape::enum(...array_keys(SearchConsole::DIMENSIONS)), 'items' => [['key' => Shape::STRING, 'label' => Shape::STRING, 'path?' => Shape::STRING, 'url?' => Shape::STRING, ...$search]]],
                ['items' => [['key' => Shape::STRING, 'label' => Shape::STRING, 'url' => Shape::STRING, 'path' => Shape::STRING_OR_NULL, 'page' => Shape::STRING, 'kind' => Shape::enum('top-ten', 'page-two'), ...$search]]],
            ),
            'handler' => function (array $in) {
                $range = $this->range($in);
                $page = isset($in['page']) ? Collector::path($in['page']) : null;

                return match ($in['report']) {
                    'status' => $this->search->status(),
                    'summary' => $this->search->summary($range, $page, $in['query'] ?? null),
                    'opportunities' => $this->search->opportunities($range, $in['limit']),
                    default => $this->search->breakdown($range, $in['report'], $page, $in['query'] ?? null, $in['limit']),
                };
            },
        ]);

        $a->register('analytics/goals', [
            'label' => 'Analytics Goals',
            'description' => 'What counts as a goal and the funnels: the goal pages of the settings (name and page, * for anything) and the funnels (name and steps). Every event is a goal by its name as well: analytics/breakdown with dimension goals lists them. Change them with the settings of the analytics namespace (goals, funnels).',
            'permission' => 'analytics.view',
            'annotations' => ['readOnly' => true],
            'input' => $input([]),
            'output' => Shape::of(['pages' => [['name' => Shape::STRING, 'path' => Shape::STRING]], 'funnels' => [['name' => Shape::STRING, 'steps' => [Shape::STRING]]], 'events' => [Shape::STRING]]),
            'handler' => fn () => [
                'pages' => array_map(static fn ($name, $path) => ['name' => (string) $name, 'path' => $path], array_keys($this->goals->pages()), $this->goals->pages()),
                'funnels' => array_map(static fn ($name, $steps) => ['name' => (string) $name, 'steps' => $steps], array_keys($this->goals->funnels()), $this->goals->funnels()),
                'events' => [Collector::OUTBOUND, Collector::DOWNLOAD, Collector::FORM, Collector::NOT_FOUND],
            ],
        ]);

        $a->register('analytics/track', [
            'label' => 'Record Analytics Event',
            'description' => 'Count an event that happened outside the published pages, such as a sale or a sign-up confirmed by email: a name (it is a goal by that name), up to 10 properties of short text, and the page it belongs to. It counts in both modes but belongs to no visit, so it adds to how often a goal was reached, not to its visitors.',
            'permission' => 'analytics.track',
            'input' => $input([
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'props' => ['type' => 'object', 'maxProperties' => 10, 'additionalProperties' => ['type' => ['string', 'number', 'boolean']]],
                'path' => ['type' => 'string', 'maxLength' => 400],
            ], ['name']),
            'output' => Shape::of(['recorded' => Shape::BOOLEAN]),
            'handler' => fn (array $in) => ['recorded' => $this->collector->track($in['name'], (array) ($in['props'] ?? []), $in['path'] ?? null)],
        ]);
    }

    private function rangeInput(): array
    {
        return [
            'period' => ['type' => 'string', 'enum' => Range::PERIODS, 'default' => '30d', 'description' => 'realtime is the last 30 minutes; today and yesterday go by the hour; 7d, 30d and 90d are days ending today, today included; 12mo is this month and the 11 before; custom takes from and to.'],
            'from' => ['type' => 'string', 'format' => 'date', 'description' => 'With period custom: the first day, such as 2026-10-01.'],
            'to' => ['type' => 'string', 'format' => 'date', 'description' => 'With period custom: the last day, today at the latest.'],
        ];
    }

    private function range(array $in): Range
    {
        return Range::of($in['period'] ?? '30d', $in['from'] ?? null, $in['to'] ?? null);
    }
}
