<?php

declare(strict_types=1);

namespace Cms\Analytics;

/** Where a visit came from: a campaign's `utm_source`/`ref`, else the referring site, by name where it is well known. */
final class Sources
{
    /** Host pattern => name. */
    private const array KNOWN = [
        '/(^|\.)google\./' => 'Google',
        '/(^|\.)bing\.com$/' => 'Bing',
        '/(^|\.)duckduckgo\.com$/' => 'DuckDuckGo',
        '/(^|\.)ecosia\.org$/' => 'Ecosia',
        '/(^|\.)search\.brave\.com$/' => 'Brave Search',
        '/(^|\.)yahoo\./' => 'Yahoo',
        '/(^|\.)(chatgpt\.com|openai\.com)$/' => 'ChatGPT',
        '/(^|\.)perplexity\.ai$/' => 'Perplexity',
        '/(^|\.)claude\.ai$/' => 'Claude',
        '/(^|\.)(t\.co|twitter\.com|x\.com)$/' => 'X',
        '/(^|\.)(facebook\.com|fb\.com|fb\.me)$/' => 'Facebook',
        '/(^|\.)instagram\.com$/' => 'Instagram',
        '/(^|\.)(linkedin\.com|lnkd\.in)$/' => 'LinkedIn',
        '/(^|\.)reddit\.com$/' => 'Reddit',
        '/(^|\.)news\.ycombinator\.com$/' => 'Hacker News',
        '/(^|\.)(youtube\.com|youtu\.be)$/' => 'YouTube',
        '/(^|\.)github\.com$/' => 'GitHub',
        '/(^|\.)bsky\.app$/' => 'Bluesky',
        '/(^|\.)(mastodon\.social|mastodon\.online)$/' => 'Mastodon',
    ];

    /**
     * @param  list<string>  $ownHosts  referrers from these hosts are navigation within the site
     */
    public static function resolve(?string $referrer, ?string $campaign, array $ownHosts): ?string
    {
        $campaign = trim((string) $campaign);

        if ($campaign !== '') {
            return mb_substr(preg_replace('/[^\p{L}\p{N} ._-]+/u', '', $campaign) ?: '', 0, 60) ?: null;
        }

        $host = strtolower((string) parse_url((string) $referrer, PHP_URL_HOST));
        $host = preg_replace('/^(www|m)\./', '', $host);

        if ($host === '' || in_array($host, array_map(static fn ($h) => preg_replace('/^www\./', '', strtolower($h)), $ownHosts), true)) {
            return null;
        }

        foreach (self::KNOWN as $pattern => $name) {
            if (preg_match($pattern, $host)) {
                return $name;
            }
        }

        return mb_substr($host, 0, 120);
    }
}
