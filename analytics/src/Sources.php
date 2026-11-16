<?php

declare(strict_types=1);

namespace Cms\Analytics;

/**
 * Where a visit came from: its source (the referring site, by name where it is well known, or a link's
 * `utm_source`/`ref`) and the channel that source belongs to. A campaign's medium decides the channel
 * when it says one (`utm_medium=email`); otherwise the source does.
 */
final class Sources
{
    public const string DIRECT = 'direct';

    public const string SEARCH = 'search';

    public const string AI = 'ai';

    public const string SOCIAL = 'social';

    public const string EMAIL = 'email';

    public const string PAID = 'paid';

    public const string REFERRAL = 'referral';

    public const string CAMPAIGN = 'campaign';

    public const array CHANNELS = [self::DIRECT, self::SEARCH, self::AI, self::SOCIAL, self::EMAIL, self::PAID, self::REFERRAL, self::CAMPAIGN];

    /** Host pattern => [name, channel]. A link's `utm_source` is matched as a host too: ChatGPT sends `chatgpt.com`. */
    private const array KNOWN = [
        // Webmail, and Gemini, first: they are hosts of Google and Yahoo too.
        '/^(mail\.google\.com|outlook\.live\.com|outlook\.office\.com|outlook\.office365\.com|mail\.yahoo\.com|mail\.proton\.me|app\.fastmail\.com)$/' => ['Email', self::EMAIL],
        '/^(gemini|bard)\.google\.com$|^gemini$/' => ['Gemini', self::AI],
        // Search engines.
        '/(^|\.)google\.[a-z.]+$|^google$/' => ['Google', self::SEARCH],
        '/(^|\.)bing\.com$|^bing$/' => ['Bing', self::SEARCH],
        '/(^|\.)duckduckgo\.com$|^duckduckgo$/' => ['DuckDuckGo', self::SEARCH],
        '/(^|\.)ecosia\.org$|^ecosia$/' => ['Ecosia', self::SEARCH],
        '/(^|\.)search\.brave\.com$/' => ['Brave Search', self::SEARCH],
        '/(^|\.)search\.yahoo\.|(^|\.)yahoo\.[a-z.]+$|^yahoo$/' => ['Yahoo', self::SEARCH],
        '/(^|\.)yandex\.[a-z.]+$|^yandex$/' => ['Yandex', self::SEARCH],
        '/(^|\.)baidu\.com$|^baidu$/' => ['Baidu', self::SEARCH],
        '/(^|\.)qwant\.com$|^qwant$/' => ['Qwant', self::SEARCH],
        '/(^|\.)startpage\.com$|^startpage$/' => ['Startpage', self::SEARCH],
        '/(^|\.)kagi\.com$|^kagi$/' => ['Kagi', self::SEARCH],
        '/(^|\.)naver\.com$|^naver$/' => ['Naver', self::SEARCH],
        '/(^|\.)seznam\.cz$|^seznam$/' => ['Seznam', self::SEARCH],
        // AI assistants: answers that link to the site.
        '/(^|\.)(chatgpt\.com|chat\.openai\.com|openai\.com)$|^chatgpt$|^openai$/' => ['ChatGPT', self::AI],
        '/(^|\.)perplexity\.ai$|^perplexity$/' => ['Perplexity', self::AI],
        '/(^|\.)claude\.ai$|^claude$/' => ['Claude', self::AI],
        '/^copilot\.microsoft\.com$|^copilot\.cloud\.microsoft$|^m365copilot\.com$|^copilot$/' => ['Copilot', self::AI],
        '/(^|\.)deepseek\.com$|^deepseek$/' => ['DeepSeek', self::AI],
        '/(^|\.)chat\.mistral\.ai$|^mistral$/' => ['Le Chat', self::AI],
        '/(^|\.)meta\.ai$/' => ['Meta AI', self::AI],
        '/(^|\.)grok\.com$|^grok$/' => ['Grok', self::AI],
        '/(^|\.)you\.com$/' => ['You.com', self::AI],
        '/(^|\.)phind\.com$/' => ['Phind', self::AI],
        '/(^|\.)poe\.com$/' => ['Poe', self::AI],
        '/^duck\.ai$/' => ['Duck.ai', self::AI],
        // Social networks and video.
        '/(^|\.)(t\.co|twitter\.com|x\.com)$|^twitter$|^x$/' => ['X', self::SOCIAL],
        '/(^|\.)(facebook\.com|fb\.com|fb\.me|m\.facebook\.com)$|^facebook$|^fb$/' => ['Facebook', self::SOCIAL],
        '/(^|\.)instagram\.com$|^instagram$|^ig$/' => ['Instagram', self::SOCIAL],
        '/(^|\.)threads\.(net|com)$|^threads$/' => ['Threads', self::SOCIAL],
        '/(^|\.)(linkedin\.com|lnkd\.in)$|^linkedin$/' => ['LinkedIn', self::SOCIAL],
        '/(^|\.)reddit\.com$|^reddit$/' => ['Reddit', self::SOCIAL],
        '/(^|\.)news\.ycombinator\.com$|^hacker[ _-]?news$|^hn$/' => ['Hacker News', self::SOCIAL],
        '/(^|\.)(youtube\.com|youtu\.be)$|^youtube$/' => ['YouTube', self::SOCIAL],
        '/(^|\.)tiktok\.com$|^tiktok$/' => ['TikTok', self::SOCIAL],
        '/(^|\.)pinterest\.[a-z.]+$|^pinterest$/' => ['Pinterest', self::SOCIAL],
        '/(^|\.)bsky\.app$|^bluesky$/' => ['Bluesky', self::SOCIAL],
        '/(^|\.)(mastodon\.social|mastodon\.online|mstdn\.social)$|^mastodon$/' => ['Mastodon', self::SOCIAL],
        '/(^|\.)github\.com$|^github$/' => ['GitHub', self::REFERRAL],
    ];

