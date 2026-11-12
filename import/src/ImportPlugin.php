<?php

declare(strict_types=1);

namespace Cms\Import;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Packages\Plugin;
use Cms\Support\Schema\Shape;

/**
 * Brings a site over from somewhere else: a WordPress export, a CSV file or a folder of Markdown.
 * Every import is previewed first and can be run again. See Importer.
 */
final class ImportPlugin extends Plugin
{
    /** How many items an answer lists; the summary counts them all. */
    private const int LISTED = 200;

    public function register(): void
    {
        $this->app->singleton(Ledger::class);
        $this->app->singleton(Importer::class);
        $this->app->singleton(SourceOptions::class);
        $this->app->singleton(Uploads::class);
        $this->app->singleton(Runs::class);
        $this->app->bindIf(Fetcher::class, SafeFetch::class);
    }

    public function boot(): void
    {
        // Whoever imports writes entries in any collection, as themselves: a site's admins, until its roles say otherwise.
        $this->cms()->permissions()->register('import.run', 'Import Content', 'Import', roles: ['admin']);
        $this->abilities();
        $this->screenAbilities();
        $this->app->make(ImportViews::class)->register();

        // With the other tools of a site, under Settings: it is used once, not every day.
        $admin = $this->cms()->admin();
        $admin->nav('import', ['path' => '/import', 'icon' => 'download', 'section' => 'system', 'order' => 86, 'permission' => 'import.run']);
        $admin->command('import', ['label' => 'Open Import', 'icon' => 'download', 'path' => '/import', 'permission' => 'import.run', 'keywords' => ['wordpress', 'csv', 'markdown', 'migrate']]);
        $this->adminModule();

        // The file picker's target: multipart, so a route of its own beside the abilities.
        Route::middleware(['web', 'cms.installed', 'auth:web', 'cms.admin', 'cms.locale', 'throttle:30,1'])->post('/api/admin/import/upload', Http\UploadController::class);

        // An import someone started goes on after the response, where the response can be finished first:
        // with the screen closed too. Elsewhere the screen's own turns carry it.
        $this->app->terminating(function (): void {
            if ($this->app->runningUnitTests() || $this->app->runningInConsole()) {
                return;
            }

            $finish = array_find(['fastcgi_finish_request', 'litespeed_finish_request'], static fn (string $function): bool => function_exists($function));

            try {
                if ($finish !== null && Cache::add('cms:import:turn', 1, 5) && ($running = $this->app->make(Runs::class)->running()) !== []) {
                    $finish();
                    $this->app->make(Runs::class)->step($running[0]['id']);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // What the Audit Log calls each change, once it has happened.
        // A run is moved on by a turn every few seconds: the Audit Log keeps the one that finishes it, not every turn.
        $this->cms()->hooks()->addFilter('audit.unrecorded', static fn (bool $skip, string $ability, array $input = [], mixed $result = null): bool => $skip || ($ability === 'import-runs/step' && ($result['status'] ?? null) === Runs::RUNNING));

        $this->cms()->hooks()->addFilter('audit.past', static fn (array $past): array => $past + [
            'import/run' => __('Ran Import'),
            'import-sources/update' => __('Chose Import Options'),
            'import-sources/delete' => __('Deleted Import File'),
            'import-runs/start' => __('Started Import'),
            'import-runs/step' => __('Finished Import'),
            'import-runs/cancel' => __('Canceled Import'),
            'import-runs/delete' => __('Deleted Import Report'),
        ]);

        // The home-screen widget. It is not on anyone's home screen unless they add it,
        // and with nothing imported and nothing waiting it draws nothing.
        $this->cms()->admin()->widget('import-status', ['label' => 'Import', 'description' => __('What has been imported so far, and the files waiting in the import folder.'), 'icon' => 'download', 'column' => 'side', 'order' => 87, 'permission' => 'import.run',
            'view' => function () {
                $counts = $this->app->make(Ledger::class)->counts();
                $waiting = count($this->app->make(Importer::class)->sources());

                return $counts['entries'] + $counts['media'] + $waiting === 0 ? ['type' => 'ui/stack'] : ['type' => 'ui/grid', 'props' => ['columns' => 3], 'children' => [
                    ['type' => 'ui/stat', 'props' => ['label' => __('Entries Imported'), 'value' => $counts['entries']]],
                    ['type' => 'ui/stat', 'props' => ['label' => __('Files Imported'), 'value' => $counts['media']]],
                    ['type' => 'ui/stat', 'props' => ['label' => __('Sources Waiting'), 'value' => $waiting]],
                ]];
            }]);

        if ($this->app->runningInConsole()) {
            $this->commands([Console\ImportCommand::class]);
        }
    }

    private function abilities(): void
    {
        $a = $this->cms()->abilities();
        $importer = fn (): Importer => $this->app->make(Importer::class);
        $input = ['type' => 'object', 'properties' => [
            'type' => ['type' => 'string', 'enum' => Importer::TYPES],
            'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500, 'description' => 'A file or folder in storage/app/import/, as import/sources lists it.'],
            'collection' => ['type' => 'string', 'description' => 'Where entries go. Required for csv and markdown; for wordpress it takes the posts (default: posts).'],
            'pages' => ['type' => 'string', 'description' => 'wordpress: the collection for pages (default: pages).'],
            'types' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'maxLength' => 200], 'description' => 'wordpress: other post types to bring along, as {post type: collection}.'],
            'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'maxLength' => 200], 'description' => 'Where a source’s value goes when it is not the field of the same name, as {name: field handle}; "" leaves it out. wordpress has excerpt, tags, categories and image.'],
            'map' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'maxLength' => 200], 'description' => 'csv: {column: field handle}, or title, slug, status, date, body, redirect_from; "" leaves a column out.'],
            'key' => ['type' => 'string', 'description' => 'csv: the column that tells rows apart (default: id, else the slug, else the title).'],
            'body' => ['type' => 'string', 'description' => 'The field that takes the main text (default: the collection’s body field, or its first blocks, Markdown or rich text field).'],
            'locale' => ['type' => 'string', 'description' => 'The language entries are made in (default: the site’s).'],
            'media' => ['type' => 'boolean', 'default' => true, 'description' => 'wordpress: fetch the files entries show into the media library.'],
            'redirects' => ['type' => 'boolean', 'default' => true, 'description' => 'Add redirects from old addresses to SEO’s list.'],
        ], 'required' => ['type', 'path']];
        $short = static function (array $report): array {
            foreach (['items', 'media', 'redirects'] as $list) {
                if (count($report[$list]) > self::LISTED) {
                    $report['truncated'][$list] = count($report[$list]);
                    $report[$list] = array_slice($report[$list], 0, self::LISTED);
                }
            }

            return $report;
        };

        // What a preview and a run answer with: the same report, the run's with what it made.
        $report = Shape::of([
            'dryRun' => Shape::BOOLEAN, 'complete' => Shape::BOOLEAN, 'records' => Shape::INTEGER, 'type' => Shape::enum(...Importer::TYPES),
            'summary' => ['create' => Shape::INTEGER, 'update' => Shape::INTEGER, 'skip' => Shape::INTEGER, 'failed' => Shape::INTEGER, 'media' => Shape::INTEGER, 'redirects' => Shape::INTEGER, 'warnings' => Shape::INTEGER],
            'redirectsWritten' => Shape::BOOLEAN,
            'warnings' => [Shape::STRING],
            'items' => [[
                'key' => Shape::STRING, 'collection' => Shape::STRING, 'title' => Shape::STRING, 'action' => Shape::enum('create', 'update', 'skip', 'failed'), 'status' => Shape::STRING,
                'id' => Shape::STRING_OR_NULL, 'uri' => Shape::STRING_OR_NULL, 'warnings' => [Shape::STRING], 'repaired?' => Shape::INTEGER, 'collision?' => Shape::BOOLEAN, 'error?' => Shape::STRING,
            ]],
            'media' => [['url' => Shape::STRING, 'action' => Shape::enum('download', 'skip', 'failed'), 'id?' => Shape::STRING, 'to?' => Shape::STRING, 'error?' => Shape::STRING]],
            'redirects' => [['from' => Shape::STRING, 'to' => Shape::STRING_OR_NULL, 'action' => Shape::STRING]],
            'truncated?' => ['items?' => Shape::INTEGER, 'media?' => Shape::INTEGER, 'redirects?' => Shape::INTEGER],
        ]);

        $a->register('import/sources', [
            'label' => 'List Import Sources',
            'description' => 'The files and folders in storage/app/import/ that can be imported, each with the type it looks like: a WordPress export (.xml), a CSV file, or a folder of Markdown.',
            'permission' => 'import.run',
            'annotations' => ['readOnly' => true],
            'output' => Shape::of(['folder' => Shape::STRING, 'sources' => [['id' => Shape::STRING, 'path' => Shape::STRING, 'type' => Shape::enum(...Importer::TYPES), 'size' => Shape::INTEGER_OR_NULL]]]),
            'handler' => fn () => ['folder' => 'storage/app/import', 'sources' => $importer()->sources()],
        ]);

        $a->register('import/preview', [
            'label' => 'Preview Import',
            'description' => 'What importing a source would do, without doing it: per record whether it would be created, updated or skipped (unchanged since the last import), its status, and warnings; the files it would fetch; the redirects it would add. Always look at this before import/run. Lists at most '.self::LISTED.' of each; summary counts them all.',
            'permission' => 'import.run',
            'annotations' => ['readOnly' => true],
            'input' => $input,
            'output' => $report,
            'handler' => fn (array $in) => $short($importer()->plan($in)),
        ]);

        $a->register('import/run', [
            'label' => 'Run Import',
            'description' => 'Import a source, as import/preview described it. Entries are created or updated through the entry service, in the name of whoever runs it; published records are published, with their original dates. Safe to run again: what was imported before is updated when its source changed and skipped when it did not, and a large import that was cut short picks up where it stopped. Large sources are better run from the command line (php cms import).',
            'permission' => 'import.run',
            'annotations' => ['idempotent' => true, 'confirm' => true],
            'input' => $input,
            // The plan is the run without its writes: it refuses the same source and names every entry, file and redirect.
            'dryRun' => static function (array $in) use ($importer): array {
                $plan = $importer()->plan($in);
                $wanted = static fn (array $rows, array $actions) => array_values(array_filter($rows, static fn (array $row) => in_array($row['action'], $actions, true)));

                return [
                    ...array_map(static fn (array $i) => ['action' => $i['action'], 'type' => 'entry', 'collection' => $i['collection'], 'id' => $i['id'], 'title' => $i['title'], 'status' => $i['status']], $wanted($plan['items'], ['create', 'update'])),
                    ...array_map(static fn (array $m) => ['action' => 'create', 'type' => 'media', 'url' => $m['url']], $wanted($plan['media'], ['download'])),
                    ...array_map(static fn (array $r) => ['action' => 'create', 'type' => 'redirect', 'from' => $r['from'], 'to' => $r['to']], $plan['redirectsWritten'] ? $wanted($plan['redirects'], ['add']) : []),
                ];
            },
            'output' => $report,
            'handler' => fn (array $in, AbilityContext $context) => $short($importer()->run($in, $context->user)),
        ]);
    }

