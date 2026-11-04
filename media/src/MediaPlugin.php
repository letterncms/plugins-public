<?php

declare(strict_types=1);

namespace Cms\Media;

use Illuminate\Support\Facades\Route;
use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Media\Http\MediaController;
use Cms\Packages\Plugin;

final class MediaPlugin extends Plugin
{
    public function register(): void
    {
        foreach ([ImageProcessor::class, SvgSanitizer::class, Variants::class, MediaStore::class, MediaManifest::class, MediaReferences::class] as $service) {
            $this->app->singleton($service);
        }

        $this->cms()->whenAvailable(\Cms\Content\Fields\FieldTypeRegistry::class, static fn ($types) => $types->register(new MediaField));
    }

    public function boot(): void
    {
        $p = $this->cms()->permissions();
        $p->register('media.view', __('View the Media Library'), __('Media'));
        $p->register('media.upload', __('Upload Files'), __('Media'));
        $p->register('media.edit', 'Edit Media Details', 'Media');
        $p->register('media.delete', 'Delete Files', 'Media');

        $this->settingsSchema();
        $this->routes();
        $this->rendering();
        $this->abilities();
        $this->mediaAsContent();

        $admin = $this->cms()->admin();
        $admin->nav('media', ['path' => '/media', 'icon' => 'media', 'section' => 'content', 'order' => 70, 'pinned' => true, 'permission' => 'media.view']);
        $admin->command('upload-media', ['label' => 'Upload Files', 'icon' => 'upload', 'path' => '/media?upload=1', 'permission' => 'media.upload']);
        $admin->widget('media-recent', ['label' => 'Recent Uploads', 'description' => __('The latest files in the media library.'), 'icon' => 'media', 'column' => 'main', 'order' => 40, 'permission' => 'media.view']);
        $this->places();
        $this->adminModule(import: '@letterncms/media');

        // Files in the workspace's search, for those who may see them: each opens the library at it.
        $this->cms()->hooks()->addFilter('workspace.search', static fn (array $sections, string $query, \Cms\Users\User $user) => mb_strlen($query) < 2 || ! $user->hasPermission('media.view') ? $sections : [...$sections, [
            'id' => 'media', 'title' => __('Media'),
            'items' => MediaItem::query()->where(static fn ($w) => $w->where('name', 'like', "%{$query}%")->orWhere('alt', 'like', "%{$query}%")->orWhere('filename', 'like', "%{$query}%"))->latest()->limit(6)->get()
                ->map(static fn (MediaItem $m) => ['label' => $m->name, 'detail' => $m->filename, 'icon' => $m->isImage() ? 'image' : 'file', 'to' => '/media?search='.rawurlencode($m->name)])->all(),
        ]]);

        $this->cms()->context()->register('cms://media', 'Media library', 'The 50 most recent files with ids, alt text and sizes. Use ids in media fields and image blocks.', fn () => MediaItem::query()->latest()->limit(50)->get()->map->toPublic()->all());
    }

    /**
     * The Media place, as data (see Cms\Admin\Views): its name and size, Upload in its bar, and
     * the library (the plugin's `media/library`: the grid, filters, details and uploads).
     */
    private function places(): void
    {
        $admin = $this->cms()->admin();
        $none = ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
        $admin->component('media/library', ['label' => 'Media library', 'description' => 'Every file, as a grid or a table, to search, filter by kind and folder, open and delete.', 'surface' => false, 'permission' => 'media.view', 'props' => $none]);
        $admin->component('media/upload', ['label' => 'Upload Files', 'description' => 'The file picker, for files into the library’s folder on view.', 'surface' => false, 'permission' => 'media.upload', 'props' => $none]);

        $this->cms()->views()->register('media', '/media', static fn () => ['type' => 'ui/page', 'props' => [
            'title' => __('Media'), 'icon' => 'media', 'width' => 'wide',
            // How many the library shows: its filters are in the address.
            'count' => ['source' => ['ability' => 'media/list', 'input' => ['perPage' => 1, 'search' => '{query.search}', 'kind' => '{query.kind}', 'folder' => '{query.folder}']], 'at' => 'total'],
        ], 'children' => [
            ['type' => 'ui/tabs', 'children' => [
                ['type' => 'ui/tab', 'props' => ['id' => 'library', 'label' => __('Library'), 'actions' => [
                    ['label' => __('Upload'), 'icon' => 'upload', 'variant' => 'primary', 'component' => 'media/upload', 'can' => 'media.upload'],
                ]], 'children' => [['type' => 'media/library']]],
            ]],
        ]], permission: 'media.view');
    }

