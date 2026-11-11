<?php

declare(strict_types=1);

namespace Cms\Import;

use Illuminate\Support\Str;
use Cms\Abilities\AbilityException;
use Cms\Cms;
use Cms\Content\Collections\Collection;
use Cms\Content\Fields\Field;
use Cms\Import\Sources\Csv;
use Cms\Import\Sources\MarkdownFolder;
use Cms\Import\Sources\Wxr;
use Cms\Modules\Media\MediaItem;
use Cms\Modules\Media\MediaStore;
use Cms\Support\Files;
use Cms\Support\Markdown;
use Cms\Support\Schema\SchemaException;
use Cms\Users\User;
use Throwable;

/**
 * Reads a source into records, says what importing them would do (plan), and does it (run).
 * Both go the same way, so a run does what its preview said. Entries are written through the
 * entry service, files through the media library, redirects through SEO: an imported entry is
 * an entry like any other, with its revisions and hooks.
 *
 * Run again, a source updates what it made: the ledger remembers each record's entry, and a
 * record it has never seen takes over an entry with the same slug rather than making a second one.
 */
final class Importer
{
    public const array TYPES = ['wordpress', 'csv', 'markdown'];

    /** Where a field's value comes from when the source calls it something else. */
    private const array ALIASES = [
        'excerpt' => ['excerpt', 'summary', 'description', 'intro'],
        'description' => ['description', 'excerpt', 'summary'],
        'summary' => ['summary', 'excerpt', 'description'],
        'image' => ['image', 'cover', 'featured_image', 'hero', 'thumbnail'],
    ];

    public function __construct(private readonly Cms $cms, private readonly Ledger $ledger, private readonly Fetcher $fetcher) {}

    /** The folder sources are read from: nothing outside it is ever opened for an ability. */
    public function root(): string
    {
        return storage_path('app/import');
    }

    /** @return list<array{path: string, type: string, size: int|null}> What is in the folder, and what each looks like. */
    public function sources(): array
    {
        $out = [];

        foreach (glob($this->root().'/*') ?: [] as $path) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $type = is_dir($path) ? 'markdown' : (['xml' => 'wordpress', 'csv' => 'csv', 'tsv' => 'csv'][$extension] ?? null);

            if ($type !== null && ! is_link($path)) {
                $out[] = ['path' => basename($path), 'type' => $type, 'size' => is_dir($path) ? null : (int) filesize($path)];
            }
        }

