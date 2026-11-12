<?php

declare(strict_types=1);

namespace Cms\Consent;

/**
 * What a published page carries: the banner (hidden until the script shows it), its styles and
 * a script, all inline, so a built page needs nothing else.
 *
 * The answer lives in the browser, in the first-party cookie `cms_consent`
 * (`<version>.<0|1 per category>`, six months): the page is one copy for everyone. The version
 * names the categories asked about, so asking about a new one asks everyone again.
 *
 * For scripts: `window.cmsConsent` is `{necessary: true, analytics, marketing, decided}` with
 * `open()` and `set({analytics, marketing})`, and every answer fires `cms:consent` on `document`
 * with `detail` `{necessary, analytics, marketing}`. A
 * `<script type="text/plain" data-cms-consent="marketing">` runs once its category is accepted.
 * Taking an accepted category back reloads the page, so what already ran stops.
 *
 * Global Privacy Control counts as "no" until the visitor says otherwise: no banner, nothing
 * accepted, and the settings stay one click away.
 *
 * Nothing moves when it appears: the banner is fixed over the page, never part of its flow.
 */
final class Banner
{
    /**
     * @param  list<string>  $categories  of ConsentPlugin::CATEGORIES
     * @param  array{title: string, text: string, policy: string}  $copy
     */
    public static function html(array $categories, array $copy, bool $reopen = true): string
    {
        $words = [
            'necessary' => [__('Necessary'), __('What the site needs to work. Always on.')],
            'analytics' => [__('Analytics'), __('Shows us which pages are read.')],
            'marketing' => [__('Marketing'), __('Lets us and our partners show you relevant ads.')],
        ];

        $rows = '';

        foreach (['necessary', ...$categories] as $category) {
            [$label, $help] = $words[$category];
            $locked = $category === 'necessary' ? ' checked disabled' : '';
            $rows .= '<label class="cms-consent__row"><input type="checkbox" class="cms-switch" role="switch" data-category="'.$category.'" aria-describedby="cms-consent-'.$category.'"'.$locked.'>'
                .'<span><strong>'.e($label).'</strong><span id="cms-consent-'.$category.'">'.e($help).'</span></span></label>';
        }

        $policy = $copy['policy'] !== '' && preg_match('#^(/(?!/)|https?://)#i', $copy['policy'])
            ? ' <a href="'.e($copy['policy']).'">'.e(__('Privacy Policy')).'</a>'
            : '';

        $banner = '<div id="cms-consent" class="cms-consent" role="dialog" aria-modal="false" aria-labelledby="cms-consent-title" aria-describedby="cms-consent-text" data-version="'.self::version($categories).'" data-categories="'.e(implode(',', $categories)).'" hidden>'
            .'<h2 id="cms-consent-title" class="cms-consent__title" tabindex="-1">'.e($copy['title']).'</h2>'
            .'<p id="cms-consent-text" class="cms-consent__text">'.nl2br(e($copy['text'])).$policy.'</p>'
            .'<div id="cms-consent-choices" class="cms-consent__choices" hidden>'.$rows.'</div>'
            .'<div class="cms-consent__actions" data-size="sm">'
            // The two answers weigh the same; once the choices are open, saving them is the way on.
            .'<button type="button" class="cms-button" data-variant="secondary" data-act="save" hidden><span>'.e(__('Save Choices')).'</span></button>'
            .'<button type="button" class="cms-button" data-variant="primary" data-act="accept"><span>'.e(__('Accept All')).'</span></button>'
            .'<button type="button" class="cms-button" data-variant="primary" data-act="reject"><span>'.e(__('Only Necessary')).'</span></button>'
            .'<button type="button" class="cms-button" data-variant="secondary" data-act="choose" aria-expanded="false" aria-controls="cms-consent-choices"><span>'.e(__('Choose')).'</span></button>'
            .'</div></div>';

        $opener = $reopen ? '<button type="button" class="cms-consent-open" data-cms-consent-open hidden>'.e(__('Cookie Settings')).'</button>' : '';

        return $banner.$opener.'<style>'.self::CSS.'</style><script>'.self::JS.'</script>';
    }