    /** content/media comes in with git or FTP: the table follows on sync, install, a full build or an admin visit. */
    private function mediaAsContent(): void
    {
        $import = fn () => $this->app->make(MediaManifest::class)->import();

        foreach (['cms.sync', 'site.build.before'] as $event) {
            $this->cms()->hooks()->on($event, $import);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([Console\MediaCheckCommand::class]);
        }

        $this->app->terminating(function () {
            $request = $this->app->bound('request') ? $this->app->make('request') : null;

            if ($request && ! $this->app->runningInConsole() && $request->is('admin', 'admin/*', 'api/admin/*') && \Cms\Install\Installer::installed()) {
                $manifest = $this->app->make(MediaManifest::class);
                $manifest->stale() && $manifest->import(5.0);
            }
        });
    }

    /** A page asks for an id the table doesn't know, on a site just deployed by FTP: look, at most once a minute. */
    private function lookAgain(string $id): ?MediaItem
    {
        $stamp = storage_path('media-import.stamp');

        if ((is_file($stamp) && time() - (int) filemtime($stamp) < 60) || ! ($manifest = $this->app->make(MediaManifest::class))->stale()) {
            return null;
        }

        @touch($stamp);
        $manifest->import(5.0);

        return MediaItem::query()->find($id);
    }

    private function settingsSchema(): void
    {
        $this->cms()->settingsRegistry()->register('media', fields: [
            ['handle' => 'max_upload_mb', 'type' => 'number', 'label' => 'Max Upload Size', 'suffix' => 'MB', 'default' => 32, 'min' => 1, 'max' => 512],
            ['handle' => 'quality', 'type' => 'number', 'display' => 'slider', 'label' => 'Image Quality', 'default' => 78, 'min' => 40, 'max' => 95],
            // The switches together, one group of rows.
            ['handle' => 'avif', 'type' => 'toggle', 'label' => 'Serve AVIF Images', 'default' => true, 'instructions' => 'Smaller than WebP; slower to generate.'],
            ['handle' => 'strip_metadata', 'type' => 'toggle', 'label' => 'Remove Photo Metadata', 'default' => true, 'instructions' => 'Location and camera details.'],
        ], icon: 'media', description: __('Uploads and responsive images.'), package: $this->manifest->name, order: 30, home: 'media');

        // The server's own limit, in the reader's language (settings are registered at boot, before it is known).
        $this->cms()->hooks()->addFilter('settings.fields', static fn (array $fields, string $namespace) => $namespace !== 'media' ? $fields : array_map(
            static fn (array $field) => $field['handle'] === 'max_upload_mb' && ($limit = self::serverLimitMb()) !== null ? [...$field, 'instructions' => __('Your server allows up to :size MB.', ['size' => $limit])] : $field,
            $fields,
        ));
    }

