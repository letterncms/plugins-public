<?php

declare(strict_types=1);

namespace Cms\Consent\Tests;

use Cms\Admin\Translations;
use Cms\Consent\Banner;
use Cms\Tests\TestCase;

final class ConsentTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/consent', 'letterncms/analytics'];
    }

    private function foot(string $locale = 'en', array $context = []): string
    {
        $this->app->make(Translations::class)->apply($locale);
        $page = new class($locale, $context) {
            public bool $editor;

            public bool $preview;

            public string $uri = '/';

            public function __construct(private readonly string $language, array $context)
            {
                $this->editor = $context['editor'] ?? false;
                $this->preview = $context['preview'] ?? false;
            }

            public function locale(): string
            {
                return $this->language;
            }
        };

        return implode('', $this->cms()->hooks()->filter('site.foot', [], $page));
    }

    private function save(array $values): void
    {
        $this->cms()->settingsRegistry()->save('consent', $values);
    }

    public function test_a_page_carries_a_hidden_dialog_that_names_itself(): void
    {
        $foot = $this->foot();

        $this->assertMatchesRegularExpression('/<div id="cms-consent" class="cms-consent" role="dialog" aria-modal="false" aria-labelledby="cms-consent-title" aria-describedby="cms-consent-text"[^>]* hidden>/', $foot);
        $this->assertStringContainsString('<h2 id="cms-consent-title" class="cms-consent__title" tabindex="-1">Cookies</h2>', $foot);
        $this->assertStringContainsString('aria-expanded="false" aria-controls="cms-consent-choices"', $foot);
        $this->assertStringContainsString('<div id="cms-consent-choices" class="cms-consent__choices" hidden>', $foot);
        // Every button is a button with words, in the site's own button style.
        $this->assertSame(4, preg_match_all('/<button type="button" class="cms-button" data-variant="[a-z]+" data-act="[a-z]+"[^>]*><span>[^<]+<\/span><\/button>/', $foot));
        // Each switch is described by its explanation; what the site needs can't be switched off.
        $this->assertStringContainsString('<input type="checkbox" class="cms-switch" role="switch" data-category="necessary" aria-describedby="cms-consent-necessary" checked disabled>', $foot);
        $this->assertStringContainsString('<input type="checkbox" class="cms-switch" role="switch" data-category="analytics" aria-describedby="cms-consent-analytics">', $foot);
        $this->assertStringContainsString('id="cms-consent-analytics"', $foot);
    }

    public function test_it_is_fixed_over_the_page_and_styled_by_the_sites_tokens(): void
    {
        $foot = $this->foot();

        $this->assertStringContainsString('.cms-consent{position:fixed;', $foot);
        $this->assertStringContainsString('.cms-consent-open{position:fixed;', $foot);
        $this->assertStringContainsString('.cms-consent[hidden],.cms-consent [hidden],.cms-consent-open[hidden]{display:none}', $foot);

        foreach (['--color-foreground', '--color-background', '--color-border', '--radius-lg', '--color-focus'] as $token) {
            $this->assertStringContainsString("var({$token},", $foot);
        }
    }

    public function test_it_asks_about_analytics_and_about_marketing_only_when_the_site_says_so(): void
    {
        $this->assertStringContainsString('data-categories="analytics"', $this->foot());
        $this->assertStringNotContainsString('data-category="marketing"', $this->foot());

        $this->save(['marketing' => true]);
        $foot = $this->foot();

        $this->assertStringContainsString('data-categories="analytics,marketing"', $foot);
        $this->assertStringContainsString('data-category="marketing"', $foot);
        // Asking about something new asks everyone again.
        $this->assertStringContainsString('data-version="'.Banner::version(['analytics', 'marketing']).'"', $foot);
        $this->assertNotSame(Banner::version(['analytics']), Banner::version(['analytics', 'marketing']));
    }

    public function test_it_tells_scripts_the_way_analytics_listens(): void
    {
        $this->save(['marketing' => true]);
        $this->cms()->settingsRegistry()->save('analytics', ['mode' => 'visitors']);
        $foot = $this->foot();

        $this->assertStringContainsString('w.cmsConsent={necessary:true,analytics:false,marketing:false,decided:false}', $foot);
        $this->assertStringContainsString('new CustomEvent("cms:consent",{detail:{necessary:true,analytics:api.analytics,marketing:api.marketing}})', $foot);
        // What Analytics waits for, and it is on the page before Analytics asks.
        $this->assertStringContainsString('c.analytics===true', $foot);
        $this->assertStringContainsString('addEventListener("cms:consent"', $foot);
        $this->assertLessThan(strpos($foot, 'c.analytics===true'), strpos($foot, 'w.cmsConsent='));
        $this->assertStringContainsString('navigator.globalPrivacyControl', $foot);
    }

    public function test_it_speaks_the_pages_language_and_says_what_the_settings_say(): void
    {
        $german = $this->foot('de');
        $this->assertStringContainsString('<span>Alle akzeptieren</span>', $german);
        $this->assertStringContainsString('Nur notwendige', $german);

        $this->save(['title' => ['de' => 'Kekse & Co.', 'en' => 'Biscuits'], 'text' => ['de' => "Wir fragen,\nbevor wir zählen."], 'policy' => ['de' => '/de/datenschutz']]);

        $german = $this->foot('de');
        $this->assertStringContainsString('>Kekse &amp; Co.</h2>', $german);
        $this->assertStringContainsString("Wir fragen,<br />\nbevor wir zählen. <a href=\"/de/datenschutz\">Datenschutzerklärung</a>", $german);

        $english = $this->foot('en');
        $this->assertStringContainsString('>Biscuits</h2>', $english);
        $this->assertStringContainsString('We’d like to use cookies to see how this site is used. You decide.', $english);
        $this->assertStringNotContainsString('<a href=', $english);
    }

    public function test_a_policy_address_that_is_no_address_is_no_link(): void
    {
        // The link picker's field refuses it; a config file edited by hand still makes no link.
        try {
            $this->save(['policy' => ['en' => 'javascript:alert(1)']]);
            $this->fail('Saved a script as the privacy policy.');
        } catch (\Cms\Abilities\AbilityException) {
            $this->addToAssertionCount(1);
        }

        foreach (['javascript:alert(1)', '//evil.example', 'data:text/html,x'] as $policy) {
            $this->assertStringNotContainsString('<a href=', Banner::html(['analytics'], ['title' => 'Cookies', 'text' => 'Text.', 'policy' => $policy]));
        }

        $this->assertStringContainsString('<a href="/privacy">', Banner::html(['analytics'], ['title' => 'Cookies', 'text' => 'Text.', 'policy' => '/privacy']));
    }

    public function test_it_says_what_it_actually_asks_about_and_nothing_when_it_asks_nothing(): void
    {
        $this->assertStringContainsString('to see how this site is used. You decide.', $this->foot());

        $this->save(['marketing' => true]);
        $this->assertStringContainsString('to see how this site is used and to show you relevant ads. You choose which.', $this->foot());

        $this->save(['analytics' => false]);
        $this->assertStringContainsString('to show you relevant ads. You decide.', $this->foot());
        $this->assertStringNotContainsString('how this site is used', $this->foot());

        // Nothing to choose: no banner, no button, no script.
        $this->save(['marketing' => false]);
        $this->assertStringNotContainsString('cms-consent', $this->foot());
    }

    public function test_the_two_answers_weigh_the_same_and_choosing_is_a_button(): void
    {
        $foot = $this->foot();

        $this->assertStringContainsString('<button type="button" class="cms-button" data-variant="primary" data-act="accept">', $foot);
        $this->assertStringContainsString('<button type="button" class="cms-button" data-variant="primary" data-act="reject">', $foot);
        $this->assertStringContainsString('<button type="button" class="cms-button" data-variant="secondary" data-act="choose"', $foot);
        $this->assertStringNotContainsString('data-variant="link"', $foot);
        // With the choices open, saving them is the way on, and the two answers step back together.
        $this->assertStringContainsString('save.setAttribute("data-variant",on?"primary":"secondary")', $foot);
        // On a phone: half the screen at most, a thumb's height for every target, the whole row a label.
        $this->assertStringContainsString('max-height:50dvh', $foot);
        $this->assertStringContainsString('min-height:2.75rem', $foot);
        $this->assertSame(2, preg_match_all('/<label class="cms-consent__row"><input /', $foot));
    }

    public function test_the_settings_page_has_the_words_once_per_language(): void
    {
        $fields = array_column($this->cms()->settingsRegistry()->schemas()['consent']['fields'], null, 'handle');

        $this->assertSame(['enabled', 'analytics', 'marketing', 'reopen', 'title', 'text', 'policy'], array_keys($fields));

        foreach (['title', 'text', 'policy'] as $handle) {
            $this->assertSame(['en', 'de'], array_column($fields[$handle]['fields'], 'handle'));
            $this->assertSame(['English', 'Deutsch'], array_column($fields[$handle]['fields'], 'label'));
        }

        $title = array_column($fields['title']['fields'], null, 'handle');
        $text = array_column($fields['text']['fields'], null, 'handle');
        $policy = array_column($fields['policy']['fields'], null, 'handle');

        // An empty field shows what the banner says without it, in the banner's language, whatever the admin's.
        $this->assertSame("Cookies\u{200B}", $title['en']['placeholder']);
        $this->assertSame("We’d like to use cookies to see how this site is used. You decide.\u{200B}", $text['en']['placeholder']);
        $this->assertStringStartsWith('Wir möchten Cookies verwenden', $text['de']['placeholder']);
        $this->assertSame(['url', 'link'], [$policy['de']['type'], $policy['de']['component']]);
        $this->assertSame('Show Cookie Settings Button', $fields['reopen']['label']);
        $this->assertSame([], array_filter(array_column($fields, 'instructions')), 'No helper sentences.');
    }

    public function test_words_saved_as_a_field_per_language_move_into_one_value_per_language(): void
    {
        \Cms\Support\Yaml::write(site_path('config/consent.yaml'), ['enabled' => true, 'title_de' => 'Kekse', 'text_de' => 'Wir fragen.', 'title_en' => 'Biscuits', 'policy_pt_br' => '/privacidade']);
        $this->cms()->settingsRegistry()->forget('consent');

        (new \Cms\Consent\ConsentPlugin($this->app))->upgrade('0.9.0');

        $this->assertSame(['enabled' => true, 'title' => ['de' => 'Kekse', 'en' => 'Biscuits'], 'text' => ['de' => 'Wir fragen.'], 'policy' => ['pt_br' => '/privacidade']], \Cms\Support\Yaml::read(site_path('config/consent.yaml')));
        $this->assertStringContainsString('>Kekse</h2>', $this->foot('de'));
        $this->assertStringContainsString('>Biscuits</h2>', $this->foot('en'));
    }

    public function test_it_stays_off_the_builder_previews_and_a_site_that_turned_it_off(): void
    {
        $this->assertStringNotContainsString('cms-consent', $this->foot('en', ['editor' => true]));
        $this->assertStringNotContainsString('cms-consent', $this->foot('en', ['preview' => true]));

        $this->save(['reopen' => false]);
        $this->assertStringNotContainsString('class="cms-consent-open"', $this->foot());

        $this->save(['enabled' => false]);
        $this->assertStringNotContainsString('cms-consent', $this->foot());
    }

    public function test_changing_a_setting_builds_the_pages_again(): void
    {
        $signature = fn () => $this->cms()->hooks()->filter('site.build.signature', [])['consent'];
        $before = $signature();

        $this->save(['title' => ['en' => 'Biscuits']]);

        $this->assertNotSame($before, $signature());
    }

    public function test_its_home_screen_widget_is_silent_unless_analytics_waits_for_an_answer_nobody_is_asked_for(): void
    {
        $widget = fn () => $this->cms()->views()->resolve('widget.consent-banner', $this->admin())['root'];

        $this->assertSame(['type' => 'ui/stack', 'props' => []], $widget(), 'Nothing to restate.');

        $this->cms()->settingsRegistry()->save('analytics', ['mode' => 'visitors']);
        $this->assertArrayNotHasKey('children', $widget());

        $this->save(['enabled' => false]);
        $this->assertSame('ui/notice', $widget()['children'][0]['type']);
        $this->assertSame('/settings/consent', $widget()['children'][0]['props']['action']['to']);
    }
}
