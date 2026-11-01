<?php

declare(strict_types=1);

namespace Cms\I18n\Tests;

use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\I18n\StringMap;
use Cms\Tests\TestCase;

final class I18nTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/i18n'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cms()->saveSite(['locale' => 'en', 'locales' => ['en', 'de']]);
    }

    private function ability(string $name, array $input = []): mixed
    {
        return $this->cms()->abilities()->execute($name, $input, AbilityContext::system());
    }

    private function page(string $title, array $extra = []): array
    {
        return $this->ability('entries/create', ['collection' => 'pages', 'title' => $title, 'status' => 'published', ...$extra]);
    }

    public function test_translation_keeps_source_authorship_and_records_the_translator_as_actor(): void
    {
        $writer = $this->admin(['name' => 'Writer']);
        $translator = $this->admin(['name' => 'Translator']);
        $source = $this->cms()->content()->create('pages', ['title' => 'Attributed source'], $writer);
        $translated = $this->cms()->abilities()->execute('translations/create', ['id' => $source->id, 'locale' => 'de'], AbilityContext::forUser($translator, 'rest'));
        $this->assertSame($writer->id, $translated['authorId']);
        $revision = app(\Cms\Content\Revisions\Revisions::class)->list($translated['id'])[0];
        $this->assertSame($translator->id, $revision['user']['id']);
        $writer->delete();
        $duplicate = $this->cms()->content()->duplicate($translated['id'], $translator);
        $this->assertSame($writer->id, $duplicate->authorId, 'Historical credit is retained even when its account is unavailable.');
        $this->assertNull($duplicate->toArray()['author']);
    }

    public function test_languages_take_the_language_field_over_from_site_settings(): void
    {
        $handles = array_column($this->cms()->settingsRegistry()->schemas()['site']['fields'], 'handle');

        $this->assertNotContains('locale', $handles);
        $this->assertContains('timezone', $handles);
    }

    public function test_translations_live_under_a_language_prefix(): void
    {
        $home = $this->page('Home', ['slug' => 'home']);
        $about = $this->page('About');
        $team = $this->page('Team', ['parentId' => $about['id']]);

        $deHome = $this->ability('translations/create', ['id' => $home['id'], 'locale' => 'de']);
        $this->assertSame('/de', $deHome['uri']);
        $this->assertSame('de', $deHome['locale']);
        $this->assertSame($home['id'], $deHome['originId']);
        $this->assertSame('draft', $deHome['status']);

        try {
            $this->ability('translations/create', ['id' => $team['id'], 'locale' => 'de']);
            $this->fail('A child needs its parent translated first.');
        } catch (AbilityException $e) {
            $this->assertStringContainsString('parent page “About”', $e->getMessage());
        }

        $deAbout = $this->ability('translations/create', ['id' => $about['id'], 'locale' => 'de']);
        $deTeam = $this->ability('translations/create', ['id' => $team['id'], 'locale' => 'de']);
        $this->assertSame('/de/about', $deAbout['uri']);
        $this->assertSame('/de/about/team', $deTeam['uri']);
        $this->assertSame($deAbout['id'], $deTeam['parentId']);

        // Every version finds all the others, whichever one you ask.
        $list = $this->ability('translations/list', ['id' => $deTeam['id']]);
        $this->assertSame($team['id'], $list['origin']);
        $this->assertEqualsCanonicalizing(['en', 'de'], array_column($list['versions'], 'locale'));
        $this->assertSame([], $list['missing']);

        $this->expectException(AbilityException::class);
        $this->ability('translations/create', ['id' => $about['id'], 'locale' => 'de']);
    }

    public function test_coverage_counts_each_language_and_follows_saves(): void
    {
        $about = $this->page('About');
        $this->page('Contact');
        $coverage = fn () => array_column($this->ability('translations/coverage')['languages'], null, 'code');

        $this->assertEquals(['entries' => 2, 'share' => 1.0, 'default' => true], array_intersect_key($coverage()['en'], array_flip(['entries', 'share', 'default'])));
        $this->assertSame(0, $coverage()['de']['entries']);

        // Saving an entry forgets the cached counts.
        $this->ability('translations/create', ['id' => $about['id'], 'locale' => 'de']);
        $this->assertSame(1, $coverage()['de']['entries']);
        $this->assertSame(0.5, $coverage()['de']['share']);
        $this->assertSame(2, $this->ability('translations/coverage')['total']);
    }

    public function test_language_changes_recompute_urls(): void
    {
        $about = $this->page('About');
        $this->ability('translations/create', ['id' => $about['id'], 'locale' => 'de']);

        $this->ability('languages/save', ['default' => 'en', 'languages' => ['en', 'de', 'fr'], 'prefixDefault' => true]);
        $this->assertSame('/en/about', $this->cms()->entries()->find($about['id'])->uri);

        $this->ability('languages/save', ['default' => 'en', 'languages' => ['en', 'de'], 'prefixDefault' => false]);
        $this->assertSame('/about', $this->cms()->entries()->find($about['id'])->uri);

        $languages = $this->ability('languages/list')['languages'];
        $this->assertSame(['en', 'de'], array_column($languages, 'code'));
        $this->assertSame('Deutsch', $languages[1]['name']);
        $this->assertSame(1, $languages[1]['entries']);

        try {
            $this->ability('languages/save', ['default' => 'en', 'languages' => ['en']]);
            $this->fail('A language with entries cannot be removed.');
        } catch (AbilityException $e) {
            $this->assertStringContainsString('de still has 1 entry', $e->getMessage());
        }
    }

    public function test_a_translated_slug_follows_the_rules_of_its_language(): void
    {
        $this->cms()->hooks()->addFilter('i18n.translator', static fn () => static fn (array $strings) => array_map(static fn () => 'Über Größe', $strings));

        $source = $this->page('About');

        $this->assertSame('ueber-groesse', $this->ability('translations/create', ['id' => $source['id'], 'locale' => 'de', 'translate' => true])['slug']);
    }

    public function test_ai_translation_changes_text_but_never_structure(): void
    {
        $this->cms()->hooks()->addFilter('i18n.translator', static fn () => static fn (array $strings, string $from, string $to) => array_map(static fn ($s) => "[{$to}] {$s}", $strings));

        $source = $this->page('Pricing', ['data' => ['summary' => 'Plans for every team']]);
        $de = $this->ability('translations/create', ['id' => $source['id'], 'locale' => 'de', 'translate' => true]);

        $this->assertSame('[de] Pricing', $de['title']);
        $this->assertSame('[de] Plans for every team', $de['data']['summary']);
        $this->assertSame('/de/de-pricing', $de['uri']);
    }

    public function test_string_map_only_touches_prose(): void
    {
        $content = [
            'title' => 'Welcome',
            'data' => [
                'body' => [
                    ['id' => 'b1abcdefgh', 'type' => 'heading', 'props' => ['text' => 'Hello there', 'level' => 'h2'], 'style' => ['base' => ['color' => 'token:color.primary']]],
                    ['id' => 'b2abcdefgh', 'type' => 'button', 'props' => ['label' => 'Start now', 'url' => '/start', 'variant' => 'primary']],
                    ['id' => 'b3abcdefgh', 'type' => 'image', 'props' => ['media' => '01m5wc4sa5w0p6y3r10qssztdb', 'alt' => 'A sunset']],
                ],
                'email' => 'hello@example.com',
                'link' => 'https://example.com',
            ],
        ];

        $map = StringMap::extract($content);
        $this->assertSame(['Welcome', 'Hello there', 'Start now', 'A sunset'], $map['strings']);

        $out = StringMap::apply($content, $map['paths'], ['Willkommen', 'Hallo', 'Jetzt starten', 'Ein Sonnenuntergang']);
        $this->assertSame('Hallo', $out['data']['body'][0]['props']['text']);
        $this->assertSame('h2', $out['data']['body'][0]['props']['level']);
        $this->assertSame('/start', $out['data']['body'][1]['props']['url']);
        $this->assertSame('token:color.primary', $out['data']['body'][0]['style']['base']['color']);
        $this->assertSame('01m5wc4sa5w0p6y3r10qssztdb', $out['data']['body'][2]['props']['media']);
    }

    public function test_alternates_switcher_and_menus_follow_the_language(): void
    {
        $this->page('Home', ['slug' => 'home']);
        $about = $this->page('About');
        $de = $this->ability('translations/create', ['id' => $about['id'], 'locale' => 'de']);
        $enEntry = $this->cms()->entries()->find($about['id']);

        $this->assertSame([], $this->cms()->hooks()->filter('seo.alternates', [], $enEntry), 'drafts are not alternates');

        $this->ability('entries/publish', ['id' => $de['id']]);
        $alternates = $this->cms()->hooks()->filter('seo.alternates', [], $enEntry);
        $this->assertEqualsCanonicalizing([['locale' => 'en', 'uri' => '/about'], ['locale' => 'de', 'uri' => '/de/about'], ['locale' => 'x-default', 'uri' => '/about']], $alternates);

        $links = cms_translations($enEntry);
        $this->assertSame(['en', 'de'], array_column($links, 'locale'));
        $this->assertSame('/de/about', $links[1]['url']);
        $this->assertTrue($links[0]['current']);

        $this->assertSame($de['id'], $this->cms()->hooks()->filter('navigation.entry', $enEntry, 'de')->id);
        $this->assertSame('rtl', cms_text_direction('ar'));
    }
}