        return $out;
    }

    /**
     * A source's real path. For an ability it is inside the import folder, links followed and
     * checked; the command line, where whoever types already reads every file, may name any path.
     */
    public function resolve(string $path, bool $anywhere = false): string
    {
        $path = trim($path);

        if ($path === '') {
            throw AbilityException::invalid(__('Name the file or folder to import.'));
        }

        if ($anywhere && is_string($real = realpath($path))) {
            return $real;
        }

        try {
            $joined = Files::within($this->root(), $path);
        } catch (Throwable) {
            throw AbilityException::invalid(__('Sources are read from storage/app/import/ only.'));
        }

        $real = realpath($joined);
        $root = realpath($this->root());

        if ($real === false || $root === false || $real === $root) {
            throw AbilityException::invalid(__('There is no “:path” in storage/app/import/.', ['path' => $path]));
        }

        if (! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            throw AbilityException::invalid(__('Sources are read from storage/app/import/ only.'));
        }

        return $real;
    }

    /** What a run would do, without doing any of it. */
    public function plan(array $in, bool $anywhere = false): array
    {
        return $this->process($in, null, $anywhere, dry: true);
    }

    /** @param  callable(array): void|null  $progress  Called with each item as it is done. */
    public function run(array $in, ?User $user = null, bool $anywhere = false, ?callable $progress = null): array
    {
        @set_time_limit(0);

        try {
            return $this->process($in, $user, $anywhere, dry: false, progress: $progress);
        } finally {
            // Once per run, also when it stopped halfway: what was made is remembered.
            $this->ledger->flush();
        }
    }

    private function process(array $in, ?User $user, bool $anywhere, bool $dry, ?callable $progress = null): array
    {
        $type = (string) ($in['type'] ?? '');
        $in = self::names($in);
        $path = $this->resolve((string) ($in['path'] ?? ''), $anywhere);
        $source = $this->read($type, $path, $in, $anywhere);
        $locale = (string) ($in['locale'] ?? $this->cms->defaultLocale());
        $warnings = $source['warnings'];

        // Files first: the text that shows them needs their new addresses.
        $wantMedia = (bool) ($in['media'] ?? true) && $source['media'] !== [];
        $library = $this->cms->isActive('letterncms/media') && class_exists(MediaStore::class);
        $media = [];
        $urls = [];
        $ids = [];
        // A preview counts on the files a run would fetch.
        $coming = [];

        if ($wantMedia && ! $library) {
            $warnings[] = __('Media is off, so files are left where they are.');
        }

        foreach ($wantMedia && $library ? $source['media'] : [] as $file) {
            $media[] = $done = $this->file($file, $user, $dry);

            if (isset($done['id'], $done['to'])) {
                $urls[$file['url']] = $done['to'];
                $ids[$file['url']] = $done['id'];
            } elseif ($dry) {
                $coming[$file['url']] = true;
            }
        }

        $files = array_fill_keys(array_column($wantMedia && $library ? $source['media'] : [], 'url'), true);

        $items = [];
        $redirects = [];
        $made = [];
        $left = [];

        foreach ($source['records'] as $record) {
            $collection = $this->cms->collections()->find($record->collection);
            $missing = array_values(array_filter(self::shows($record, $files), static fn (string $url) => ! isset($urls[$url]) && ! isset($coming[$url])));
            $prepared = $this->prepare($record, $collection, $locale, $in, $urls, $ids, $left, $missing);
            $item = ['key' => $record->key, 'collection' => $record->collection, 'title' => $record->title, 'action' => $prepared['action'], 'status' => $prepared['status'], 'id' => $prepared['id'], 'uri' => $prepared['uri'], 'warnings' => $prepared['warnings'],
                // Files an earlier run could not fetch, put in now.
                ...($prepared['repaired'] > 0 ? ['repaired' => $prepared['repaired']] : [])];

            if (! $dry && $prepared['action'] !== 'skip') {
                try {
                    $entry = $this->apply($record, $collection, $prepared, $locale, $made, $user);
                    $item['id'] = $entry->id;
                    $item['uri'] = $entry->uri;
                    $this->ledger->rememberEntry($record->key, $entry->id, $prepared['hash'], $missing);
                } catch (AbilityException $e) {
                    $item['action'] = 'failed';
                    $item['error'] = $e->getMessage().($e->details === [] ? '' : ' '.json_encode($e->details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }

            if ($item['id'] !== null) {
                $made[$record->key] = $item['id'];
            }

            foreach ($item['action'] === 'failed' ? [] : $record->redirectFrom as $from) {
                $redirects[] = ['from' => '/'.trim((string) parse_url($from, PHP_URL_PATH), '/'), 'to' => $item['uri']];
            }

            $items[] = $item;
            $progress !== null && $progress($item);
        }

        foreach ($left as $name => $count) {
            $warnings[] = __('Left out “:name” (:count×): the collection has no field for it.', ['name' => $name, 'count' => $count]);
        }

        [$redirects, $writes] = $this->redirects($redirects, (bool) ($in['redirects'] ?? true), $dry, $warnings);
        $count = static fn (array $rows, string $action) => count(array_filter($rows, static fn (array $r) => $r['action'] === $action));

        return [
            'dryRun' => $dry,
            'type' => $type,
            'summary' => [
                'create' => $count($items, 'create'), 'update' => $count($items, 'update'), 'skip' => $count($items, 'skip'), 'failed' => $count($items, 'failed'),
                'media' => $count($media, 'download'), 'redirects' => $count($redirects, 'add'),
                'warnings' => count($warnings) + array_sum(array_map(static fn (array $i) => count($i['warnings']), $items)),
            ],
            // Where redirects go: into SEO's list, or only into this report.
            'redirectsWritten' => $writes,
            'warnings' => array_values(array_unique($warnings)),
            'items' => $items,
            'media' => $media,
            'redirects' => $redirects,
        ];
    }

    /** @return array{records: list<Record>, media: array<string, array>, warnings: list<string>} */
    private function read(string $type, string $path, array $in, bool $cli = false): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw AbilityException::invalid(__('Import “wordpress”, “csv” or “markdown”.'));
        }

        if ($type === 'markdown' ? ! is_dir($path) : ! is_file($path)) {
            throw AbilityException::invalid($type === 'markdown' ? __('A Markdown import reads a folder.') : __('This import reads a file.'));
        }

        $known = fn (?string $handle, string $fallback) => $this->collection((string) ($handle ?? ($this->cms->collections()->find($fallback) ? $fallback : '')))->handle;

        if ($type === 'wordpress') {
            $types = ['post' => $known($in['collection'] ?? null, 'posts')];

            // Without a collection for them, pages are left out and the report says so.
            if (isset($in['pages']) || $this->cms->collections()->find('pages')) {
                $types['page'] = $known($in['pages'] ?? null, 'pages');
            }

            foreach ((array) ($in['types'] ?? []) as $postType => $handle) {
                $types[(string) $postType] = $this->collection((string) $handle)->handle;
            }

            // An ability runs inside a web request, with its memory and time: the command line reads the big ones.
            return Wxr::read($path, $types, $cli ? Wxr::MAX_BYTES : Wxr::MAX_BYTES_REQUEST);
        }

        $collection = $this->collection((string) ($in['collection'] ?? ''))->handle;

        return $type === 'csv'
            ? Csv::read($path, $collection, array_map('strval', (array) ($in['map'] ?? [])), isset($in['key']) ? (string) $in['key'] : null)
            : MarkdownFolder::read($path, $collection);
    }

    private function collection(string $handle): Collection
    {
        if ($handle === '') {
            throw AbilityException::invalid(__('Name the collection to import into.'));
        }

        return $this->cms->collections()->find($handle) ?? throw AbilityException::invalid(__('There is no collection “:handle”.', ['handle' => $handle]));
    }

    /**
     * A record as the entry it becomes: its fields, whether it is new, changed or as it was, and
     * whether it can be published as it is.
     *
     * @param  array<string, string>  $urls  a file's old address => its new one
     * @param  array<string, string>  $ids  a file's old address => its media id
     * @param  array<string, int>  $left  names no field took, counted
     * @param  list<string>  $missing  files the record shows that are not in the library (yet)
     */
    private function prepare(Record $record, Collection $collection, string $locale, array $in, array $urls, array $ids, array &$left, array $missing = []): array
    {
        $blueprint = $collection->blueprint();
        $map = array_map('strval', (array) ($in['fields'] ?? []));
        $warnings = $record->warnings;
        $data = [];

        foreach ($record->data as $name => $value) {
            $field = $this->field($collection, (string) $name, $map);

            if ($field === null) {
                $left[(string) $name] = ($left[(string) $name] ?? 0) + 1;

                continue;
            }

            $data[$field->handle] = self::coerce($value, $field);
        }

        if ($record->body !== null && trim($record->body) !== '') {
            $field = $this->bodyField($collection, $in['body'] ?? null);

            if ($field === null) {
                $left['body'] = ($left['body'] ?? 0) + 1;
            } else {
                $data[$field->handle] = $this->body(self::relink($record->body, $urls), $field);
            }
        }

        if ($record->image !== null) {
            $field = $this->field($collection, 'image', $map);

            if ($field !== null && $field->type === 'media' && isset($ids[$record->image])) {
                $data[$field->handle] = [$ids[$record->image]];
            }
        }

        // What the source says, not what became of it here: a file's new address changes nothing about the record.
        $hash = hash('xxh128', (string) json_encode([$record->title, $record->slug, $record->status, $record->at, $record->data, $record->body, $record->image, $record->parentKey, $in['body'] ?? null, $map]));
        $known = $this->ledger->entry($record->key);
        $existing = $known !== null ? $this->cms->entries()->find($known['id']) : null;
        $slug = Str::slug($record->slug ?? $record->title);

        if ($existing !== null && $existing->collection !== $collection->handle) {
            $existing = null;
        }

        $bySlug = $existing === null && $slug !== ''
            ? $this->cms->entries()->query()->working()->collection($collection->handle)->locale($locale)->where('slug', $slug)->first()
            : null;
        // As it was, unless a file that was missing last time is here now: then the entry is done again, with it.
        $wasMissing = $existing !== null ? $known['missing'] : [];
        $repaired = count(array_diff($wasMissing, $missing));
        $action = $existing !== null ? ($known['hash'] === $hash && $repaired === 0 ? 'skip' : 'update') : ($bySlug !== null ? 'update' : 'create');
        $existing ??= $bySlug;
        $status = $record->status;

        // Publishing needs every required field. What can't be published yet comes in as a draft, and says why.
        if ($status === 'published' && $action !== 'skip') {
            try {
                $blueprint->process(array_replace($existing !== null ? $existing->working()['data'] : $blueprint->defaults(), $data));
            } catch (SchemaException $e) {
                $status = 'draft';
                $warnings[] = __('Imported as a draft: :fields.', ['fields' => implode(', ', array_map(static fn ($field, $error) => $field.' '.lcfirst(is_array($error) ? implode(' ', $error) : (string) $error), array_keys($e->errors), $e->errors))]);
            }
        }

        return ['action' => $action, 'status' => $status, 'id' => $existing?->id, 'uri' => $existing?->uri, 'data' => $data, 'hash' => $hash, 'warnings' => $warnings, 'repaired' => $action === 'update' ? $repaired : 0];
    }

    /**
     * `map`, `fields` and `types` name things: every value a string, or the import is refused before it reads anything.
     */
    private static function names(array $in): array
    {
        foreach (['map', 'fields', 'types'] as $option) {
            if (! array_key_exists($option, $in) || $in[$option] === null) {
                continue;
            }

            $value = $in[$option] instanceof \stdClass ? (array) $in[$option] : $in[$option];

            if (! is_array($value) || ! array_all($value, static fn (mixed $name) => is_string($name))) {
                throw AbilityException::invalid(__('“:option” maps names to names: every value is text.', ['option' => $option]), [$option => __('Every value is text.')]);
            }

            $in[$option] = $value;
        }

        return $in;
    }

    /**
     * The files of the source a record shows: its image, and the pictures in its text.
     *
     * @param  array<string, true>  $files  the source's files, by address
     * @return list<string>
     */
    private static function shows(Record $record, array $files): array
    {
        $found = $record->image !== null && isset($files[$record->image]) ? [$record->image => true] : [];

        if ($files !== [] && $record->body !== null && preg_match_all('/!\[[^\]]*\]\((\S+?)\)/', $record->body, $m)) {
            foreach ($m[1] as $src) {
                $src = str_replace(['%20', '%28', '%29'], [' ', '(', ')'], $src);

                foreach ([$src, Wxr::original($src)] as $url) {
                    if (isset($files[$url])) {
                        $found[$url] = true;
                    }
                }
            }
        }

        return array_keys($found);
    }

    /** @param  array<string, string>  $made  record key => entry id, of what this run has done so far */
    private function apply(Record $record, Collection $collection, array $prepared, string $locale, array $made, ?User $user): \Cms\Content\Entries\Entry
    {
        $publish = $prepared['status'] === 'published';
        $content = $this->cms->entryService();

        if ($prepared['id'] !== null) {
            return $content->update($prepared['id'], ['title' => $record->title, 'data' => $prepared['data'], ...($record->slug !== null ? ['slug' => $record->slug] : []), ...($publish && $record->at !== null ? ['at' => $record->at] : [])], $user, $publish);
        }

        $parent = $record->parentKey !== null ? ($made[$record->parentKey] ?? $this->ledger->entry($record->parentKey)['id'] ?? null) : null;

        return $content->create($collection->handle, [
            'title' => $record->title,
            'data' => $prepared['data'],
            'locale' => $locale,
            'status' => $publish ? 'published' : 'draft',
            ...($record->slug !== null ? ['slug' => $record->slug] : []),
            ...($publish && $record->at !== null ? ['at' => $record->at] : []),
            ...($collection->isTree() && $parent !== null && $this->cms->entries()->find($parent)?->collection === $collection->handle ? ['parentId' => $parent] : []),
        ], $user);
    }

    /** The field a name goes to: as mapped, by its own name, or by what the collection calls the same thing. */
    private function field(Collection $collection, string $name, array $map): ?Field
    {
        if (array_key_exists($name, $map)) {
            return $map[$name] === '' ? null : $collection->blueprint()->field($map[$name]);
        }

        foreach (self::ALIASES[$name] ?? [$name] as $handle) {
            if (($field = $collection->blueprint()->field($handle)) !== null) {
                return $field;
            }
        }

        return null;
    }

    private function bodyField(Collection $collection, ?string $handle): ?Field
    {
        $blueprint = $collection->blueprint();

        if ($handle !== null) {
            return $blueprint->field($handle) ?? throw AbilityException::invalid(__('The collection “:collection” has no field “:field”.', ['collection' => $collection->handle, 'field' => $handle]));
        }

        if ($collection->body !== null && ($field = $blueprint->field($collection->body)) !== null) {
            return $field;
        }

        foreach (['body', 'content', 'text'] as $name) {
            if (($field = $blueprint->field($name)) !== null) {
                return $field;
            }
        }

        foreach ($blueprint->fields() as $field) {
            if (in_array($field->type, ['blocks', 'markdown', 'richtext'], true)) {
                return $field;
            }
        }

        return null;
    }

    /** Markdown as the field keeps it: blocks, Markdown, HTML or plain text. */
    private function body(string $markdown, Field $field): mixed
    {
        $type = $this->cms->fields()->resolve($field->type);

        return match (true) {
            $type->serializesToMarkdown() => $type->fromMarkdown($markdown, $field),
            $field->type === 'richtext' => Markdown::toHtml($markdown),
            default => $markdown,
        };
    }

    /** A source's value as the field's type takes it. */
    private static function coerce(mixed $value, Field $field): mixed
    {
        $list = static fn (mixed $v): array => is_array($v) ? array_values(array_map('strval', array_filter($v, 'is_scalar'))) : array_values(array_filter(array_map('trim', preg_split('/[|,]/', (string) $v) ?: []), static fn (string $s) => $s !== ''));

        return match ($field->type) {
            'tags' => $list($value),
            'select' => $field->get('multiple', false) ? $list($value) : (is_array($value) ? (string) ($list($value)[0] ?? '') : (string) $value),
            'toggle' => is_bool($value) ? $value : in_array(strtolower(trim((string) (is_scalar($value) ? $value : ''))), ['1', 'true', 'yes', 'y', 'on'], true),
            'number' => is_numeric($value) ? $value + 0 : $value,
            'date' => is_int($value) ? gmdate('Y-m-d', $value) : ($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value),
            'text', 'textarea', 'markdown', 'email', 'url', 'slug', 'color', 'code' => is_array($value) ? implode(', ', $list($value)) : (is_scalar($value) ? (string) $value : $value),
            default => $value,
        };
    }

    /** Images in the text at their new addresses: the file itself, or the size WordPress cut from it. */
    private static function relink(string $markdown, array $urls): string
    {
        if ($urls === []) {
            return $markdown;
        }

        return (string) preg_replace_callback('/(!\[[^\]]*\]\()(\S+?)(\))/', static function (array $m) use ($urls): string {
            $src = str_replace(['%20', '%28', '%29'], [' ', '(', ')'], $m[2]);
            $to = $urls[$src] ?? $urls[Wxr::original($src)] ?? null;

            return $to === null ? $m[0] : $m[1].$to.$m[3];
        }, $markdown);
    }

    /**
     * One file into the media library, once: the ledger knows what an earlier run fetched, and the
     * library itself answers with the file it has when the same bytes come again.
     *
     * @param  array{url: string, name: string, alt: string}  $file
     */
    private function file(array $file, ?User $user, bool $dry): array
    {
        $known = $this->ledger->media($file['url']);
        $item = $known !== null ? MediaItem::query()->find($known) : null;

        if ($item !== null) {
            return ['url' => $file['url'], 'action' => 'skip', 'id' => $item->id, 'to' => $item->url()];
        }

        if ($dry) {
            return ['url' => $file['url'], 'action' => 'download'];
        }

        $store = app(MediaStore::class);

        try {
            $bytes = $this->fetcher->fetch($file['url'], $store->maxBytes());
            Files::ensureDir(storage_path('app/uploads'));
            $tmp = storage_path('app/uploads/import-'.bin2hex(random_bytes(8)));
            file_put_contents($tmp, $bytes);
            $item = $store->store($tmp, basename((string) parse_url($file['url'], PHP_URL_PATH)) ?: 'file', $user, 'import');

            if ($file['alt'] !== '' && (string) $item->alt === '') {
                $item = $store->update($item, ['alt' => $file['alt']]);
            }
        } catch (Throwable $e) {
            return ['url' => $file['url'], 'action' => 'failed', 'error' => $e->getMessage()];
        }

        $this->ledger->rememberMedia($file['url'], $item->id);

        return ['url' => $file['url'], 'action' => 'download', 'id' => $item->id, 'to' => $item->url()];
    }

    /**
     * Old addresses to new ones. With SEO on they join its redirects, which serve them; without it
     * they are only reported. An address that already redirects, or that a page lives at, is left alone.
     *
     * @param  list<array{from: string, to: ?string}>  $wanted
     * @return array{0: list<array{from: string, to: ?string, action: string}>, 1: bool}
     */
    private function redirects(array $wanted, bool $write, bool $dry, array &$warnings): array
    {
        // By name, not by class: SEO is another plugin, and this one works without it.
        $class = 'Cms\\Seo\\Redirects';
        $seo = $write && $this->cms->isActive('letterncms/seo') && class_exists($class) ? app($class) : null;
        $existing = $seo !== null ? array_column($seo->all(), null, 'from') : [];
        $out = [];
        $add = [];

        foreach ($wanted as $redirect) {
            $from = $redirect['from'];
            $to = $redirect['to'];

            if ($from === '/' || isset($out[$from]) || ! preg_match('~^/[^\s?#]*$~', $from)) {
                continue;
            }

            // A new entry has its address once it is made: a preview can't name it yet.
            $action = match (true) {
                $from === $to, isset($existing[$from]), ! $dry && $to === null => 'skip',
                $to !== null && $this->cms->entries()->findByUri($from) !== null => 'skip',
                default => 'add',
            };
            $out[$from] = ['from' => $from, 'to' => $to, 'action' => $action];

            if ($action === 'add' && $to !== null) {
                $add[] = ['from' => $from, 'to' => $to, 'status' => 301];
            }
        }

        if (! $dry && $seo !== null && $add !== []) {
            try {
                $seo->save([...array_values($existing), ...$add]);
            } catch (Throwable $e) {
                $warnings[] = __('The redirects could not be saved: :error', ['error' => $e->getMessage()]);
            }
        }

        return [array_values($out), $seo !== null];
    }
}