    /** PHP's upload limit (`64M`, `2G`, bytes) in MB, like the field it is shown under; null when there is none. */
    private static function serverLimitMb(): ?int
    {
        $value = trim((string) ini_get('upload_max_filesize'));
        $bytes = (float) $value * match (strtolower(substr($value, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return $bytes > 0 ? max(1, (int) floor($bytes / 1024 ** 2)) : null;
    }

    private function routes(): void
    {
        Route::middleware(['web', 'cms.installed', 'auth:web', 'cms.admin', 'cms.locale', 'throttle:60,1'])->post('/api/admin/media/upload', [MediaController::class, 'upload']);
        Route::middleware(['api', 'auth:token', 'throttle:60,1'])->post('/api/v1/media/upload', [MediaController::class, 'upload']);
        Route::get('/_cms/img/{id}/{file}', [MediaController::class, 'variant'])->where('id', '[0-9a-z]{26}');
    }

    /** Responsive images for blocks and templates (`media.img` / `media.url` filters). */
    private function rendering(): void
    {
        $hooks = $this->cms()->hooks();
        $resolve = function (mixed $value): ?MediaItem {
            $id = is_array($value) ? ($value[0] ?? null) : $value;

            if ($id instanceof Image) {
                return $id->item;
            }

            if (! is_string($id)) {
                return null;
            }

            // A /media/ URL of a file with a record gets the same responsive markup as its id.
            if (str_starts_with($id, '/media/')) {
                $item = MediaItem::query()->where('path', ltrim((string) parse_url($id, PHP_URL_PATH), '/'))->first();
                $item && cms()->hooks()->dispatch('render.depend', 'media:'.$item->id);

                return $item;
            }

            if (! preg_match('/^[0-9a-z]{26}$/i', $id)) {
                return null;
            }

            $id = strtolower($id);
            $item = MediaItem::query()->find($id) ?? $this->lookAgain($id);
            cms()->hooks()->dispatch('render.depend', 'media:'.$id);

            return $item && is_file(public_path($item->path)) ? $item : null;
        };

        $hooks->addFilter('media.img', function ($html, mixed $image, string $alt = '', array $options = []) use ($resolve) {
            $item = $resolve($image);

            return $item ? $this->app->make(Variants::class)->picture($item, $alt !== '' ? $alt : null, $options) : $html;
        });

        // Custom SVG in the Icon block goes through the same sanitizer as uploads.
        $hooks->addFilter('blocks.svg', fn (string $svg, string $source) => $svg !== '' ? $svg : $this->app->make(SvgSanitizer::class)->clean($source));

        $hooks->addFilter('media.url', static function ($url, mixed $image) use ($resolve) {
            $item = $resolve($image);

            return $item ? $item->url() : $url;
        });
    }

    private function abilities(): void
    {
        $a = $this->cms()->abilities();
        $store = fn () => $this->app->make(MediaStore::class);
        $package = $this->manifest->name;
        $public = static fn (MediaItem $m) => $m->toPublic();

        $a->register('media/list', [
            'label' => 'List Media',
            'description' => 'Browse the media library with search, filters and sorting.',
            'permission' => 'media.view',
            'annotations' => ['readOnly' => true],
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => [
                'search' => ['type' => 'string', 'maxLength' => 120],
                'kind' => ['type' => 'string', 'enum' => ['all', 'image', 'video', 'audio', 'pdf', 'file'], 'default' => 'all'],
                'folder' => ['type' => ['string', 'null']],
                'sort' => ['type' => 'string', 'enum' => ['name', '-name', 'size', '-size', 'folder', '-folder', 'created_at', '-created_at', 'updated_at', '-updated_at'], 'default' => '-created_at'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'perPage' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 60],
            ]],
            'handler' => function (array $in) use ($public) {
                $sort = $in['sort'];
                $q = MediaItem::query()->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id');

                if (! empty($in['search'])) {
                    $term = '%'.$in['search'].'%';
                    $q->where(static fn ($w) => $w->where('name', 'like', $term)->orWhere('alt', 'like', $term)->orWhere('filename', 'like', $term));
                }

                match ($in['kind']) {
                    'image' => $q->where('mime', 'like', 'image/%'),
                    'video' => $q->where('mime', 'like', 'video/%'),
                    'audio' => $q->where('mime', 'like', 'audio/%'),
                    'pdf' => $q->where('mime', 'application/pdf'),
                    'file' => $q->where('mime', 'not like', 'image/%')->where('mime', 'not like', 'video/%')->where('mime', 'not like', 'audio/%'),
                    default => null,
                };

                if (array_key_exists('folder', $in) && $in['folder'] !== null) {
                    $q->where('folder', $in['folder']);
                }

                $page = $q->paginate($in['perPage'], ['*'], 'page', $in['page']);

                return [
                    'items' => array_map($public, $page->items()),
                    'total' => $page->total(),
                    'page' => $page->currentPage(),
                    'pages' => $page->lastPage(),
                    'folders' => MediaItem::query()->select('folder')->distinct()->orderBy('folder')->pluck('folder')->filter()->values()->all(),
                ];
            },
        ]);

        $a->register('media/lookup', [
            'label' => 'Look Up Media',
            'description' => 'Details for media ids.',
            'permission' => 'admin.access',
            'annotations' => ['readOnly' => true],
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 200]], 'required' => ['ids']],
            'handler' => static function (array $in) use ($public) {
                $ids = array_map('strtolower', array_filter($in['ids'], 'is_string'));
                $items = MediaItem::query()->whereIn('id', $ids)->get()->keyBy('id');

                return ['items' => array_values(array_filter(array_map(static fn ($id) => isset($items[$id]) ? $public($items[$id]) : null, $ids)))];
            },
        ]);

