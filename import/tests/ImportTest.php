<?php

declare(strict_types=1);

namespace Cms\Import\Tests;

use Illuminate\Support\Facades\Artisan;
use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Import\Fetcher;
use Cms\Import\HtmlToMarkdown;
use Cms\Modules\Media\MediaItem;
use Cms\Support\Files;
use Cms\Tests\TestCase;
use RuntimeException;

final class ImportTest extends TestCase
{
    /** @var list<string> */
    private array $fetched = [];

    /** The old site is unreachable. */
    private bool $down = false;

    protected function plugins(): array
    {
        return ['letterncms/import', 'letterncms/seo'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Files::copyDirectory(__DIR__.'/fixtures', storage_path('app/import'));
        $this->fetched = [];
        $this->app->instance(Fetcher::class, new class($this->fetched, $this->down) implements Fetcher
        {
            public function __construct(private array &$fetched, private bool &$down) {}

            public function fetch(string $url, int $maxBytes): string
            {
                $this->fetched[] = $url;

                if ($this->down || str_contains($url, 'missing')) {
                    throw new RuntimeException('The server answered 404.');
                }

                // One red pixel.
                return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
            }
        });
    }

    private function ability(string $name, array $input = []): mixed
    {
        return $this->cms()->abilities()->execute($name, $input, AbilityContext::system());
    }

    private function entries(string $collection): array
    {
        return $this->cms()->entries()->query()->working()->collection($collection)->get();
    }

    private function redirects(): array
    {
        return array_column($this->ability('redirects/list'), 'to', 'from');
    }

    public function test_sources_lists_what_the_folder_holds(): void
    {
        $sources = array_column($this->ability('import/sources')['sources'], 'type', 'path');

        $this->assertSame('wordpress', $sources['wordpress.xml']);
        $this->assertSame('csv', $sources['notes.csv']);
        $this->assertSame('markdown', $sources['markdown']);
    }

    public function test_a_preview_reports_and_changes_nothing(): void
    {
        $report = $this->ability('import/preview', ['type' => 'wordpress', 'path' => 'wordpress.xml']);

        $this->assertTrue($report['dryRun']);
        $this->assertSame(['create' => 4, 'update' => 0, 'skip' => 0, 'failed' => 0, 'media' => 1, 'redirects' => 3], array_diff_key($report['summary'], ['warnings' => 0]));
        $this->assertSame(['Hello & welcome', 'Classic post', 'About', 'Team'], array_column($report['items'], 'title'));
        $this->assertSame('https://old.example.com/wp-content/uploads/2024/05/quay.png', $report['media'][0]['url']);
        $this->assertContains('/2024/05/hello-welcome', array_column($report['redirects'], 'from'));
        $this->assertStringContainsString('product', implode(' ', $report['warnings']));
        // The classic post's shortcode is named, on the post it is in.
        $this->assertStringContainsString('gallery', implode(' ', $report['items'][1]['warnings']));

        $this->assertSame([], $this->entries('posts'));
        $this->assertSame([], $this->entries('pages'));
        $this->assertSame(0, MediaItem::query()->count());
        $this->assertSame([], $this->fetched);
        $this->assertSame([], $this->redirects());
        $this->assertFileDoesNotExist(storage_path('app/import-ledger.json'));
    }

    public function test_a_dry_run_names_what_the_import_would_make_and_refuses_what_it_would(): void
    {
        $report = $this->ability('import/run', ['type' => 'wordpress', 'path' => 'wordpress.xml', 'dryRun' => true]);

        $this->assertSame(['dryRun', 'ability', 'checked', 'input', 'changes'], array_keys($report));
        $this->assertSame('full', $report['checked']);
        $by = static fn (string $type) => array_values(array_filter($report['changes'], static fn (array $c) => $c['type'] === $type));
        $this->assertSame(['Hello & welcome', 'Classic post', 'About', 'Team'], array_column($by('entry'), 'title'));
        $this->assertSame(['create'], array_values(array_unique(array_column($by('entry'), 'action'))));
        $this->assertSame(['https://old.example.com/wp-content/uploads/2024/05/quay.png'], array_column($by('media'), 'url'));
        $this->assertContains('/2024/05/hello-welcome', array_column($by('redirect'), 'from'));

        $this->assertSame([], $this->entries('posts'));
        $this->assertSame(0, MediaItem::query()->count());
        $this->assertSame([], $this->fetched);
        $this->assertSame([], $this->redirects());
        $this->assertFileDoesNotExist(storage_path('app/import-ledger.json'));

        try {
            $this->ability('import/run', ['type' => 'csv', 'path' => 'wordpress.xml', 'collection' => 'nowhere', 'dryRun' => true]);
            $this->fail('A dry run took a collection that does not exist.');
        } catch (AbilityException $e) {
            $this->assertSame('invalid_input', $e->type);
        }

        // What ran is no longer in a dry run of the same source.
        $this->ability('import/run', ['type' => 'wordpress', 'path' => 'wordpress.xml']);
        $this->assertSame([], $this->ability('import/run', ['type' => 'wordpress', 'path' => 'wordpress.xml', 'dryRun' => true])['changes']);
    }

    public function test_wordpress_brings_posts_pages_media_and_redirects(): void
    {
        $report = $this->ability('import/run', ['type' => 'wordpress', 'path' => 'wordpress.xml']);

        $this->assertFalse($report['dryRun']);
        $this->assertSame(4, $report['summary']['create']);
        $this->assertSame(0, $report['summary']['failed']);

        $posts = array_column($this->entries('posts'), null, 'slug');
        $hello = $posts['hello-welcome'];
        $this->assertSame('Hello & welcome', $hello->title);
        $this->assertSame('published', $hello->status);
        $this->assertSame('2024-05-02T08:30:00+00:00', $hello->publishedAt->toIso8601String());
        $this->assertSame('We are open again.', $hello->data['excerpt']);
        $this->assertSame(['News'], $hello->data['tags']);

        // The text as Markdown, its image at the library's address, nothing a page could run.
        $image = MediaItem::query()->sole();
        $this->assertSame('import', $image->folder);
        $this->assertSame('The quay at dawn', $image->alt);
        $this->assertStringContainsString('We are **open** again. Read [about us](https://old.example.com/about/).', $hello->data['body']);
        $this->assertStringContainsString('## Opening hours', $hello->data['body']);
        $this->assertStringContainsString("- Monday\n- Tuesday", $hello->data['body']);
        $this->assertStringContainsString('![The quay]('.$image->url().')', $hello->data['body']);
        $this->assertStringNotContainsString('alert', $hello->data['body']);
        $this->assertSame(['https://old.example.com/wp-content/uploads/2024/05/quay.png'], $this->fetched);

        // A draft stays one; the classic editor's blank lines are paragraphs.
        $classic = $posts['classic-post'];
        $this->assertSame('draft', $classic->status);
        $this->assertStringContainsString("with a line break.\n\nSecond paragraph", $classic->data['body']);

        // Pages keep their place under each other, whatever order the export lists them in.
        $pages = array_column($this->entries('pages'), null, 'slug');
        $this->assertSame($pages['about']->id, $pages['team']->parentId);
        $this->assertSame('/about/team', $pages['team']->uri);

        // Old addresses lead to the new ones; one that is the same needs none.
        $this->assertSame(['/2024/05/hello-welcome' => $hello->uri], $this->redirects());
        $this->assertTrue($report['redirectsWritten']);
    }

    public function test_running_again_changes_nothing_and_a_changed_source_updates(): void
    {
        $input = ['type' => 'wordpress', 'path' => 'wordpress.xml'];
        $this->ability('import/run', $input);
        $before = array_map(static fn ($e) => [$e->id, $e->version()], $this->entries('posts'));

        $again = $this->ability('import/run', $input);

        $this->assertSame(['create' => 0, 'update' => 0, 'skip' => 4, 'failed' => 0, 'media' => 0, 'redirects' => 0], array_diff_key($again['summary'], ['warnings' => 0]));
        $this->assertSame($before, array_map(static fn ($e) => [$e->id, $e->version()], $this->entries('posts')));
        $this->assertSame(1, MediaItem::query()->count());
        $this->assertCount(1, $this->fetched);
        $this->assertCount(1, $this->redirects());

        // The export changes: that post is updated in place, the rest left alone.
        $file = storage_path('app/import/wordpress.xml');
        file_put_contents($file, str_replace('We are open again.', 'We reopened.', (string) file_get_contents($file)));
        $changed = $this->ability('import/run', $input);

        $this->assertSame(1, $changed['summary']['update']);
        $this->assertSame(3, $changed['summary']['skip']);
        $this->assertCount(2, $this->entries('posts'));
        $this->assertSame('We reopened.', array_column($this->entries('posts'), null, 'slug')['hello-welcome']->data['excerpt']);
    }

    public function test_an_entry_with_the_same_slug_is_taken_over_not_doubled(): void
    {
        $this->ability('entries/create', ['collection' => 'pages', 'title' => 'About', 'slug' => 'about']);

        $report = $this->ability('import/run', ['type' => 'wordpress', 'path' => 'wordpress.xml']);

        $this->assertSame('update', array_column($report['items'], 'action', 'title')['About']);
        $this->assertCount(2, $this->entries('pages'));
    }

    public function test_what_cannot_be_published_comes_in_as_a_draft(): void
    {
        // Posts need an excerpt; this row has none.
        file_put_contents(storage_path('app/import/posts.csv'), "title,status,body\nNo excerpt,published,Text\n");

        $report = $this->ability('import/run', ['type' => 'csv', 'path' => 'posts.csv', 'collection' => 'posts']);

        $this->assertSame('draft', $report['items'][0]['status']);
        $this->assertStringContainsString('excerpt', $report['items'][0]['warnings'][0]);
        $this->assertSame('draft', $this->entries('posts')[0]->status);
        $this->assertSame('Text', $this->entries('posts')[0]->data['body']);
    }

    public function test_a_file_that_cannot_be_fetched_is_reported_and_the_rest_goes_on(): void
    {
        $file = storage_path('app/import/wordpress.xml');
        file_put_contents($file, str_replace('2024/05/quay', '2024/05/missing', (string) file_get_contents($file)));

        $report = $this->ability('import/run', ['type' => 'wordpress', 'path' => 'wordpress.xml']);

        $this->assertSame('failed', $report['media'][0]['action']);
        $this->assertSame(4, $report['summary']['create']);
        $this->assertSame(0, MediaItem::query()->count());
    }

    public function test_csv_maps_columns_to_fields(): void
    {
        $input = ['type' => 'csv', 'path' => 'notes.csv', 'collection' => 'pages', 'map' => ['Old URL' => 'redirect_from', 'Tags' => '']];
        $preview = $this->ability('import/preview', $input);

        $this->assertSame(2, $preview['summary']['create']);
        $this->assertCount(2, $preview['warnings']);
        $this->assertSame([], $this->entries('pages'));

        $this->ability('import/run', $input);
        $pages = array_column($this->entries('pages'), null, 'slug');

        $this->assertSame(['first-note', 'second-note'], array_keys($pages));
        $this->assertSame('Short one', $pages['first-note']->data['summary']);
        $this->assertSame('published', $pages['first-note']->status);
        $this->assertSame('2024-03-01', $pages['first-note']->publishedAt->toDateString());
        $this->assertSame('draft', $pages['second-note']->status);
        $this->assertSame(['/old/first' => '/first-note'], $this->redirects());

        $this->assertSame(2, $this->ability('import/run', $input)['summary']['skip']);
        $this->assertCount(2, $this->entries('pages'));
    }

    public function test_markdown_reads_a_folder_with_front_matter(): void
    {
        $input = ['type' => 'markdown', 'path' => 'markdown', 'collection' => 'posts'];
        $report = $this->ability('import/run', $input);

        $this->assertSame(3, $report['summary']['create']);
        $this->assertStringContainsString('unknown_key', implode(' ', $report['warnings']));
        $posts = array_column($this->entries('posts'), null, 'slug');

        $hello = $posts['hello'];
        $this->assertSame('Hello from Markdown', $hello->title);
        $this->assertSame('published', $hello->status);
        $this->assertSame('2024-05-01', $hello->publishedAt->toDateString());
        $this->assertSame(['one', 'two'], $hello->data['tags']);
        $this->assertSame("Some **bold** text.\n\n## A heading", trim($hello->data['body']));
        $this->assertSame(['/blog/2024/hello.html' => $hello->uri], $this->redirects());

        // No front matter: the first heading is the title. Published needs an excerpt, so it waits as a draft.
        $this->assertSame('A plain file', $posts['plain']->title);
        $this->assertSame('No front matter here.', trim($posts['plain']->data['body']));
        $this->assertSame('draft', $posts['plain']->status);
        $this->assertSame('draft', $posts['draft']->status);

        $this->assertSame(3, $this->ability('import/run', $input)['summary']['skip']);
        $this->assertCount(3, $this->entries('posts'));
    }

    public function test_a_document_type_or_entity_is_refused_unread(): void
    {
        try {
            $this->ability('import/preview', ['type' => 'wordpress', 'path' => 'xxe.xml']);
            $this->fail('An export with a document type was read.');
        } catch (AbilityException $e) {
            $this->assertStringContainsString('document type', $e->getMessage());
        }

        $this->assertSame([], $this->entries('posts'));
    }

    public function test_only_the_import_folder_is_read(): void
    {
        file_put_contents(storage_path('app/secret.xml'), '<rss><channel/></rss>');
        symlink(storage_path('app/secret.xml'), storage_path('app/import/link.xml'));
        symlink(storage_path('app'), storage_path('app/import/up'));

        foreach (['../secret.xml', 'markdown/../../secret.xml', storage_path('app/secret.xml'), 'link.xml', 'up/secret.xml', 'nothing.xml', ''] as $path) {
            try {
                $this->ability('import/preview', ['type' => 'wordpress', 'path' => $path]);
                $this->fail("Read {$path}.");
            } catch (AbilityException $e) {
                $this->assertSame('invalid_input', $e->type, $path);
            }
        }

        $this->assertNotContains('link.xml', array_column($this->ability('import/sources')['sources'], 'path'));
    }

    public function test_importing_takes_the_permission(): void
    {
        $editor = $this->userWithRole('editor');

        $this->expectException(AbilityException::class);
        $this->cms()->abilities()->execute('import/run', ['type' => 'wordpress', 'path' => 'wordpress.xml'], AbilityContext::forUser($editor, 'rest'));
    }

    public function test_the_command_previews_and_runs(): void
    {
        $this->assertSame(0, Artisan::call('import', ['type' => 'wordpress', 'path' => 'wordpress.xml', '--dry-run' => true]));
        $this->assertStringContainsString('Dry run: 4 to create', Artisan::output());
        $this->assertSame([], $this->entries('posts'));

        // Any path on the machine, for whoever is at its command line.
        $this->assertSame(0, Artisan::call('import', ['type' => 'markdown', 'path' => __DIR__.'/fixtures/markdown', '--collection' => 'posts', '--yes' => true]));
        $this->assertStringContainsString('Imported: 3 created', Artisan::output());
        $this->assertCount(3, $this->entries('posts'));

        $this->assertSame(1, Artisan::call('import', ['type' => 'csv', 'path' => 'notes.csv', '--collection' => 'nowhere']));
    }

    public function test_html_becomes_markdown(): void
    {
        $this->assertSame("# Title\n\nA *b* [c](/d) `e`  \nf", HtmlToMarkdown::convert('<h1>Title</h1><p>A <em>b</em> <a href="/d">c</a> <code>e</code><br>f</p>'));
        $this->assertSame("1. One\n  - Nested\n2. Two", HtmlToMarkdown::convert('<ol><li>One<ul><li>Nested</li></ul></li><li>Two</li></ol>'));
        $this->assertSame("> Quoted\n\n```php\necho 1;\n```", HtmlToMarkdown::convert('<blockquote><p>Quoted</p></blockquote><pre><code class="language-php">echo 1;</code></pre>'));
        $this->assertSame("| A | B |\n| --- | --- |\n| 1 | 2 |", HtmlToMarkdown::convert('<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>'));
        // A link that would run script is only its text; markup characters in text stay text.
        $this->assertSame('click 2 \* 3', HtmlToMarkdown::convert('<p><a href="javascript:alert(1)">click</a> 2 * 3</p>'));
        $this->assertSame("![A photo](/a.jpg)\n\n*Its caption*", HtmlToMarkdown::convert('[caption id="x"]<img src="/a.jpg" alt="A photo"> Its caption[/caption]'));
    }

    public function test_a_file_that_failed_is_fetched_by_a_later_run_and_put_into_its_entry(): void
    {
        $input = ['type' => 'wordpress', 'path' => 'wordpress.xml'];
        $this->down = true;
        $first = $this->ability('import/run', $input);

        $this->assertSame('failed', $first['media'][0]['action']);
        $this->assertStringContainsString('old.example.com/wp-content/uploads', array_column($this->entries('posts'), null, 'slug')['hello-welcome']->data['body']);

        // Still down: nothing to repair, so nothing is touched.
        $this->assertSame(4, $this->ability('import/run', $input)['summary']['skip']);

        $this->down = false;
        $plan = $this->ability('import/preview', $input);
        $this->assertSame([1, 3, 1], [$plan['summary']['update'], $plan['summary']['skip'], $plan['summary']['media']], 'A preview says what the run will repair.');

        $second = $this->ability('import/run', $input);
        $this->assertSame(['create' => 0, 'update' => 1, 'skip' => 3, 'failed' => 0, 'media' => 1], array_intersect_key($second['summary'], ['create' => 0, 'update' => 0, 'skip' => 0, 'failed' => 0, 'media' => 0]));
        $repaired = array_values(array_filter($second['items'], static fn (array $item) => isset($item['repaired'])));
        $this->assertSame([['Hello & welcome', 1]], array_map(static fn (array $item) => [$item['title'], $item['repaired']], $repaired));
        $this->assertStringContainsString('![The quay]('.MediaItem::query()->sole()->url().')', array_column($this->entries('posts'), null, 'slug')['hello-welcome']->data['body']);

        $third = $this->ability('import/run', $input);
        $this->assertSame([0, 4, 0], [$third['summary']['update'], $third['summary']['skip'], $third['summary']['media']]);
    }

    public function test_the_ledger_is_written_once_a_run_and_keeps_what_another_run_wrote(): void
    {
        $file = storage_path('app/import-ledger.json');
        $ledger = app(\Cms\Import\Ledger::class);
        $ledger->rememberEntry('mine', '01aaaaaaaaaaaaaaaaaaaaaaaa', 'h1');
        $ledger->rememberMedia('https://old.example.com/a.png', 'm1');

        $this->assertFileDoesNotExist($file, 'Nothing is written per record.');
        $this->assertSame('01aaaaaaaaaaaaaaaaaaaaaaaa', $ledger->entry('mine')['id'], 'The run itself knows what it made.');

        // Another run finished in the meantime.
        file_put_contents($file, json_encode(['entries' => ['theirs' => ['id' => '01bbbbbbbbbbbbbbbbbbbbbbbb', 'hash' => 'h2']], 'media' => ['https://old.example.com/b.png' => 'm2']]));
        $ledger->flush();

        $written = json_decode((string) file_get_contents($file), true);
        $this->assertSame(['theirs', 'mine'], array_keys($written['entries']));
        $this->assertSame(['https://old.example.com/b.png', 'https://old.example.com/a.png'], array_keys($written['media']));
        $this->assertSame('01bbbbbbbbbbbbbbbbbbbbbbbb', $ledger->entry('theirs')['id']);

        // A whole run: one write, at its end.
        $before = hash_file('xxh128', $file);
        $seen = [];
        app(\Cms\Import\Importer::class)->run(['type' => 'markdown', 'path' => 'markdown', 'collection' => 'notes'], null, false, function () use (&$seen, $file): void {
            $seen[] = hash_file('xxh128', $file);
        });
        $this->assertNotSame([], $seen);
        $this->assertSame([$before], array_values(array_unique($seen)), 'Untouched while the run goes.');
        $this->assertNotSame($before, hash_file('xxh128', $file));
        $this->assertArrayHasKey('theirs', json_decode((string) file_get_contents($file), true)['entries']);
    }

    public function test_an_ability_reads_a_smaller_export_than_the_command_line(): void
    {
        $file = storage_path('app/import/big.xml');
        $handle = fopen($file, 'wb');
        fwrite($handle, (string) file_get_contents(storage_path('app/import/wordpress.xml')));
        ftruncate($handle, \Cms\Import\Sources\Wxr::MAX_BYTES_REQUEST + 1);
        fclose($handle);

        try {
            $this->ability('import/preview', ['type' => 'wordpress', 'path' => 'big.xml']);
            $this->fail('An ability read an export over its cap.');
        } catch (AbilityException $e) {
            $this->assertSame('invalid_input', $e->type);
            $this->assertStringContainsString('20 MB', $e->getMessage());
        }

        $this->assertLessThan(\Cms\Import\Sources\Wxr::MAX_BYTES, \Cms\Import\Sources\Wxr::MAX_BYTES_REQUEST);
    }

    public function test_names_that_are_not_text_are_refused(): void
    {
        foreach ([
            ['type' => 'csv', 'path' => 'notes.csv', 'collection' => 'notes', 'map' => ['title' => ['nested']]],
            ['type' => 'csv', 'path' => 'notes.csv', 'collection' => 'notes', 'fields' => ['excerpt' => 7]],
            ['type' => 'wordpress', 'path' => 'wordpress.xml', 'types' => ['product' => ['posts']]],
        ] as $input) {
            foreach (['import/preview', 'import/run'] as $ability) {
                try {
                    $this->ability($ability, $input);
                    $this->fail($ability.' took '.json_encode($input));
                } catch (AbilityException $e) {
                    $this->assertSame('invalid_input', $e->type);
                }
            }

            // The importer itself refuses too, for the command line, which no schema guards.
            try {
                app(\Cms\Import\Importer::class)->plan($input);
                $this->fail('The importer took '.json_encode($input));
            } catch (AbilityException $e) {
                $this->assertSame('invalid_input', $e->type);
            }
        }

        $this->assertSame([], $this->entries('notes'));
    }

    public function test_its_home_screen_widget_draws_nothing_until_there_is_something_to_say(): void
    {
        $widget = fn () => $this->cms()->views()->resolve('widget.import-status', $this->admin())['root'];
        \Cms\Support\Files::deleteDirectory(storage_path('app/import'));

        $this->assertSame(['type' => 'ui/stack', 'props' => []], $widget(), 'Nothing imported, nothing waiting: nothing drawn.');

        \Cms\Support\Files::copyDirectory(__DIR__.'/fixtures', storage_path('app/import'));
        $this->ability('import/run', ['type' => 'wordpress', 'path' => 'wordpress.xml']);

        $this->assertSame(['Entries Imported' => 4, 'Files Imported' => 1, 'Sources Waiting' => count($this->ability('import/sources')['sources'])], array_column(array_column($widget()['children'], 'props'), 'value', 'label'));
    }

    public function test_every_ability_says_what_it_answers_with(): void
    {
        // The test case checks each answer against its schema as the other tests run: a report that drifts from it fails there.
        foreach (['import/sources', 'import/preview', 'import/run'] as $name) {
            $this->assertNotNull($this->cms()->abilities()->get($name)->output(), $name);
        }
    }

    public function test_every_change_has_a_past_tense_label_for_the_audit_log(): void
    {
        $past = $this->cms()->hooks()->filter('audit.past', []);
        $changes = array_filter($this->cms()->abilities()->all(), static fn ($ability) => $ability->package === 'letterncms/import' && ! $ability->isReadOnly());

        $this->assertNotSame([], $changes);

        foreach ($changes as $name => $ability) {
            $this->assertIsString($past[$name] ?? null, "{$name} has no past-tense label: add it to the audit.past filter.");
        }
    }
}
