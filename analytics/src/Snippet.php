<?php

declare(strict_types=1);

namespace Cms\Analytics;

/**
 * What a published page carries.
 *
 * Counts mode: one image, with the path baked in at build. No script, nothing read from
 * the browser, and no referrer sent.
 *
 * Visitors mode: a few hundred bytes of script that send the path, the referrer, a campaign
 * name and the viewport width. Visitors who ask not to be tracked (Do Not Track, Global
 * Privacy Control) and automated browsers are skipped, and by default it waits for a consent
 * signal: `window.cmsConsent.analytics === true`, or a `cms:consent` event with
 * `detail.analytics` true. A consent tool sends either; a built page has one copy for everyone,
 * so the decision has to come from the browser.
 */
final class Snippet
{
    public static function image(string $endpoint, string $path): string
    {
        return '<img src="'.e($endpoint.'?p='.rawurlencode($path)).'" alt="" width="1" height="1" aria-hidden="true" decoding="async" fetchpriority="low" referrerpolicy="no-referrer" style="position:absolute;opacity:0">';
    }

    public static function html(string $endpoint, bool $consent = true): string
    {
        $url = json_encode($endpoint, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        $start = $consent
            ? 'var c=w.cmsConsent;if(c&&c.analytics===true)go();else d.addEventListener("cms:consent",function f(e){if(e.detail&&e.detail.analytics===true){d.removeEventListener("cms:consent",f);go()}})'
            : 'go()';

        return <<<HTML
<script>(function(w,d,n){if(n.webdriver||n.doNotTrack=="1"||w.doNotTrack=="1"||n.globalPrivacyControl)return;function go(){var q=new URLSearchParams(location.search),b=JSON.stringify({p:location.pathname,r:d.referrer,s:q.get("utm_source")||q.get("ref")||"",w:w.innerWidth});try{n.sendBeacon?n.sendBeacon({$url},b):fetch({$url},{method:"POST",body:b,keepalive:true})}catch(e){}}{$start}})(window,document,navigator)</script>
HTML;
    }
}