    /** A page of rows, the way every paged ability answers. */
    private static function page(array $rows, array $in): array
    {
        $per = (int) $in['perPage'];
        $pages = max(1, (int) ceil(count($rows) / $per));
        $page = min((int) $in['page'], $pages);

        return ['items' => array_slice(array_values($rows), ($page - 1) * $per, $per), 'total' => count($rows), 'page' => $page, 'pages' => $pages];
    }

    /** A record of a report as a list shows it: what is wrong or unusual about it in one line. */
    private static function row(array $item): array
    {
        $notes = [...$item['warnings'], ...(! empty($item['collision']) ? [__('Replaces an entry with the same slug.')] : []), ...(isset($item['error']) ? [$item['error']] : [])];

        return ['key' => $item['key'], 'collection' => $item['collection'], 'title' => $item['title'], 'action' => $item['action'], 'status' => $item['status'], 'id' => $item['id'], 'uri' => $item['uri'], 'collision' => ! empty($item['collision']), 'note' => $notes === [] ? null : implode(' ', $notes)];
    }

    /** The lists of a report (a plan's or a run's), one at a time. */
    private static function listed(array $report, array $in): array
    {
        $rows = match ($in['of']) {
            'media' => array_map(static fn (array $m) => ['url' => $m['url'], 'action' => $m['action'], 'note' => $m['error'] ?? null], array_values($report['media'])),
            'redirects' => array_map(static fn (array $r) => ['from' => $r['from'], 'to' => $r['to'], 'action' => $r['action']], array_values($report['redirects'])),
            default => array_map(self::row(...), array_values($report['items'])),
        };

        return self::page($in['action'] === 'all' ? $rows : array_filter($rows, static fn (array $row) => $row['action'] === $in['action']), $in);
    }

