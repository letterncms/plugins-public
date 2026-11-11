<?php

declare(strict_types=1);

namespace Cms\Import\Tests;

use Illuminate\Http\UploadedFile;
use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Import\Fetcher;
use Cms\Import\Runs;
use Cms\Support\Files;
use Cms\Tests\TestCase;
use ZipArchive;

/** The Import screen: handing a file over, choosing what it becomes, previewing, importing a turn at a time, and looking back. */
final class ImportScreenTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/import', 'letterncms/seo'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Files::copyDirectory(__DIR__.'/fixtures', storage_path('app/import'));
        @unlink(storage_path('app/import/xxe.xml'));
        $this->app->instance(Fetcher::class, new class implements Fetcher
        {
            public function fetch(string $url, int $maxBytes): string
            {
                return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
            }
        });
    }

    private function ability(string $name, array $input = [], ?AbilityContext $context = null): mixed
    {
        return $this->cms()->abilities()->execute($name, $input, $context ?? AbilityContext::forUser($this->admin ??= $this->admin(), 'admin'));
    }

    private ?\Cms\Users\User $admin = null;

    private function upload(string $name, string $contents, ?\Cms\Users\User $as = null): \Illuminate\Testing\TestResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $contents);

        return $this->actingAs($as ?? ($this->admin ??= $this->admin()))->post('/api/admin/import/upload', ['file' => new UploadedFile($tmp, $name, null, null, true)], ['Accept' => 'application/json']);
    }

    /** @param array<string, string> $files name in the zip => contents */
    private function zip(array $files, ?callable $also = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $also !== null && $also($zip);
        $zip->close();

        return (string) file_get_contents($path);
    }

    public function test_a_csv_and_an_export_are_taken_in_under_a_safe_name_of_their_own(): void
    {
        $this->upload('Our Notes (final).CSV', "title;summary\nA note;Short\n")->assertStatus(201)->assertJsonPath('data', ['id' => 'b3VyLW5vdGVzLWZpbmFsLmNzdg', 'path' => 'our-notes-final.csv', 'type' => 'csv', 'size' => 27]);
        // The same name again is another file, not the first one overwritten.
        $this->upload('our notes final.csv', "title\nAnother\n")->assertStatus(201)->assertJsonPath('data.path', 'our-notes-final-2.csv');
        $this->upload('../../evil.xml', (string) file_get_contents(__DIR__.'/fixtures/wordpress.xml'))->assertStatus(201)->assertJsonPath('data.path', 'evil.xml');

        $this->assertSame("title;summary\nA note;Short\n", file_get_contents(storage_path('app/import/our-notes-final.csv')));
        $this->assertFileDoesNotExist(storage_path('evil.xml'));
        $this->assertContains('evil.xml', array_column($this->ability('import/sources')['sources'], 'path'));
    }

    public function test_what_is_not_an_export_a_csv_or_a_zip_of_markdown_is_refused(): void
    {
        $before = glob(storage_path('app/import/*'));

        foreach ([
            ['shell.php', '<?php echo 1;', 'Upload a WordPress export (.xml), a CSV file or a zip of Markdown files.'],
            ['page.html', '<html></html>', 'Upload a WordPress export (.xml), a CSV file or a zip of Markdown files.'],
            ['export.xml', '<html><body>No feed</body></html>', 'This is not a WordPress export.'],
            ['export.xml', (string) file_get_contents(__DIR__.'/fixtures/xxe.xml'), 'This is not a WordPress export.'],
            ['data.csv', "a,b\n1,\0\0\0", 'This is not a CSV file.'],
            // A picture called .csv: every byte is some Latin-1 letter, but a CSV has no control characters.
            ['photo.csv', "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR", 'This is not a CSV file.'],
            ['blank.csv', "\n\n,,,\n", 'This is not a CSV file.'],
            ['empty.csv', '', 'The file is empty.'],
            ['notes.zip', 'not a zip at all', 'The zip file could not be opened.'],
            ['notes.zip', $this->zip(['picture.png' => 'x', 'run.php' => '<?php']), 'The zip holds no Markdown files.'],
            ['notes.zip', $this->zip(['../../outside.md' => '# Out']), 'The zip holds a file outside its own folder.'],
            ['big.xml', "<?xml version=\"1.0\"?>\n<rss>".str_repeat('x', 21_000_000).'</rss>', 'The file is larger than 20 MB.'],
        ] as [$name, $contents, $message]) {
            $this->upload($name, $contents)->assertStatus(422)->assertJsonPath('error.message', $message);
        }

        $this->assertSame($before, glob(storage_path('app/import/*')), 'Nothing a refusal touched is left behind.');
        $this->assertFileDoesNotExist(storage_path('app/outside.md'));

        // Only those who may import, signed in.
        $this->upload('notes.csv', "title\nA\n", $this->userWithRole('editor'))->assertStatus(403);
        $this->assertSame($before, glob(storage_path('app/import/*')));
    }

    public function test_a_zip_is_unpacked_to_its_markdown_only(): void
    {
        $zip = $this->zip([
            'site/posts/Hello World.md' => "---\ntitle: Hello\n---\nBody",
            'site/posts/second.markdown' => '# Second',
            'site/posts/photo.jpg' => 'not text',
            'site/run.php' => '<?php',
            '__MACOSX/site/._Hello.md' => 'x',
        ], static function (ZipArchive $zip): void {
            // A link in the zip, pointing out of it.
            $zip->addFromString('site/link.md', '/etc/passwd');
            $zip->setExternalAttributesName('site/link.md', ZipArchive::OPSYS_UNIX, (0o120777 << 16));
        });

        $this->upload('My Site.zip', $zip)->assertStatus(201)->assertJsonPath('data.path', 'my-site')->assertJsonPath('data.type', 'markdown');

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(storage_path('app/import/my-site'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $this->assertFalse($file->isLink());
            $files[] = substr($file->getPathname(), strlen(storage_path('app/import/my-site')) + 1);
        }
        sort($files);

        // The one folder around everything is gone, names are safe, and nothing but Markdown came out.
        $this->assertSame(['posts/Hello-World.md', 'posts/second.markdown'], $files);

        $this->ability('import-sources/update', ['path' => 'my-site', 'options' => ['collection' => 'notes']]);
        $this->assertSame(2, $this->ability('import-sources/preview', ['path' => 'my-site'])['summary']['create']);
    }

    public function test_a_csv_is_previewed_once_its_collection_and_columns_are_chosen(): void
    {
        $source = $this->ability('import-sources/get', ['path' => 'notes.csv']);
        $this->assertSame(['id', 'Title', 'Summary', 'Tags', 'Status', 'Published At', 'Old URL'], $source['columns']);
        $this->assertFalse($source['ready']);
        // The entry's own columns are known by their names; the rest wait for a collection.
        $this->assertSame(['collection' => '', 'column_0' => '', 'column_1' => 'title', 'column_2' => '', 'column_3' => '', 'column_4' => 'status', 'column_5' => 'date', 'column_6' => ''], (array) $source['options']);

        $options = (array) $this->ability('import-sources/update', ['path' => 'notes.csv', 'options' => ['collection' => 'posts', 'column_6' => 'redirect_from']]);
        // With a collection, a column goes to the field its name leads to: Summary to the excerpt, Tags to tags.
        $this->assertSame(['posts', 'excerpt', 'tags', 'redirect_from'], [$options['collection'], $options['column_2'], $options['column_3'], $options['column_6']]);
        $this->assertTrue($this->ability('import-sources/get', ['path' => 'notes.csv'])['ready']);

        $report = $this->ability('import-sources/preview', ['path' => 'notes.csv']);
        $this->assertSame([2, 0, 0], [$report['summary']['create'], $report['summary']['update'], $report['collisions']]);
        $this->assertSame(['First note', 'Second note'], array_column($report['items'], 'title'));
        $this->assertSame(['/old/first'], array_column($this->ability('import-sources/preview', ['path' => 'notes.csv', 'of' => 'redirects'])['items'], 'from'));
        $this->assertSame([], $this->cms()->entries()->query()->working()->collection('posts')->get(), 'A preview writes nothing.');

        // A column pointed elsewhere changes the preview: the excerpt is required to publish, so without it the note comes in as a draft.
        $this->ability('import-sources/update', ['path' => 'notes.csv', 'options' => ['column_2' => '']]);
        $first = $this->ability('import-sources/preview', ['path' => 'notes.csv'])['items'][0];
        $this->assertSame('draft', $first['status']);
        $this->assertStringContainsString('Imported as a draft', (string) $first['note']);

        // An entry that is already there with the same slug is named as what the import would replace.
        $this->cms()->entryService()->create('posts', ['title' => 'First note', 'data' => ['excerpt' => 'Mine.']], $this->admin);
        $report = $this->ability('import-sources/preview', ['path' => 'notes.csv']);
        $this->assertSame([1, 1, 1], [$report['summary']['create'], $report['summary']['update'], $report['collisions']]);
        $this->assertSame([true, 'Replaces an entry with the same slug.'], [$report['items'][0]['collision'], substr((string) $report['items'][0]['note'], -37)]);
        $this->assertSame(1, $this->ability('import-sources/preview', ['path' => 'notes.csv', 'action' => 'update'])['total']);

        $this->expectException(AbilityException::class);
        $this->ability('import-sources/update', ['path' => 'notes.csv', 'options' => ['collection' => 'nowhere']]);
    }

    public function test_an_import_runs_a_turn_at_a_time_and_survives_being_left(): void
    {
        $started = $this->ability('import-runs/start', ['path' => 'wordpress.xml']);
        $this->assertSame(['running', 0], [$started['status'], $started['done']]);
        $this->assertGreaterThan(1, $records = $started['records']);
        // Starting it again, from another tab, is the same import.
        $this->assertSame($started['id'], $this->ability('import-runs/start', ['path' => 'wordpress.xml'])['id']);

        // A turn with no time at all still does one thing, so every turn gets further.
        $runs = app(Runs::class);
        $turns = 0;
        $seen = [];

        do {
            $run = Runs::summary($runs->step($started['id'], 0.0));
            $seen[] = $run['done'];
            $this->assertLessThan(40, ++$turns, 'It ends.');

            // Halfway, the page is reloaded: how far it is comes from the server.
            if ($turns === 2) {
                $this->app->forgetInstance(Runs::class);
                $this->app->forgetInstance(\Cms\Import\Importer::class);
                $this->app->forgetInstance(\Cms\Import\Ledger::class);
                $runs = app(Runs::class);
                $this->assertSame('running', $this->ability('import-runs/get', ['id' => $started['id']])['status']);
            }
        } while ($run['status'] === 'running');

        $this->assertGreaterThan(2, $turns, 'More than one turn was needed.');
        $sorted = $seen;
        sort($sorted);
        $this->assertSame($sorted, $seen, 'It only ever gets further.');
        $this->assertSame(['done', $records, $records], [$run['status'], $run['done'], $run['summary']['create']]);
        $this->assertNotNull($run['finishedAt']);

        // What an earlier turn made is not reported as unchanged by a later one.
        $report = $this->ability('import-runs/get', ['id' => $started['id']]);
        $this->assertSame(array_fill(0, $records, 'create'), array_column($report['items'], 'action'));
        $this->assertNotNull($report['items'][0]['id']);
        $this->assertGreaterThan(0, $this->ability('import-runs/get', ['id' => $started['id'], 'of' => 'media'])['total']);
        $this->assertCount($records, [...$this->cms()->entries()->query()->working()->collection('posts')->get(), ...$this->cms()->entries()->query()->working()->collection('pages')->get()]);

        // The ability does the same in one call when there is time.
        $again = $this->ability('import-runs/start', ['path' => 'wordpress.xml']);
        $this->assertNotSame($started['id'], $again['id']);
        $done = $this->ability('import-runs/step', ['id' => $again['id']]);
        $this->assertSame(['done', 0, $records], [$done['status'], $done['summary']['create'], $done['summary']['skip']]);

        // Both are in the history, newest first, and a finished one can be forgotten: what it imported stays.
        $this->assertSame([$again['id'], $started['id']], array_column($this->ability('import-runs/list')['items'], 'id'));
        $this->assertSame([['action' => 'delete', 'type' => 'import-run', 'id' => $again['id'], 'path' => 'wordpress.xml']], $this->ability('import-runs/delete', ['id' => $again['id'], 'dryRun' => true])['changes']);
        $this->ability('import-runs/delete', ['id' => $again['id']]);
        $this->assertSame([$started['id']], array_column($this->ability('import-runs/list')['items'], 'id'));
        $this->assertCount($records, [...$this->cms()->entries()->query()->working()->collection('posts')->get(), ...$this->cms()->entries()->query()->working()->collection('pages')->get()]);
    }

    public function test_an_import_can_be_canceled_and_a_source_that_cannot_be_read_never_starts(): void
    {
        $run = $this->ability('import-runs/start', ['path' => 'wordpress.xml']);

        // A few turns in: the files first, then the first entry.
        for ($turns = 0; ($now = Runs::summary(app(Runs::class)->step($run['id'], 0.0)))['done'] === 0; $turns++) {
            $this->assertSame(['running', null], [$now['status'], $now['error']]);
            $this->assertLessThan(10, $turns);
        }

        $this->assertSame([['action' => 'update', 'type' => 'import-run', 'id' => $run['id'], 'changes' => ['status' => 'canceled']]], $this->ability('import-runs/cancel', ['id' => $run['id'], 'dryRun' => true])['changes']);
        $canceled = $this->ability('import-runs/cancel', ['id' => $run['id']]);
        $this->assertSame(['canceled', 1], [$canceled['status'], $canceled['done']]);
        // A turn that comes after changes nothing, and what was imported stays.
        $this->assertSame(['canceled', 1], array_values(array_intersect_key($this->ability('import-runs/step', ['id' => $run['id']]), ['status' => 1, 'done' => 1])));
        $this->assertCount(1, [...$this->cms()->entries()->query()->working()->collection('posts')->get(), ...$this->cms()->entries()->query()->working()->collection('pages')->get()]);

        // A running import is not deleted from under itself.
        $running = $this->ability('import-runs/start', ['path' => 'wordpress.xml']);
        foreach ([['import-runs/delete', ['id' => $running['id']]], ['import-runs/delete', ['id' => $running['id'], 'dryRun' => true]], ['import-runs/start', ['path' => 'notes.csv']], ['import-runs/start', ['path' => '../secrets.json']], ['import-runs/get', ['id' => '01zzzzzzzzzzzzzzzzzzzzzzzz']]] as [$ability, $input]) {
            try {
                $this->ability($ability, $input);
                $this->fail("{$ability} went through.");
            } catch (AbilityException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertCount(2, $this->ability('import-runs/list')['items']);
    }

    public function test_a_file_that_cannot_be_fetched_is_tried_once_and_the_run_still_ends(): void
    {
        $asked = [];
        $this->app->instance(Fetcher::class, new class($asked) implements Fetcher
        {
            public function __construct(private array &$asked) {}

            public function fetch(string $url, int $maxBytes): string
            {
                $this->asked[] = $url;

                throw new \RuntimeException('The host did not answer.');
            }
        });
        $runs = app(Runs::class);
        $run = $this->ability('import-runs/start', ['path' => 'wordpress.xml']);

        // While it runs, its file is not deleted from under it.
        foreach ([[], ['dryRun' => true]] as $more) {
            try {
                $this->ability('import-sources/delete', ['path' => 'wordpress.xml', ...$more]);
                $this->fail('A file being imported was deleted.');
            } catch (AbilityException $e) {
                $this->assertSame('conflict', $e->type);
            }
        }

        // No time at all per turn: a failure is no progress, so a turn of failures still moves on to the entries.
        for ($turns = 0; ($now = Runs::summary($runs->step($run['id'], 0.0)))['status'] === 'running'; $turns++) {
            $this->assertLessThan(40, $turns, 'It ends.');
        }

        $this->assertSame('done', $now['status']);
        $this->assertSame(count(array_unique($asked)), count($asked), 'No file is asked for twice in one import.');
        $media = $this->ability('import-runs/get', ['id' => $run['id'], 'of' => 'media']);
        $this->assertSame(['failed'], array_values(array_unique(array_column($media['items'], 'action'))));
        $this->assertGreaterThan(0, $now['summary']['create']);
    }

    public function test_the_audit_log_has_a_run_start_cancel_and_finish_not_every_turn(): void
    {
        $count = static fn (string $ability) => \Illuminate\Support\Facades\DB::table('audit_log')->where('ability', $ability)->count();
        $run = $this->ability('import-runs/start', ['path' => 'wordpress.xml']);

        // A turn that finds another one under way answers with how it stands, and is no change worth a line.
        $lock = fopen(storage_path('app/import-runs/'.$run['id'].'.lock'), 'c');
        flock($lock, LOCK_EX);
        $this->assertSame('running', $this->ability('import-runs/step', ['id' => $run['id']])['status']);
        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertSame(0, $count('import-runs/step'));

        $this->assertSame('done', $this->ability('import-runs/step', ['id' => $run['id']])['status']);
        $this->assertSame([1, 1], [$count('import-runs/start'), $count('import-runs/step')]);


        $again = $this->ability('import-runs/start', ['path' => 'wordpress.xml']);
        $this->ability('import-runs/cancel', ['id' => $again['id']]);
        $this->assertSame([2, 1, 1], [$count('import-runs/start'), $count('import-runs/cancel'), $count('import-runs/step')]);
    }

    public function test_a_source_can_be_deleted_with_what_was_chosen_for_it(): void
    {
        $this->ability('import-sources/update', ['path' => 'markdown', 'options' => ['collection' => 'notes']]);
        $dry = $this->ability('import-sources/delete', ['path' => 'markdown', 'dryRun' => true]);
        $this->assertSame('full', $dry['checked']);
        $this->assertSame('import-source', $dry['changes'][0]['type']);
        $this->assertGreaterThan(1, $dry['changes'][0]['removes']['files']);
        $this->assertDirectoryExists(storage_path('app/import/markdown'));

        $this->assertSame(['deleted' => 'markdown'], $this->ability('import-sources/delete', ['path' => 'markdown']));
        $this->assertDirectoryDoesNotExist(storage_path('app/import/markdown'));
        $this->assertArrayNotHasKey('markdown', Files::readJson(storage_path('app/import-options.json')));

        $this->expectException(AbilityException::class);
        $this->ability('import-sources/delete', ['path' => '../import-options.json']);
    }

    public function test_its_places_are_views_the_admin_can_draw(): void
    {
        $admin = $this->admin ??= $this->admin();
        $views = $this->cms()->views();
        $types = static function (array $node) use (&$types): array {
            return [$node['type'], ...array_merge([], ...array_map($types, $node['children'] ?? []))];
        };

        $home = $views->resolve('import', $admin)['root'];
        $this->assertSame('import/upload', $home['props']['actions'][0]['component']);
        $this->assertSame(['ui/page', 'ui/tabs', 'ui/tab', 'ui/records', 'ui/tab', 'ui/records'], $types($home));

        // A CSV asks for its collection before anything else; then it shows what it would do and can be started.
        $csv = $views->resolve('import-file', $admin, ['id' => \Cms\Import\Importer::id('notes.csv')])['root'];
        $this->assertSame([], $csv['props']['actions'] ?? []);
        $this->assertSame('ui/empty', $csv['children'][0]['children'][0]['children'][0]['type']);
        $this->ability('import-sources/update', ['path' => 'notes.csv', 'options' => ['collection' => 'posts', 'column_6' => 'redirect_from']]);
        $csv = $views->resolve('import-file', $admin, ['id' => \Cms\Import\Importer::id('notes.csv')])['root'];
        $this->assertSame('import-runs/start', $csv['props']['actions'][0]['ability']);
        $this->assertSame(['preview', 'redirects', 'options'], array_map(static fn (array $tab) => $tab['props']['id'], $csv['children'][0]['children']));
        $fields = $csv['children'][0]['children'][2]['children'][0]['props']['fields'];
        $this->assertSame(['collection', 'column_0', 'column_1', 'column_2', 'column_3', 'column_4', 'column_5', 'column_6'], array_column($fields, 'handle'));
        $this->assertSame('Summary', $fields[3]['label']);
        $this->assertArrayHasKey('excerpt', $fields[3]['options']);

        // A file that cannot be read says why, where its preview would be.
        $this->ability('import-sources/update', ['path' => 'notes.csv', 'options' => ['column_1' => '']]);
        $broken = $views->resolve('import-file', $admin, ['id' => \Cms\Import\Importer::id('notes.csv')])['root'];
        $this->assertSame(['ui/notice', 'No column holds the title. Name one “title”, or map one to it.'], [$broken['children'][0]['children'][0]['children'][0]['type'], $broken['children'][0]['children'][0]['children'][0]['props']['title']]);

        $export = $views->resolve('import-file', $admin, ['id' => \Cms\Import\Importer::id('wordpress.xml')])['root'];
        $this->assertSame(['preview', 'media', 'redirects', 'options'], array_map(static fn (array $tab) => $tab['props']['id'], $export['children'][0]['children']));
        $this->assertSame('ui/page', $views->resolve('import-file', $admin, ['id' => \Cms\Import\Importer::id('gone.csv')])['root']['type']);

        // An import that is running is the progress piece; a finished one is its report.
        $run = $this->ability('import-runs/start', ['path' => 'wordpress.xml']);
        $this->assertSame(['ui/page', 'import/progress'], $types($views->resolve('import-run', $admin, ['id' => $run['id']])['root']));
        $this->ability('import-runs/step', ['id' => $run['id']]);
        $report = $views->resolve('import-run', $admin, ['id' => $run['id']])['root'];
        // Its files and redirects are tabs beside the entries, and tabs are drawn only as the page's one child.
        $this->assertSame(['ui/tabs'], array_column($report['children'], 'type'));
        $this->assertSame(['entries', 'media', 'redirects'], array_map(static fn (array $tab) => $tab['props']['id'], $report['children'][0]['children']));
        $entries = $report['children'][0]['children'][0]['children'];
        $this->assertSame('ui/grid', $entries[0]['type']);
        $this->assertSame($run['records'], $entries[0]['children'][0]['props']['value']);
        $this->assertNotContains('import/progress', $types($report));

        $this->assertFalse($views->opens('/import', $this->userWithRole('editor')));
    }

    public function test_its_abilities_follow_the_cores_rules(): void
    {
        $verbs = ['list', 'get', 'get-many', 'create', 'update', 'delete', 'start', 'step', 'cancel', 'report', 'preview', 'sources', 'run'];
        $mine = array_filter($this->cms()->abilities()->all(), static fn ($ability) => $ability->package === 'letterncms/import');

        $this->assertSame([
            'import-runs/cancel', 'import-runs/delete', 'import-runs/get', 'import-runs/list', 'import-runs/start', 'import-runs/step',
            'import-sources/delete', 'import-sources/get', 'import-sources/preview', 'import-sources/update',
            'import/preview', 'import/run', 'import/sources',
        ], collect(array_keys($mine))->sort()->values()->all());

        foreach ($mine as $name => $ability) {
            $verb = explode('/', $name)[1];
            $this->assertContains($verb, $verbs, $name);
            $this->assertNotNull($ability->output(), "{$name} says what it answers with");
            $this->assertSame(in_array($verb, ['list', 'get', 'report', 'preview', 'sources'], true), $ability->isReadOnly(), $name);

            if ($ability->isDestructive() || ($ability->annotations['confirm'] ?? false)) {
                $this->assertNotNull($ability->dryRun, "{$name} says in full what it would do");
            }
        }

        // An update that only the input was checked for says so.
        $this->assertSame(['input', null], array_values(array_intersect_key($this->ability('import-sources/update', ['path' => 'notes.csv', 'options' => ['collection' => 'posts'], 'dryRun' => true]), ['checked' => 1, 'changes' => 1])));
        $this->assertFalse($this->ability('import-sources/get', ['path' => 'notes.csv'])['ready'], 'And wrote nothing.');
        $this->assertSame('full', $this->ability('import-runs/start', ['path' => 'wordpress.xml', 'dryRun' => true])['checked']);
        $this->assertSame([], $this->ability('import-runs/list')['items']);
    }
}
