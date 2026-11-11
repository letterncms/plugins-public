<?php

declare(strict_types=1);

namespace Cms\Import;

use Cms\Abilities\AbilityContext;
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
        $this->app->bindIf(Fetcher::class, SafeFetch::class);
    }

    public function boot(): void
    {
        // Whoever imports writes entries in any collection, as themselves: a site's admins, until its roles say otherwise.
        $this->cms()->permissions()->register('import.run', 'Import Content', 'Import', roles: ['admin']);
        $this->abilities();

        // The home-screen widget every plugin has (PackageManager::problems). It is not on anyone's home screen unless they add it,
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
            'body' => ['type' => 'string', 'description' => 'The field that takes the main text (default: the collection’s body, or its first blocks, Markdown or rich text field).'],
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
            'dryRun' => Shape::BOOLEAN, 'type' => Shape::enum(...Importer::TYPES),
            'summary' => ['create' => Shape::INTEGER, 'update' => Shape::INTEGER, 'skip' => Shape::INTEGER, 'failed' => Shape::INTEGER, 'media' => Shape::INTEGER, 'redirects' => Shape::INTEGER, 'warnings' => Shape::INTEGER],
            'redirectsWritten' => Shape::BOOLEAN,
            'warnings' => [Shape::STRING],
            'items' => [[
                'key' => Shape::STRING, 'collection' => Shape::STRING, 'title' => Shape::STRING, 'action' => Shape::enum('create', 'update', 'skip', 'failed'), 'status' => Shape::STRING,
                'id' => Shape::STRING_OR_NULL, 'uri' => Shape::STRING_OR_NULL, 'warnings' => [Shape::STRING], 'repaired?' => Shape::INTEGER, 'error?' => Shape::STRING,
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
            'output' => Shape::of(['folder' => Shape::STRING, 'sources' => [['path' => Shape::STRING, 'type' => Shape::enum(...Importer::TYPES), 'size' => Shape::INTEGER_OR_NULL]]]),
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
}