    /** What the Import screen does, step by step: each an ability, so an agent can do the same. */
    private function screenAbilities(): void
    {
        $a = $this->cms()->abilities();
        $importer = fn (): Importer => $this->app->make(Importer::class);
        $options = fn (): SourceOptions => $this->app->make(SourceOptions::class);
        $runs = fn (): Runs => $this->app->make(Runs::class);
        // A source is deleted only while nothing imports it: a run reads it on every turn.
        $idle = static function (string $path) use ($options, $runs): array {
            $found = $options()->source($path);

            return $runs()->importing($found['path']) ? throw AbilityException::conflict(__('“:path” is being imported. Cancel the import first.', ['path' => $found['path']])) : $found;
        };
        $path = ['type' => 'string', 'minLength' => 1, 'maxLength' => 500, 'description' => 'A file or folder in storage/app/import/, as import/sources lists it.'];
        $id = ['type' => 'string', 'pattern' => '^[0-9a-z]{26}$'];
        $paging = [
            'of' => ['type' => 'string', 'enum' => ['entries', 'media', 'redirects'], 'default' => 'entries'],
            'action' => ['type' => 'string', 'default' => 'all', 'description' => 'Only rows with this outcome: create, update, skip or failed for entries; download, skip or failed for media; add or what else was done for redirects.'],
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'perPage' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
        ];
        $counts = ['create' => Shape::INTEGER, 'update' => Shape::INTEGER, 'skip' => Shape::INTEGER, 'failed' => Shape::INTEGER, 'media' => Shape::INTEGER, 'redirects' => Shape::INTEGER, 'warnings' => Shape::INTEGER];
        $row = Shape::either(
            ['key' => Shape::STRING, 'collection' => Shape::STRING, 'title' => Shape::STRING, 'action' => Shape::STRING, 'status' => Shape::STRING, 'id' => Shape::STRING_OR_NULL, 'uri' => Shape::STRING_OR_NULL, 'collision' => Shape::BOOLEAN, 'note' => Shape::STRING_OR_NULL],
            ['url' => Shape::STRING, 'action' => Shape::STRING, 'note' => Shape::STRING_OR_NULL],
            ['from' => Shape::STRING, 'to' => Shape::STRING_OR_NULL, 'action' => Shape::STRING],
        );
        $source = ['id' => Shape::STRING, 'path' => Shape::STRING, 'type' => Shape::enum(...Importer::TYPES), 'size' => Shape::INTEGER_OR_NULL];
        $chosenInput = ['type' => 'object', 'description' => 'As import-sources/get answers `options`; what is left out stays.'];
        $chosen = Shape::open('What was chosen for the source: `collection`; for wordpress also `pages`, `media` and `redirects`; for csv one `column_<n>` per column of the header, saying what that column is (a field handle, title, slug, status, date, body, redirect_from, or "" to leave it out).');
        $run = [
            'id' => Shape::STRING, 'path' => Shape::STRING, 'type' => Shape::STRING, 'status' => Shape::enum(Runs::RUNNING, Runs::DONE, Runs::FAILED, Runs::CANCELED), 'error' => Shape::STRING_OR_NULL,
            'by' => Shape::STRING_OR_NULL, 'startedAt' => Shape::STRING_OR_NULL, 'finishedAt' => Shape::STRING_OR_NULL, 'records' => Shape::INTEGER, 'done' => Shape::INTEGER,
            'summary' => $counts, 'redirectsWritten' => Shape::BOOLEAN, 'warnings' => [Shape::STRING],
        ];
        // The plan of a source with what was chosen for it, read afresh: what is on the site may have changed since the last look.
        $plan = static fn (array $source): array => $importer()->plan($options()->input($source));

        $a->register('import-sources/get', [
            'label' => 'Get Import Source',
            'description' => 'One source in the import folder with what was chosen for it (`options`), the columns of a CSV, and whether it can be previewed yet (`ready`: a CSV and a folder of Markdown need a collection first).',
            'permission' => 'import.run',
            'annotations' => ['readOnly' => true],
            'input' => ['type' => 'object', 'properties' => ['path' => $path], 'required' => ['path']],
            'output' => Shape::of([...$source, 'options' => $chosen, 'columns' => [Shape::STRING], 'ready' => Shape::BOOLEAN]),
            'handler' => static function (array $in) use ($options) {
                $found = $options()->source($in['path']);

                return [...$found, 'options' => (object) $options()->get($found), 'columns' => $options()->columns($found), 'ready' => $options()->ready($found)];
            },
        ]);

        $a->register('import-sources/update', [
            'label' => 'Update Import Source',
            'description' => 'Choose where a source’s entries go and, for a CSV, what each column is, as import-sources/get describes `options`. What is left out stays. Nothing is imported: import-sources/preview shows what would be, import-runs/start does it.',
            'permission' => 'import.run',
            'input' => ['type' => 'object', 'properties' => ['path' => $path, 'options' => $chosenInput], 'required' => ['path', 'options']],
            'output' => $chosen,
            'handler' => static fn (array $in) => (object) $options()->save($options()->source($in['path']), (array) $in['options']),
        ]);

        $a->register('import-sources/delete', [
            'label' => 'Delete Import Source',
            'description' => 'Remove a file or folder from the import folder, and what was chosen for it. What it already imported stays.',
            'permission' => 'import.run',
            'annotations' => ['destructive' => true],
            'input' => ['type' => 'object', 'properties' => ['path' => $path], 'required' => ['path']],
            'output' => Shape::of(['deleted' => Shape::STRING]),
            'dryRun' => fn (array $in): array => [['action' => 'delete', 'type' => 'import-source', 'path' => ($found = $idle($in['path']))['path'], 'removes' => ['files' => $this->app->make(Uploads::class)->count($importer()->resolve($found['path']))]]],
            'handler' => function (array $in) use ($options, $idle) {
                $found = $idle($in['path']);
                $this->app->make(Uploads::class)->delete($found['path']);
                $options()->forget($found['path']);

                return ['deleted' => $found['path']];
            },
        ]);

        $a->register('import-sources/preview', [
            'label' => 'Preview Import Source',
            'description' => 'What importing a source would do with what was chosen for it, a page at a time: its entries (`of: entries`, each to create, update or skip, with `collision` where it would replace an entry that has the same slug and a `note` on what is unusual), the files it would fetch (`media`) or the redirects it would add (`redirects`), and `summary` counting all of them.',
            'permission' => 'import.run',
            'annotations' => ['readOnly' => true],
            'input' => ['type' => 'object', 'properties' => ['path' => $path, ...$paging], 'required' => ['path']],
            'output' => Shape::page($row, ['summary' => $counts, 'records' => Shape::INTEGER, 'collisions' => Shape::INTEGER, 'redirectsWritten' => Shape::BOOLEAN, 'warnings' => [Shape::STRING]]),
            'handler' => static function (array $in) use ($options, $plan) {
                $report = $plan($options()->source($in['path']));

                return [...self::listed($report, $in), 'summary' => $report['summary'], 'records' => $report['records'], 'collisions' => count(array_filter($report['items'], static fn (array $i) => ! empty($i['collision']))), 'redirectsWritten' => $report['redirectsWritten'], 'warnings' => $report['warnings']];
            },
        ]);

        $a->register('import-runs/list', [
            'label' => 'List Imports',
            'description' => 'The imports started from the Import screen or with import-runs/start, newest first: their source, how they stand (running, done, failed, canceled) and what they made.',
            'permission' => 'import.run',
            'annotations' => ['readOnly' => true],
            'input' => ['type' => 'object', 'properties' => ['page' => $paging['page'], 'perPage' => $paging['perPage']]],
            'output' => Shape::page($run),
            'handler' => static fn (array $in) => self::page(array_map(Runs::summary(...), $runs()->all()), ['page' => $in['page'] ?? 1, 'perPage' => $in['perPage'] ?? 50]),
        ]);

        $a->register('import-runs/get', [
            'label' => 'Get Import',
            'description' => 'One import: how far it is (`done` of `records`), what it made so far, and a page of its entries, files or redirects (`of`).',
            'permission' => 'import.run',
            'annotations' => ['readOnly' => true],
            'input' => ['type' => 'object', 'properties' => ['id' => $id, ...$paging], 'required' => ['id']],
            'output' => Shape::page($row, $run),
            'handler' => static fn (array $in) => [...self::listed($found = $runs()->find($in['id']), $in), ...Runs::summary($found)],
        ]);

        $a->register('import-runs/start', [
            'label' => 'Start Import',
            'description' => 'Start importing a source with what was chosen for it. It runs a few seconds at a time: call import-runs/step until its status is no longer "running" (the Import screen does). A source that is already being imported answers with that import.',
            'permission' => 'import.run',
            'annotations' => ['confirm' => true],
            'input' => ['type' => 'object', 'properties' => ['path' => $path], 'required' => ['path']],
            'output' => Shape::of($run),
            // The plan is the run without its writes.
            'dryRun' => static function (array $in) use ($options, $importer): array {
                $report = $importer()->plan($options()->input($options()->source($in['path'])));
                $wanted = static fn (array $rows, array $actions) => array_values(array_filter($rows, static fn (array $row) => in_array($row['action'], $actions, true)));

                return [
                    ...array_map(static fn (array $i) => ['action' => $i['action'], 'type' => 'entry', 'collection' => $i['collection'], 'id' => $i['id'], 'title' => $i['title'], 'status' => $i['status']], $wanted($report['items'], ['create', 'update'])),
                    ...array_map(static fn (array $m) => ['action' => 'create', 'type' => 'media', 'url' => $m['url']], $wanted($report['media'], ['download'])),
                    ...array_map(static fn (array $r) => ['action' => 'create', 'type' => 'redirect', 'from' => $r['from'], 'to' => $r['to']], $report['redirectsWritten'] ? $wanted($report['redirects'], ['add']) : []),
                ];
            },
            'handler' => static fn (array $in, AbilityContext $context) => Runs::summary($runs()->start($in['path'], $context->user)),
        ]);

        $a->register('import-runs/step', [
            'label' => 'Continue Import',
            'description' => 'Import for a few seconds more and answer with how the import stands. Safe to call again and from two places at once: one turn runs at a time.',
            'permission' => 'import.run',
            'annotations' => ['idempotent' => true],
            'input' => ['type' => 'object', 'properties' => ['id' => $id], 'required' => ['id']],
            'output' => Shape::of($run),
            'handler' => static fn (array $in) => Runs::summary($runs()->step($in['id'])),
        ]);

        $a->register('import-runs/cancel', [
            'label' => 'Cancel Import',
            'description' => 'Stop an import that is running. What it has imported stays; starting the source again carries on.',
            'permission' => 'import.run',
            'input' => ['type' => 'object', 'properties' => ['id' => $id], 'required' => ['id']],
            'output' => Shape::of($run),
            'dryRun' => static fn (array $in): array => ($found = $runs()->find($in['id']))['status'] === Runs::RUNNING ? [['action' => 'update', 'type' => 'import-run', 'id' => $found['id'], 'changes' => ['status' => Runs::CANCELED]]] : [],
            'handler' => static fn (array $in) => Runs::summary($runs()->cancel($in['id'])),
        ]);

        $a->register('import-runs/delete', [
            'label' => 'Delete Import',
            'description' => 'Forget a finished import’s report. What it imported stays.',
            'permission' => 'import.run',
            'annotations' => ['destructive' => true],
            'input' => ['type' => 'object', 'properties' => ['id' => $id], 'required' => ['id']],
            'output' => Shape::of(['deleted' => Shape::STRING]),
            'dryRun' => static function (array $in) use ($runs): array {
                $found = $runs()->find($in['id']);

                if ($found['status'] === Runs::RUNNING) {
                    throw \Cms\Abilities\AbilityException::conflict(__('This import is still running. Cancel it first.'));
                }

                return [['action' => 'delete', 'type' => 'import-run', 'id' => $found['id'], 'path' => $found['path']]];
            },
            'handler' => static function (array $in) use ($runs) {
                $runs()->delete($in['id']);

                return ['deleted' => $in['id']];
            },
        ]);
    }
}
