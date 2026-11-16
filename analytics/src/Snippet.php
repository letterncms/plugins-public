<?php

declare(strict_types=1);

namespace Cms\Analytics;

/**
 * What a published page carries.
 *
 * Counts mode: one image, with the path baked in at build. No script, nothing read from the browser, and
 * no referrer sent.
 *
 * Visitors mode: the script in `resources/tracker.js`, the same on every page: what differs (where it
 * reports, whether it waits for consent, whether the page is the one for a missing address) is in the
 * element's attributes, so the site's content security policy names one hash for it. Visitors who ask
 * not to be tracked (Do Not Track, Global Privacy Control) and automated browsers are skipped, and by
 * default it waits for a consent signal: `window.cmsConsent.analytics === true`, or a `cms:consent` event
 * with `detail.analytics` true. A built page has one copy for everyone, so the decision comes from the browser.
 */
final class Snippet
{
    private static ?string $script = null;

    public static function image(string $endpoint, string $path): string
    {
        return '<img src="'.e($endpoint.'?p='.rawurlencode($path)).'" alt="" width="1" height="1" aria-hidden="true" decoding="async" fetchpriority="low" referrerpolicy="no-referrer" style="position:absolute;opacity:0">';
    }

    public static function html(string $endpoint, bool $consent = true, bool $automatic = true, bool $missing = false): string
    {
        $attributes = 'data-endpoint="'.e($endpoint).'"'.($consent ? ' data-consent="1"' : '').($automatic ? ' data-auto="1"' : '').($missing ? ' data-status="404"' : '');

        return '<script '.$attributes.'>'.self::script().'</script>';
    }

    /** The script without its comments and indentation: one statement a line, as written. */
    public static function script(): string
    {
        return self::$script ??= implode("\n", array_filter(
            array_map('trim', file(dirname(__DIR__).'/resources/tracker.js', FILE_IGNORE_NEW_LINES) ?: []),
            static fn (string $line) => $line !== '' && ! str_starts_with($line, '//'),
        ));
    }
}