        $a->register('media/import', [
            'label' => 'Import Media',
            'description' => 'Add a file from a public URL or base64 data. Returns the new media item (use its id in media fields and image blocks).',
            'permission' => 'media.upload',
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => [
                'url' => ['type' => 'string', 'format' => 'uri'],
                'filename' => ['type' => 'string', 'maxLength' => 200],
                'data' => ['type' => 'string', 'description' => 'Base64 file content (with filename).'],
                'alt' => ['type' => 'string', 'maxLength' => 1000],
                'folder' => ['type' => 'string', 'maxLength' => 200, 'default' => ''],
            ]],
            'handler' => function (array $in, AbilityContext $ctx) use ($store, $public) {
                $item = match (true) {
                    isset($in['url']) => $store()->importUrl($in['url'], $ctx->user, $in['folder']),
                    isset($in['data'], $in['filename']) => $store()->importBase64($in['filename'], $in['data'], $ctx->user, $in['folder']),
                    default => throw AbilityException::invalid(__('Send a url, or data with a filename.')),
                };

                if (isset($in['alt'])) {
                    $store()->update($item, ['alt' => $in['alt']]);
                }

                return $public($item->fresh());
            },
        ]);

        $a->register('media/update', [
            'label' => 'Update Media',
            'description' => 'Change name, alt text, caption, folder or focal point (0–100 %).',
            'permission' => 'media.edit',
            'package' => $package,
            'annotations' => ['idempotent' => true],
            'input' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'string'],
                'name' => ['type' => 'string', 'maxLength' => 200],
                'alt' => ['type' => 'string', 'maxLength' => 1000],
                'caption' => ['type' => 'string', 'maxLength' => 1000],
                'folder' => ['type' => 'string', 'maxLength' => 200],
                'focal' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100], 'y' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100]]],
            ], 'required' => ['id']],
            'handler' => fn (array $in) => $public($store()->update(MediaItem::query()->findOrFail(strtolower($in['id'])), $in)),
        ]);

        $a->register('media/report', [
            'label' => 'Report Media Problems',
            'description' => 'Ids and /media/ URLs that point at nothing, records without a file, files without a record, and items nothing uses.',
            'permission' => 'media.view',
            'annotations' => ['readOnly' => true],
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => (object) []],
            'handler' => fn () => $this->app->make(MediaReferences::class)->report(),
        ]);

        $a->register('media/delete', [
            'label' => 'Delete Media',
            'description' => 'Permanently delete a file and its image variants. Refused while something uses it, unless force is true.',
            'permission' => 'media.delete',
            'package' => $package,
            'annotations' => ['destructive' => true],
            'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'force' => ['type' => 'boolean', 'default' => false]], 'required' => ['id']],
            'handler' => function (array $in) use ($store) {
                $item = MediaItem::query()->findOrFail(strtolower($in['id']));
                $used = empty($in['force']) ? $this->app->make(MediaReferences::class)->of($item) : [];

                if ($used !== []) {
                    throw AbilityException::conflict(__('This file is still used by :where.', ['where' => implode(', ', array_unique(array_map(static fn (array $r) => $r['where']['title'], $used)))]));
                }

                $store()->delete($item);

                return ['deleted' => $in['id']];
            },
        ]);
    }
}