    /** @param list<string> $categories */
    public static function version(array $categories): string
    {
        return substr(md5(implode(',', $categories)), 0, 6);
    }

    /** The site's tokens with a fallback each, so it reads well on a theme that leaves one out. Unlayered: the theme's layers can't undo where it sits. */
    private const string CSS = '.cms-consent{position:fixed;z-index:2147483000;inset:auto auto 1rem 1rem;box-sizing:border-box;width:calc(100% - 2rem);max-width:26rem;max-height:calc(100dvh - 2rem);overflow:auto;overscroll-behavior:contain;display:grid;gap:.75rem;margin:0;padding:1.25rem;font-size:.9375rem;line-height:1.5;color:var(--color-foreground,#111);background:var(--color-background,#fff);border:1px solid var(--color-border,rgb(0 0 0/.15));border-radius:var(--radius-lg,.75rem);box-shadow:var(--shadow-lg,0 24px 48px -16px rgb(0 0 0/.2))}'
        .'.cms-consent[hidden],.cms-consent [hidden],.cms-consent-open[hidden]{display:none}'
        .'.cms-consent__title{margin:0;font-size:1rem;line-height:1.3;font-weight:var(--font-weight-semibold,600);outline:0}'
        .'.cms-consent__text{margin:0}.cms-consent__text a{color:inherit;text-decoration:underline;text-underline-offset:.2em}'
        .'.cms-consent__choices{display:grid}'
        // The whole row is the switch's label, and tall enough for a thumb.
        .'.cms-consent__row{display:flex;gap:.75rem;align-items:flex-start;min-height:2.75rem;box-sizing:border-box;padding-block:.375rem;cursor:pointer}.cms-consent__row>span{display:grid}.cms-consent__row>span>span{opacity:.75}'
        // What can't be switched off is plainly on, in a dark theme too: the kit dims a disabled switch.
        .'.cms-consent__row:has(:disabled){cursor:default}.cms-consent .cms-switch:disabled{opacity:1;cursor:default}'
        .'.cms-consent__actions{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}'
        .'.cms-consent__actions .cms-button{margin:0}'
        .'.cms-consent :focus-visible,.cms-consent-open:focus-visible{outline:2px solid var(--color-focus,currentColor);outline-offset:2px}'
        .'.cms-consent-open{position:fixed;z-index:2147482999;inset:auto auto .75rem .75rem;padding:.35rem .7rem;font:inherit;font-size:.8125rem;line-height:1.4;color:var(--color-foreground,#111);background:var(--color-background,#fff);border:1px solid var(--color-border,rgb(0 0 0/.15));border-radius:var(--radius-md,.5rem);cursor:pointer;opacity:.8}'
        .'.cms-consent-open:hover,.cms-consent-open:focus-visible{opacity:1}'
        // On a phone: at most half the screen, buttons one under the other, as wide as the panel and a thumb high.
        .'@media (max-width:40rem){.cms-consent{inset:auto .5rem .5rem .5rem;width:auto;max-width:none;max-height:50dvh;gap:.5rem;padding:1rem}'
        .'.cms-consent__actions{display:grid;grid-template-columns:1fr 1fr}.cms-consent__actions .cms-button{grid-column:1/-1;width:100%;min-height:2.75rem;justify-content:center}'
        // With the choices open, saving them comes first and the two quick answers share a line: the panel stays under half the screen.
        .'.cms-consent[data-state=choices] :is([data-act=accept],[data-act=reject]){grid-column:auto}'
        .'.cms-consent[data-state=choices] .cms-consent__text{display:none}'
        .'.cms-consent-open{min-height:2.75rem;padding-inline:.9rem}}'
        .'@media print{.cms-consent,.cms-consent-open{display:none}}';

