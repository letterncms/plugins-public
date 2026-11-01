<?php

declare(strict_types=1);

namespace Cms\Analytics;

/**
 * What a visit tells about the visitor's software, read from the user agent and the
 * viewport width the snippet sends. Coarse on purpose: a handful of names, no versions.
 */
final readonly class Client
{
    private const string BOTS = '/bot|crawl|spider|slurp|archiver|headless|lighthouse|pagespeed|gtmetrix|preview|monitor|uptime|pingdom|curl|wget|python|httpclient|okhttp|axios|go-http|java\/|ruby|perl|php\/|facebookexternalhit|embedly|whatsapp|telegram|discord|slack|vercel|phantom|selenium|puppeteer|playwright/i';

    public function __construct(
        public string $device,
        public string $browser,
        public string $os,
    ) {}

    /** True for crawlers, monitors, link previews and scripted browsers. */
    public static function isBot(string $userAgent): bool
    {
        return trim($userAgent) === '' || preg_match(self::BOTS, $userAgent) === 1;
    }

    public static function from(string $userAgent, ?int $width = null): self
    {
        return new self(self::device($userAgent, $width), self::browser($userAgent), self::os($userAgent));
    }

    private static function device(string $ua, ?int $width): string
    {
        if ($width !== null && $width > 0) {
            return $width < 768 ? 'mobile' : ($width < 1024 ? 'tablet' : 'desktop');
        }

        return match (true) {
            (bool) preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $ua) => 'tablet',
            (bool) preg_match('/Mobi|iPhone|Android/i', $ua) => 'mobile',
            default => 'desktop',
        };
    }

    private static function browser(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/Edg(e|A|iOS)?\//', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/', $ua) => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            (bool) preg_match('/Firefox\/|FxiOS/', $ua) => 'Firefox',
            (bool) preg_match('/Chrome\/|CriOS/', $ua) => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Other',
        };
    }

    private static function os(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Other',
        };
    }
}
