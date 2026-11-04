<?php

declare(strict_types=1);

namespace Cms\Site\Tests;

use Cms\Abilities\AbilityContext;
use Cms\Forms\FormRepository;
use Cms\Forms\Submission;
use Cms\Modules\Languages\StringMap;
use Cms\Modules\Navigation\MenuResolver;
use Cms\Seo\Meta;
use Cms\Seo\SiteFiles;
use Cms\Tests\TestCase;

/** A site in several languages, seen by a visitor: URLs, links, menus, forms, search. */
final class LocalisedSiteTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks', 'letterncms/site', 'letterncms/languages', 'letterncms/navigation', 'letterncms/forms', 'letterncms/seo'];
    }

    protected function collections(): array
    {
        return [
            'pages' => ['title' => 'Pages', 'structure' => 'tree', 'route' => '/{parent_uri}/{slug}', 'home' => 'home', 'body' => 'content', 'fields' => [['handle' => 'content', 'type' => 'blocks']]],
            'news' => ['title' => 'News', 'structure' => 'dated', 'route' => '/news/{slug}', 'routes' => ['de' => '/neuigkeiten/{slug}'], 'body' => 'content', 'fields' => [['handle' => 'content', 'type' => 'blocks']]],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cms()->saveSite(['locale' => 'en', 'locales' => ['en', 'de', 'fr']]);
    }

    private function page(string $title, array $extra = []): \Cms\Content\Entries\Entry
    {
        return $this->cms()->content()->create('pages', ['title' => $title, 'status' => 'published', ...$extra]);
    }

    private function translation(\Cms\Content\Entries\Entry $source, string $locale, string $title, array $extra = []): \Cms\Content\Entries\Entry
    {
        return $this->cms()->content()->copyFrom($source, ['title' => $title, 'data' => $source->data, 'locale' => $locale, 'originId' => $source->id, 'status' => 'published', ...$extra]);
    }

    public function test_a_collection_can_have_another_url_pattern_in_a_language(): void
    {
        $post = $this->cms()->content()->create('news', ['title' => 'First', 'slug' => 'first', 'status' => 'published']);
        $de = $this->translation($post, 'de', 'Erste', ['slug' => 'erste']);
        $fr = $this->translation($post, 'fr', 'Première', ['slug' => 'premiere']);

        $this->assertSame('/news/first', $post->uri);
        $this->assertSame('/de/neuigkeiten/erste', $de->uri);
        $this->assertSame('/fr/news/premiere', $fr->uri, 'A language without its own pattern uses the collection’s.');
    }

    public function test_links_lead_to_the_version_in_the_language_being_read(): void
    {
        $pricing = $this->page('Pricing', ['slug' => 'pricing']);
        $this->translation($pricing, 'de', 'Preise', ['slug' => 'preise']);
        $only = $this->page('English only', ['slug' => 'only']);
        $this->assertSame('/only', $only->uri);

        $link = fn (string $url, array $attrs = []) => ['type' => 'link', 'props' => ['label' => 'Go', 'url' => $url], 'attrs' => $attrs];
        $about = $this->page('About', ['slug' => 'about', 'data' => ['content' => [
            $link('/pricing'),
            $link('/pricing#faq'),
            $link('/only'),
            $link('https://example.com/pricing'),
            $link('/pricing', ['hreflang' => 'en']),
        ]]]);
        $this->translation($about, 'de', 'Über uns', ['slug' => 'ueber-uns']);

        $html = $this->get('/de/ueber-uns')->assertOk()->getContent();

        $this->assertStringContainsString('href="/de/preise"', $html);
        $this->assertStringContainsString('href="/de/preise#faq"', $html);
        $this->assertStringContainsString('href="/only"', $html, 'No German version: the link stays.');
        $this->assertStringContainsString('href="https://example.com/pricing"', $html);
        $this->assertMatchesRegularExpression('~<a[^>]*hreflang="en"[^>]*href="/pricing"|<a[^>]*href="/pricing"[^>]*hreflang="en"~', $html, 'A link that names its language keeps it.');

        // The English page is untouched.
        $this->assertStringContainsString('href="/pricing"', $this->get('/about')->getContent());
    }

    public function test_a_link_to_an_unpublished_translation_stays_on_the_original(): void
    {
        $pricing = $this->page('Pricing', ['slug' => 'pricing']);
        $this->cms()->content()->copyFrom($pricing, ['title' => 'Preise', 'data' => [], 'locale' => 'de', 'originId' => $pricing->id, 'status' => 'draft', 'slug' => 'preise']);
        $about = $this->page('About', ['slug' => 'about', 'data' => ['content' => [['type' => 'link', 'props' => ['label' => 'Go', 'url' => '/pricing']]]]]);
        $this->translation($about, 'de', 'Über uns', ['slug' => 'ueber-uns']);

        $this->assertStringContainsString('href="/pricing"', $this->get('/de/ueber-uns')->getContent());
    }

    public function test_menu_items_have_their_own_wording_and_link_per_language(): void
    {
        $pricing = $this->page('Pricing', ['slug' => 'pricing']);
        $this->translation($pricing, 'de', 'Preise', ['slug' => 'preise']);

        $saved = $this->cms()->abilities()->execute('navigation/save', ['handle' => 'main', 'title' => 'Main', 'items' => [
            ['entry' => $pricing->id, 'label' => 'Plans', 'locales' => ['de' => ['label' => 'Tarife', 'url' => 'https://ignored.example']]],
            ['url' => 'https://docs.example', 'label' => 'Docs', 'locales' => ['de' => ['label' => 'Doku', 'url' => 'https://docs.example/de'], 'fr' => ['label' => 'Documentation']]],
        ]], AbilityContext::system());

        $this->assertSame(['de' => ['label' => 'Tarife']], $saved['items'][0]['locales'], 'A linked page brings its own URL.');

        $resolver = $this->app->make(MenuResolver::class);
        $resolve = fn (string $locale) => array_map(static fn ($i) => [$i['label'], $i['url']], $resolver->resolve('main', '/', $locale));

        $this->assertSame([['Plans', '/pricing'], ['Docs', 'https://docs.example']], $resolve('en'));
        $this->assertSame([['Tarife', '/de/preise'], ['Doku', 'https://docs.example/de']], $resolve('de'));
        $this->assertSame([['Plans', '/pricing'], ['Documentation', 'https://docs.example']], $resolve('fr'));
    }

    public function test_forms_read_in_the_language_of_their_page_and_answer_in_it(): void
    {
        $forms = $this->app->make(FormRepository::class);
        $forms->save('contact', [
            'title' => 'Contact',
            'fields' => [
                ['type' => 'form/text', 'props' => ['label' => 'Name', 'name' => 'name', 'required' => true]],
                ['type' => 'form/radio', 'props' => ['label' => 'Kind', 'name' => 'kind', 'options' => "site|Website\napp|App"]],
            ],
            'settings' => ['submit' => 'Send', 'success' => 'Thanks', 'min_seconds' => 0],
            'locales' => ['de' => [
                'settings' => ['submit' => 'Senden', 'success' => 'Danke', 'notify' => 'not-a-word'],
                'fields' => ['name' => ['label' => 'Ihr Name', 'name' => 'hacked'], 'kind' => ['options' => "site|Webseite\napp|App"]],
            ]],
        ]);

        // Only words are kept, and they survive a save.
        $this->assertSame(['submit' => 'Senden', 'success' => 'Danke'], $forms->find('contact')['locales']['de']['settings']);

        $page = $this->page('Contact', ['slug' => 'contact', 'data' => ['content' => [['type' => 'forms/form', 'props' => ['form' => 'contact']]]]]);
        $this->translation($page, 'de', 'Kontakt', ['slug' => 'kontakt']);

        $html = $this->get('/de/kontakt')->assertOk()->getContent();
        $this->assertStringContainsString('Ihr Name', $html);
        $this->assertStringContainsString('Senden', $html);
        $this->assertStringContainsString('name="_cms_locale" value="de"', $html);
        $this->assertStringContainsString('name="name"', $html, 'Field names stay those of the form.');
        $this->assertStringContainsString('Webseite', $html);
        $this->assertStringContainsString('Send', $this->get('/contact')->getContent());

        // A German visitor's mistakes come back in German, with the German label.
        $this->postJson('/api/forms/contact', ['_cms_locale' => 'de', 'kind' => 'site'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name', 'Ihr Name ist erforderlich.')
            ->assertJsonPath('message', 'Bitte prüfe die markierten Felder und versuche es erneut.');

        $this->postJson('/api/forms/contact', ['_cms_locale' => 'de', 'name' => 'Ada', 'kind' => 'site'])
            ->assertOk()
            ->assertJsonPath('message', 'Danke');

        $this->assertSame(['name' => 'Ada', 'kind' => 'site'], Submission::query()->first()->data, 'One form collects one kind of answer, in every language.');
    }

    public function test_hreflang_lists_only_versions_search_engines_may_show(): void
    {
        $page = $this->page('About', ['slug' => 'about']);
        $de = $this->translation($page, 'de', 'Über uns', ['slug' => 'ueber-uns']);
        $fr = $this->translation($page, 'fr', 'À propos', ['slug' => 'a-propos', 'data' => ['seo' => ['noindex' => true]]]);

        $html = $this->get('/about')->assertOk()->getContent();
        $this->assertStringContainsString('hreflang="de" href="http://localhost/de/ueber-uns"', str_replace($this->baseUrl(), 'http://localhost', $html));
        $this->assertStringContainsString('hreflang="x-default"', $html);
        $this->assertStringNotContainsString('hreflang="fr"', $html);

        // The noindexed version announces nobody, and nobody announces it.
        $this->assertStringNotContainsString('hreflang=', $this->get($fr->uri)->assertOk()->getContent());
        $this->assertStringContainsString('content="de_DE"', $this->get($de->uri)->getContent());
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->cms()->site('url', config('app.url')), '/');
    }

    public function test_a_translation_does_not_inherit_a_canonical_or_noindex(): void
    {
        $page = $this->page('Old', ['slug' => 'old', 'data' => ['seo' => ['canonical' => '/new', 'noindex' => true, 'description' => 'Kept']]]);

        $de = $this->cms()->abilities()->execute('translations/create', ['id' => $page->id, 'locale' => 'de'], AbilityContext::system());

        $this->assertSame(['description' => 'Kept'], $de['data']['seo']);
    }

    public function test_each_language_has_its_own_sitemap_under_an_index(): void
    {
        $home = $this->page('Home', ['slug' => 'home']);
        $this->translation($home, 'de', 'Start', ['slug' => 'home']);
        $about = $this->page('About', ['slug' => 'about']);
        $this->translation($about, 'de', 'Über uns', ['slug' => 'ueber-uns']);

        $files = $this->app->make(SiteFiles::class);
        $this->assertSame(['de' => 'sitemap-de.xml', 'en' => 'sitemap-en.xml'], collect($files->languageSitemaps())->sortKeys()->all());

        $index = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<sitemapindex', $index);
        $this->assertStringContainsString('/sitemap-de.xml</loc>', $index);

        $german = $this->get('/sitemap-de.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/de/ueber-uns</loc>', $german);
        $this->assertStringNotContainsString('/about</loc>', $german);
        $this->assertStringContainsString('hreflang="en"', $german, 'Each URL still names its other languages.');

        $this->get('/sitemap-fr.xml')->assertNotFound();
    }

    public function test_a_single_language_site_keeps_one_plain_sitemap(): void
    {
        $this->cms()->saveSite(['locale' => 'en', 'locales' => ['en']]);
        $this->page('About', ['slug' => 'about']);

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<urlset', $xml);
        $this->assertStringContainsString('/about</loc>', $xml);
    }

    public function test_the_home_of_a_language_is_a_home_page_and_starts_its_breadcrumbs(): void
    {
        $home = $this->page('Home', ['slug' => 'home']);
        $deHome = $this->translation($home, 'de', 'Start', ['slug' => 'home']);
        $about = $this->page('About', ['slug' => 'about']);
        $this->translation($about, 'de', 'Über uns', ['slug' => 'ueber-uns']);

        $this->assertTrue(Meta::isHome($deHome));
        $this->assertSame('/de', $deHome->uri);

        $html = $this->get('/de/ueber-uns')->assertOk()->getContent();
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $json);
        $graph = json_decode($json[1], true)['@graph'];
        $crumbs = collect($graph)->firstWhere('@type', 'BreadcrumbList')['itemListElement'];
        $site = collect($graph)->firstWhere('@type', 'WebSite');

        $this->assertSame('Start', $crumbs[0]['name']);
        $this->assertStringEndsWith('/de', $crumbs[0]['item']);
        $this->assertSame('de', $site['inLanguage']);
    }

    public function test_a_missing_page_answers_in_the_language_of_its_url(): void
    {
        $this->cms()->content()->create('templates', ['title' => '404', 'slug' => '404', 'status' => 'published', 'data' => ['blocks' => [
            ['type' => 'paragraph', 'props' => ['text' => '', 'tag' => 'p'], 'bind' => ['text' => 'globals.ui.gone']],
            ['type' => 'repeat', 'props' => ['source' => 'languages'], 'children' => [['type' => 'link', 'bind' => ['label' => 'item.locale', 'url' => 'item.url', 'attr:hreflang' => 'item.locale']]]],
        ]]]);
        $this->cms()->globals()->save(\Cms\Content\Globals\GlobalSet::fromArray('ui', [
            'fields' => [['handle' => 'gone', 'type' => 'text', 'label' => 'Gone']],
            'data' => ['gone' => 'Page not found'],
            'locales' => ['de' => ['gone' => 'Seite nicht gefunden']],
        ]));
        $home = $this->page('Home', ['slug' => 'home']);
        $this->translation($home, 'de', 'Start', ['slug' => 'home']);

        $german = $this->get('/de/gibt-es-nicht')->assertNotFound()->getContent();
        $this->assertStringContainsString('<html lang="de"', $german);
        $this->assertStringContainsString('Seite nicht gefunden', $german);

        $english = $this->get('/does-not-exist')->assertNotFound()->getContent();
        $this->assertStringContainsString('<html lang="en"', $english);
        $this->assertStringContainsString('Page not found', $english);
    }

    public function test_code_written_text_and_dates_follow_the_page(): void
    {
        $page = $this->page('About', ['slug' => 'about', 'data' => ['content' => [
            ['type' => 'heading', 'anchor' => 'main', 'props' => ['text' => 'Hi', 'level' => '1']],
            ['type' => 'paragraph', 'props' => ['text' => '', 'tag' => 'p'], 'bind' => ['text' => 'entry.date|date']],
        ]], 'at' => '2026-10-30T12:00:00+00:00']);
        $this->translation($page, 'de', 'Über uns', ['slug' => 'ueber-uns', 'at' => '2026-10-30T12:00:00+00:00']);
        $this->translation($page, 'fr', 'À propos', ['slug' => 'a-propos', 'at' => '2026-10-30T12:00:00+00:00']);

        $this->assertStringContainsString('October 30, 2026', $this->get('/about')->getContent());
        $this->assertStringContainsString('Zum Inhalt springen', $german = $this->get('/de/ueber-uns')->getContent());
        $this->assertStringContainsString('30. Oktober 2026', $german);
        $this->assertStringContainsString('30 octobre 2026', $this->get('/fr/a-propos')->getContent());
    }

    public function test_what_a_translator_gets_is_only_what_visitors_read(): void
    {
        $map = StringMap::extract(['title' => 'Home', 'data' => ['tags' => ['planned'], 'content' => [
            [
                'id' => 'a',
                'type' => 'repeat',
                'label' => 'Editor name',
                'props' => ['source' => 'menu', 'menu' => 'main', 'text' => 'Read me', 'items' => ['One', 'Two']],
                'children' => [['id' => 'b', 'type' => 'button', 'props' => ['label' => 'Sign in', 'type' => 'submit']]],
                'logic' => ['on' => [['event' => 'click', 'actions' => [['do' => 'set', 'var' => 'open', 'value' => 'true']]]], 'show' => 'open'],
                'motion' => [['trigger' => ['start' => 'top bottom']]],
                'attrs' => ['role' => 'tablist', 'aria-label' => 'Say this', 'aria-selected' => 'selected'],
                'bind' => ['text' => 'entry.title', 'label' => 'Per site, {globals.plans.ext}', 'url' => '{globals.links.docs}'],
            ],
        ]]]);

        $this->assertSame(['Home', 'Read me', 'One', 'Two', 'Sign in', 'Say this', 'Per site, {globals.plans.ext}'], $map['strings']);
    }
}