    private const string JS = '(function(w,d){var el=d.getElementById("cms-consent");if(!el)return;'
        .'var V=el.getAttribute("data-version"),cats=(el.getAttribute("data-categories")||"").split(",").filter(Boolean),all=["analytics","marketing"],'
        .'q=function(s){return el.querySelector(s)},choices=q("#cms-consent-choices"),choose=q("[data-act=choose]"),save=q("[data-act=save]"),opener=d.querySelector(".cms-consent-open"),from=null,'
        .'api=w.cmsConsent={necessary:true,analytics:false,marketing:false,decided:false};'
        // A page the site's own admin shows in a frame (the design screen's preview) is nobody's visit: nothing to ask.
        .'if(w.frameElement)return;'
        .'function read(){try{var m=d.cookie.match(/(?:^|; )cms_consent=([^;]*)/);if(!m)return null;var p=decodeURIComponent(m[1]).split(".");if(p[0]!==V)return null;var o={};cats.forEach(function(c,i){o[c]=p[i+1]==="1"});return o}catch(e){return null}}'
        .'function write(o){try{d.cookie="cms_consent="+[V].concat(cats.map(function(c){return o[c]?1:0})).join(".")+";path=/;max-age=15552000;SameSite=Lax"+(location.protocol==="https:"?";Secure":"")}catch(e){}}'
        .'function run(){[].slice.call(d.querySelectorAll("script[type=\"text/plain\"][data-cms-consent]")).forEach(function(s){if(api[s.getAttribute("data-cms-consent")]!==true)return;var n=d.createElement("script");[].slice.call(s.attributes).forEach(function(a){if(a.name!=="type"&&a.name!=="data-cms-consent")n.setAttribute(a.name,a.value)});n.text=s.text;s.parentNode.replaceChild(n,s)})}'
        .'function apply(o,decided){var back=false;all.forEach(function(c){var on=cats.indexOf(c)>-1&&o[c]===true;if(api[c]&&!on)back=true;api[c]=on});api.decided=decided;'
        .'[].slice.call(el.querySelectorAll("input[data-category]")).forEach(function(i){if(!i.disabled)i.checked=api[i.getAttribute("data-category")]});'
        .'if(back){location.reload();return}run();d.dispatchEvent(new CustomEvent("cms:consent",{detail:{necessary:true,analytics:api.analytics,marketing:api.marketing}}))}'
        .'function details(on){choices.hidden=!on;save.hidden=!on;choose.hidden=on;choose.setAttribute("aria-expanded",on?"true":"false");el.setAttribute("data-state",on?"choices":"ask");save.setAttribute("data-variant",on?"primary":"secondary");[q("[data-act=accept]"),q("[data-act=reject]")].forEach(function(b){b.setAttribute("data-variant",on?"secondary":"primary")})}'
        .'function show(focus){if(el.parentNode!==d.body||d.body.firstChild!==el)d.body.insertBefore(el,d.body.firstChild);el.hidden=false;if(opener)opener.hidden=true;if(focus)q("#cms-consent-title").focus()}'
        .'function hide(){var had=el.contains(d.activeElement);el.hidden=true;details(false);if(opener)opener.hidden=false;var to=from&&d.contains(from)&&from.getClientRects().length?from:opener;if((had||from)&&to&&to.focus)to.focus();from=null}'
        .'function decide(o){write(o);apply(o,true);hide()}'
        .'api.open=function(){show(true);details(true)};'
        .'api.set=function(o){var n={};cats.forEach(function(c){n[c]=o&&o[c]===true});decide(n)};'
        .'el.addEventListener("click",function(e){var b=e.target.closest?e.target.closest("[data-act]"):null;if(!b)return;var a=b.getAttribute("data-act"),o={};'
        .'if(a==="choose"){details(true);var f=q("input[data-category]:not([disabled])");if(f)f.focus();return}'
        .'cats.forEach(function(c){var i=q("input[data-category="+c+"]");o[c]=a==="accept"||(a==="save"&&!!i&&i.checked)});decide(o)});'
        .'el.addEventListener("keydown",function(e){if(e.key==="Escape"){e.stopPropagation();hide()}});'
        .'d.addEventListener("click",function(e){var t=e.target.closest?e.target.closest("[data-cms-consent-open],a[href$=\"#cms-consent\"]"):null;if(!t)return;e.preventDefault();from=t;api.open()});'
        .'var stored=read();if(stored){apply(stored,true);if(opener)opener.hidden=false}else if(navigator.globalPrivacyControl){if(opener)opener.hidden=false}else show(false)'
        .'})(window,document);';
}