    /**
     * @param  array{source?: ?string, medium?: ?string, campaign?: ?string, paid?: bool}  $link  what the page's address said
     * @param  list<string>  $ownHosts  referrers from these hosts are navigation within the site
     * @return array{source: ?string, channel: string, medium: ?string, campaign: ?string}
     */
    public static function resolve(?string $referrer, array $link, array $ownHosts): array
    {
        $medium = self::clean($link['medium'] ?? null, 60);
        $campaign = self::clean($link['campaign'] ?? null, 100);
        $tagged = self::clean($link['source'] ?? null, 120);

        $host = strtolower((string) parse_url((string) $referrer, PHP_URL_HOST));
        $host = (string) preg_replace('/^(www|m)\./', '', $host);
        $own = array_map(static fn ($h) => (string) preg_replace('/^www\./', '', strtolower($h)), $ownHosts);
        $host = in_array($host, $own, true) ? '' : $host;

        [$name, $channel] = $tagged !== null ? self::known(strtolower($tagged)) ?? [$tagged, null] : ($host !== '' ? self::known($host) ?? [mb_substr($host, 0, 120), self::REFERRAL] : [null, null]);
        $channel = self::byMedium($medium) ?? (($link['paid'] ?? false) ? self::PAID : null) ?? $channel ?? ($tagged !== null || $campaign !== null ? self::CAMPAIGN : self::DIRECT);

        return ['source' => $name, 'channel' => $channel, 'medium' => $medium, 'campaign' => $campaign];
    }

    /** @return array{0: string, 1: string}|null */
    private static function known(string $host): ?array
    {
        foreach (self::KNOWN as $pattern => $known) {
            if (preg_match($pattern, $host)) {
                return $known;
            }
        }

        return null;
    }

    private static function byMedium(?string $medium): ?string
    {
        $medium = strtolower((string) $medium);

        return match (true) {
            $medium === '' => null,
            (bool) preg_match('/^(cpc|ppc|cpm|cpv|cpa|paid.*|.*ads?|display|banner|retargeting|affiliate)$/', $medium) => self::PAID,
            (bool) preg_match('/^(e-?mail|newsletter|mail)$/', $medium) => self::EMAIL,
            (bool) preg_match('/^(social|social-network|social-media|sm|organic-social)$/', $medium) => self::SOCIAL,
            (bool) preg_match('/^(organic|search)$/', $medium) => self::SEARCH,
            (bool) preg_match('/^(ai|llm|assistant|chatbot)$/', $medium) => self::AI,
            (bool) preg_match('/^(referral|link)$/', $medium) => self::REFERRAL,
            default => null,
        };
    }

    private static function clean(?string $value, int $length): ?string
    {
        $value = trim((string) preg_replace('/[^\p{L}\p{N} .:\/_+-]+/u', '', (string) $value));

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
