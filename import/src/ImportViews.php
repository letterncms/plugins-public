<?php

declare(strict_types=1);

namespace Cms\Import;

use Cms\Abilities\AbilityException;
use Cms\Admin\Views;
use Cms\Cms;
use Throwable;

/**
 * The places of Import, as data (see Cms\Admin\Views): the files and the imports made from them,
 * one file with what importing it would do, and one import with what it did. Two pieces are
 * the plugin's own: the file picker and the progress of an import that is running.
 */
final class ImportViews
{
    public function __construct(private readonly Cms $cms) {}

    // Made when a place is opened, not when the plugin boots: whoever swaps the fetcher before then is heard.
    private function importer(): Importer
    {
        return app(Importer::class);
    }

    private function options(): SourceOptions
    {
        return app(SourceOptions::class);
    }

    private function runs(): Runs
    {
        return app(Runs::class);
    }

    public function register(): void
    {
        $admin = $this->cms->admin();
        $admin->component('import/upload', ['label' => 'Upload Import File', 'description' => 'The file picker, for a WordPress export, a CSV file or a zip of Markdown into the import folder.', 'surface' => false, 'permission' => 'import.run',
            'props' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false]]);
        $admin->component('import/progress', ['label' => 'Import Progress', 'description' => 'An import that is running: how far it is, moved on while it is on screen, and the way to cancel it.', 'surface' => false, 'permission' => 'import.run',
            'props' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id'], 'additionalProperties' => false]]);

        $views = $this->cms->views();
        $views->register('import', '/import', fn () => $this->home(), permission: 'import.run');
        $views->register('import-file', '/import/files/:id', fn ($user, array $params) => $this->file(Importer::path((string) ($params['id'] ?? ''))), permission: 'import.run');
        $views->register('import-run', '/import/runs/:id', fn ($user, array $params) => $this->run((string) ($params['id'] ?? '')), permission: 'import.run');
    }

    private function home(): array
    {
        $upload = ['label' => __('Upload File'), 'name' => 'upload', 'icon' => 'upload', 'variant' => 'primary', 'component' => 'import/upload'];

        return ['type' => 'ui/page', 'props' => ['title' => __('Import'), 'icon' => 'download', 'actions' => [$upload]], 'children' => [
            ['type' => 'ui/tabs', 'children' => [
                ['type' => 'ui/tab', 'props' => ['id' => 'files', 'label' => __('Files')], 'children' => [
                    ['type' => 'ui/records', 'props' => [
                        'id' => 'import-files', 'label' => __('Files'), 'key' => 'path', 'search' => false,
                        'source' => ['ability' => 'import/sources'], 'at' => 'sources',
                        'columns' => [
                            ['key' => 'path', 'label' => __('File'), 'format' => 'strong'],
                            ['key' => 'type', 'label' => __('Kind'), 'width' => '140px', 'format' => 'badge', 'labels' => self::kinds()],
                            ['key' => 'size', 'label' => __('Size'), 'width' => '110px', 'align' => 'end', 'format' => 'bytes', 'empty' => __('Folder')],
                        ],
                        'open' => ['label' => __('Open'), 'to' => '/import/files/{row.id}'],
                        'actions' => [
                            ['label' => __('Delete File'), 'icon' => 'trash', 'variant' => 'danger', 'ability' => 'import-sources/delete', 'input' => ['path' => '{row.path}'], 'then' => 'refresh', 'success' => __('File deleted'),
                                'confirm' => ['title' => __('Delete “{row.path}”?'), 'text' => __('What it already imported stays.'), 'confirm' => __('Delete File'), 'danger' => true]],
                        ],
                        // An empty list offers its one action: the bar's Upload File.
                        'empty' => ['icon' => 'download', 'title' => __('No files yet'), 'action' => array_diff_key($upload, ['variant' => true, 'name' => true])],
                    ]],
                ]],
                ['type' => 'ui/tab', 'props' => ['id' => 'history', 'label' => __('History')], 'children' => [
                    ['type' => 'ui/records', 'props' => [
                        'id' => 'import-runs', 'label' => __('History'), 'key' => 'id', 'search' => false,
                        'source' => ['ability' => 'import-runs/list'],
                        'columns' => [
                            ['key' => 'path', 'label' => __('File'), 'format' => 'strong'],
                            ['key' => 'status', 'label' => __('Status'), 'width' => '120px', 'format' => 'badge', 'labels' => self::statuses(), 'tones' => self::TONES],
                            ['key' => 'summary.create', 'label' => __('Created'), 'width' => '100px', 'align' => 'end', 'format' => 'number'],
                            ['key' => 'summary.update', 'label' => __('Updated'), 'width' => '100px', 'align' => 'end', 'format' => 'number'],
                            ['key' => 'startedAt', 'label' => __('Started'), 'width' => '140px', 'align' => 'end', 'format' => 'ago'],
                        ],
                        'open' => ['label' => __('Open'), 'to' => '/import/runs/{row.id}'],
                        'actions' => [
                            ['label' => __('Delete Report'), 'icon' => 'trash', 'variant' => 'danger', 'ability' => 'import-runs/delete', 'input' => ['id' => '{row.id}'], 'then' => 'refresh', 'success' => __('Report deleted'), 'when' => '{row.finishedAt}',
                                'confirm' => ['title' => __('Delete this report?'), 'text' => __('What it imported stays.'), 'confirm' => __('Delete Report'), 'danger' => true]],
                        ],
                        'empty' => ['icon' => 'download', 'title' => __('No imports yet'), 'action' => array_diff_key($upload, ['variant' => true, 'name' => true])],
                    ]],
                ]],
            ]],
        ]];
    }

    private function file(string $path): array
    {
        $back = ['to' => '/import', 'label' => __('Import')];

        try {
            $source = $this->options()->source($path);
        } catch (AbilityException) {
            return Views::notFound('download', $back);
        }

        $input = ['path' => $source['path']];
        $options = ['label' => __('Open Options'), 'to' => '/import/files/'.$source['id'].'?tab=options'];
        $report = null;
        $problem = null;

        if ($this->options()->ready($source)) {
            try {
                $report = $this->importer()->plan($this->options()->input($source));
            } catch (AbilityException $e) {
                $problem = $e->getMessage();
            } catch (Throwable $e) {
                report($e);
                $problem = __('The file could not be read.');
            }
        }

        $preview = match (true) {
            $problem !== null => [['type' => 'ui/notice', 'props' => ['tone' => 'danger', 'title' => $problem, 'action' => $options]]],
            $report === null => [['type' => 'ui/empty', 'props' => ['icon' => 'download', 'title' => __('Choose a collection first'), 'action' => $options]]],
            default => [
                ...self::numbers([__('To Create') => $report['summary']['create'], __('To Update') => $report['summary']['update'], __('Unchanged') => $report['summary']['skip'],
                    __('Replacing an Entry') => count(array_filter($report['items'], static fn (array $i) => ! empty($i['collision'])))]),
                ...self::warnings($report['warnings']),
                self::entries('import-sources/preview', $input, ['create' => __('Create'), 'update' => __('Update'), 'skip' => __('Unchanged')], __('Nothing to import')),
            ],
        };

        return ['type' => 'ui/page', 'props' => ['title' => self::literal($source['path']), 'icon' => 'download', 'back' => $back, 'actions' => $report === null || self::changes($report) === 0 ? [] : [
            ['label' => __('Start Import'), 'name' => 'start', 'icon' => 'download', 'variant' => 'primary', 'ability' => 'import-runs/start', 'input' => $input, 'then' => 'open', 'to' => '/import/runs/{result.id}'],
        ]], 'children' => [['type' => 'ui/tabs', 'children' => [
            ['type' => 'ui/tab', 'props' => ['id' => 'preview', 'label' => __('Preview')], 'children' => $preview],
            ...($report !== null ? self::lists('import-sources/preview', $input, $report, ['download' => __('Fetch'), 'skip' => __('In the Library')], ['add' => __('Add'), 'skip' => __('Already There')]) : []),
            ['type' => 'ui/tab', 'props' => ['id' => 'options', 'label' => __('Options')], 'children' => [
                ['type' => 'ui/form', 'props' => ['source' => ['ability' => 'import-sources/get', 'input' => $input], 'valuesAt' => 'options', 'fields' => $this->fields($source), 'save' => ['ability' => 'import-sources/update', 'input' => $input, 'as' => 'options']]],
            ]],
        ]]]];
    }

    private function run(string $id): array
    {
        $back = ['to' => '/import?tab=history', 'label' => __('Import')];

        try {
            $run = $this->runs()->find($id);
        } catch (AbilityException) {
            return Views::notFound('download', $back);
        }

        $page = static fn (array $children) => ['type' => 'ui/page', 'props' => ['title' => self::literal($run['path']), 'icon' => 'download', 'back' => $back], 'children' => $children];

        if ($run['status'] === Runs::RUNNING) {
            return $page([['type' => 'import/progress', 'props' => ['id' => $id]]]);
        }

        $input = ['id' => $id];
        $summary = Runs::summary($run)['summary'];

        $report = [
            ...($run['status'] === Runs::FAILED ? [['type' => 'ui/notice', 'props' => ['tone' => 'danger', 'title' => (string) ($run['error'] ?? __('The import stopped on an error.'))]]] : []),
            ...($run['status'] === Runs::CANCELED ? [['type' => 'ui/notice', 'props' => ['tone' => 'warning', 'title' => __('Canceled before it finished'), 'action' => ['label' => __('Open File'), 'to' => '/import/files/'.Importer::id($run['path'])]]]] : []),
            ...self::numbers([__('Created') => $summary['create'], __('Updated') => $summary['update'], __('Unchanged') => $summary['skip'], __('Failed') => $summary['failed']]),
            ...self::warnings($run['warnings']),
            self::entries('import-runs/get', $input, ['create' => __('Created'), 'update' => __('Updated'), 'skip' => __('Unchanged'), 'failed' => __('Failed')], __('Nothing was imported'), opens: true),
        ];
        $lists = self::lists('import-runs/get', $input, $run, ['download' => __('Fetched'), 'skip' => __('In the Library'), 'failed' => __('Failed')], ['add' => __('Added'), 'skip' => __('Already There')]);

        // With files or redirects beside the entries, the page is tabs, as a file's is (tabs are drawn only as the
        // page's one child): the report's numbers head the entries.
        return $page($lists === [] ? $report : [['type' => 'ui/tabs', 'children' => [
            ['type' => 'ui/tab', 'props' => ['id' => 'entries', 'label' => __('Entries')], 'children' => $report],
            ...$lists,
        ]]]);
    }

    /** What importing would change: entries made or updated, files fetched, redirects added. Nothing, and there is nothing to start. */
    private static function changes(array $report): int
    {
        return (int) $report['summary']['create'] + (int) $report['summary']['update'] + (int) $report['summary']['media'] + (int) $report['summary']['redirects'];
    }

    private const array TONES = ['running' => 'info', 'done' => 'success', 'failed' => 'danger', 'canceled' => 'neutral'];

    private const string GOOD = 'success';

    private const array ACTION_TONES = ['create' => self::GOOD, 'update' => 'info', 'skip' => 'neutral', 'failed' => 'danger', 'download' => 'info', 'add' => self::GOOD];

    /** @return array<string, string> */
    private static function statuses(): array
    {
        return ['running' => __('Running'), 'done' => __('Done'), 'failed' => __('Failed'), 'canceled' => __('Canceled')];
    }

    /** @return array<string, string> */
    private static function kinds(): array
    {
        return ['wordpress' => __('WordPress'), 'csv' => __('CSV'), 'markdown' => __('Markdown')];
    }

    /** @param array<string, int> $numbers label => count */
    private static function numbers(array $numbers): array
    {
        return [['type' => 'ui/grid', 'props' => ['columns' => 4], 'children' => array_map(static fn (string $label, int $value) => ['type' => 'ui/stat', 'props' => ['label' => $label, 'value' => $value]], array_keys($numbers), $numbers)]];
    }

    /** What the source said about itself, in one notice: a few lines at most. */
    private static function warnings(array $warnings): array
    {
        return $warnings === [] ? [] : [['type' => 'ui/notice', 'props' => ['tone' => 'warning', 'title' => self::literal((string) $warnings[0]), ...(count($warnings) > 1 ? ['text' => self::literal(implode(' ', array_slice($warnings, 1, 4)))] : [])]]];
    }

    /** The entries of a report, a page at a time. */
    private static function entries(string $ability, array $input, array $actions, string $empty, bool $opens = false): array
    {
        return ['type' => 'ui/records', 'props' => [
            'id' => 'import-entries', 'label' => __('Entries'), 'key' => 'key', 'search' => false,
            'source' => ['ability' => $ability, 'input' => [...$input, 'of' => 'entries']],
            'filters' => [['param' => 'action', 'label' => __('Show'), 'all' => __('All'), 'options' => array_map(static fn ($value, $label) => ['value' => $value, 'label' => $label], array_keys($actions), $actions)]],
            'paged' => ['perPage' => 50],
            'columns' => [
                // What is unusual about a record goes with its title, and only where there is something to say.
                ['key' => 'title', 'label' => __('Title'), 'format' => 'strong', 'warning' => 'note'],
                ['key' => 'collection', 'label' => __('Collection'), 'width' => '140px', 'format' => 'muted', 'labels' => array_map(static fn ($c) => $c->title, cms()->collections()->all())],
                ['key' => 'action', 'label' => __('Result'), 'width' => '130px', 'format' => 'badge', 'labels' => $actions, 'tones' => self::ACTION_TONES],
                ['key' => 'status', 'label' => __('Status'), 'width' => '120px', 'format' => 'badge', 'labels' => ['published' => __('Published'), 'draft' => __('Draft'), 'scheduled' => __('Scheduled')], 'tones' => ['published' => 'success']],
            ],
            ...($opens ? ['open' => ['label' => __('Open'), 'to' => '/content/{row.collection}/{row.id}', 'when' => '{row.id}']] : []),
            'empty' => ['icon' => 'download', 'title' => $empty],
        ]];
    }

    /** Tabs for the files and redirects of a report, each only when it has any. */
    private static function lists(string $ability, array $input, array $report, array $media, array $redirects): array
    {
        return [
            ...($report['media'] === [] ? [] : [['type' => 'ui/tab', 'props' => ['id' => 'media', 'label' => __('Media')], 'children' => [['type' => 'ui/records', 'props' => [
                'id' => 'import-media', 'label' => __('Media'), 'key' => 'url', 'search' => false, 'paged' => ['perPage' => 50],
                'source' => ['ability' => $ability, 'input' => [...$input, 'of' => 'media']],
                'columns' => [
                    ['key' => 'url', 'label' => __('Address'), 'format' => 'code', 'warning' => 'note'],
                    ['key' => 'action', 'label' => __('Result'), 'width' => '140px', 'format' => 'badge', 'labels' => $media, 'tones' => self::ACTION_TONES],
                ],
            ]]]]]),
            ...($report['redirects'] === [] ? [] : [['type' => 'ui/tab', 'props' => ['id' => 'redirects', 'label' => __('Redirects')], 'children' => [['type' => 'ui/records', 'props' => [
                'id' => 'import-redirects', 'label' => __('Redirects'), 'key' => 'from', 'search' => false, 'paged' => ['perPage' => 50],
                'source' => ['ability' => $ability, 'input' => [...$input, 'of' => 'redirects']],
                'columns' => [
                    ['key' => 'from', 'label' => __('From'), 'format' => 'code'],
                    ['key' => 'to', 'label' => __('To'), 'format' => 'code'],
                    ['key' => 'action', 'label' => __('Result'), 'width' => '160px', 'format' => 'badge', 'labels' => $redirects, 'tones' => self::ACTION_TONES],
                ],
            ]]]]]),
        ];
    }

    /** The options of a source: where its entries go, and for a CSV what each column is. */
    private function fields(array $source): array
    {
        $collections = [];

        foreach ($this->cms->collections()->all() as $collection) {
            $collections[$collection->handle] = $collection->title;
        }

        if ($source['type'] === 'wordpress') {
            return [
                ['handle' => 'collection', 'type' => 'select', 'label' => __('Posts Go To'), 'options' => $collections, 'required' => true],
                ['handle' => 'pages', 'type' => 'select', 'label' => __('Pages Go To'), 'options' => $collections, 'placeholder' => __('Leave Out')],
                ['handle' => 'media', 'type' => 'toggle', 'label' => __('Fetch Images and Files')],
                ['handle' => 'redirects', 'type' => 'toggle', 'label' => __('Redirect Old Addresses')],
            ];
        }

        $fields = [['handle' => 'collection', 'type' => 'select', 'label' => __('Collection'), 'options' => $collections, 'required' => true, 'placeholder' => __('Choose a Collection')]];
        $chosen = $this->cms->collections()->find((string) $this->options()->get($source)['collection']);

        // What a column can be: the entry's own, or one of the collection's fields once one is chosen.
        $targets = ['title' => __('Title'), 'slug' => __('Slug'), 'status' => __('Status'), 'date' => __('Publish Date'), 'body' => __('Body'), 'redirect_from' => __('Old Address')];

        foreach ($chosen?->blueprint()->fields() ?? [] as $field) {
            $targets[$field->handle] ??= (string) ($field->label ?: $field->handle);
        }

        foreach ($this->options()->columns($source) as $i => $column) {
            $fields[] = ['handle' => SourceOptions::column($i), 'type' => 'select', 'label' => self::literal($column), 'options' => $targets, 'placeholder' => __('Leave Out'), 'section' => __('Columns'), 'width' => 'half'];
        }

        return $fields;
    }

    /** Words from a file, written as they are: a `{` in a name is no template. */
    private static function literal(string $text): string
    {
        return (string) preg_replace('/\{([^{}]*)\}/', '{{$1}}', $text);
    }
}
