<?php

declare(strict_types=1);

namespace Cms\Import;

use Cms\Abilities\AbilityException;
use Cms\Cms;
use Cms\Import\Sources\Csv;
use Cms\Support\Files;

/**
 * What someone chose for a source in the admin, kept until it is imported: where its entries
 * go, and for a CSV what each column is. Together with the source they are the input of
 * import/preview and import/run, so the screen does exactly what an agent would.
 */
final class SourceOptions
{
    public function __construct(private readonly Cms $cms, private readonly Importer $importer) {}

    private function file(): string
    {
        return storage_path('app/import-options.json');
    }

    /** @return array{id: string, path: string, type: string, size: int|null} the source, or a refusal */
    public function source(string $path): array
    {
        $real = $this->importer->resolve($path);

        foreach ($this->importer->sources() as $source) {
            if ($source['path'] === basename($real) && dirname($real) === realpath($this->importer->root())) {
                return $source;
            }
        }

        throw AbilityException::invalid(__('There is no “:path” in storage/app/import/.', ['path' => $path]));
    }

    /** @return list<string> the columns of a CSV source, none for the others */
    public function columns(array $source): array
    {
        return $source['type'] === 'csv' ? Csv::columns($this->importer->resolve($source['path'])) : [];
    }

    /**
     * The options of a source as its form shows them: what was chosen, over what would be taken anyway.
     *
     * @return array<string, mixed>
     */
    public function get(array $source): array
    {
        $stored = (array) ((is_file($this->file()) ? Files::readJson($this->file()) : [])[$source['path']] ?? []);
        $has = fn (string $handle) => $this->cms->collections()->find($handle) !== null;

        if ($source['type'] === 'wordpress') {
            return [
                'collection' => (string) ($stored['collection'] ?? ($has('posts') ? 'posts' : '')),
                'pages' => (string) ($stored['pages'] ?? ($has('pages') ? 'pages' : '')),
                'media' => (bool) ($stored['media'] ?? true),
                'redirects' => (bool) ($stored['redirects'] ?? true),
            ];
        }

        $options = ['collection' => (string) ($stored['collection'] ?? '')];

        foreach ($this->columns($source) as $i => $column) {
            $options[self::column($i)] = (string) ($stored['map'][$column] ?? $this->guess($options['collection'], $column));
        }

        return $options;
    }

    /** What a column is taken for until someone says: the entry's own (title, date…), the field its name leads to, or nothing. */
    private function guess(string $collection, string $column): string
    {
        $name = Csv::guess($column);

        return in_array($name, Csv::OWN_COLUMNS, true) ? $name : (string) ($collection === '' ? '' : $this->importer->fieldFor($collection, $name));
    }

    /** The handle of a column's field in the form: columns are named by people, handles are not. */
    public static function column(int $index): string
    {
        return 'column_'.$index;
    }

    /**
     * Keep what the form sent. Returns the options as get() reads them back.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $source, array $options): array
    {
        $keep = [];

        foreach (['collection', 'pages'] as $key) {
            if (isset($options[$key]) && is_string($options[$key])) {
                if ($options[$key] !== '' && $this->cms->collections()->find($options[$key]) === null) {
                    throw AbilityException::invalid(__('There is no collection “:handle”.', ['handle' => $options[$key]]), [$key => __('There is no collection “:handle”.', ['handle' => $options[$key]])]);
                }

                $keep[$key] = $options[$key];
            }
        }

        foreach (['media', 'redirects'] as $key) {
            if (isset($options[$key])) {
                $keep[$key] = (bool) $options[$key];
            }
        }

        foreach ($this->columns($source) as $i => $column) {
            if (isset($options[self::column($i)]) && is_string($options[self::column($i)])) {
                $keep['map'][$column] = mb_substr($options[self::column($i)], 0, 200);
            }
        }

        $this->write(fn (array $all) => [...$all, $source['path'] => array_replace((array) ($all[$source['path']] ?? []), $keep)]);

        return $this->get($source);
    }

    public function forget(string $path): void
    {
        $this->write(static fn (array $all) => array_diff_key($all, [$path => true]));
    }

    /**
     * The input import/preview and import/run take for this source with these options.
     *
     * @return array<string, mixed>
     */
    public function input(array $source): array
    {
        $options = $this->get($source);
        $in = ['type' => $source['type'], 'path' => $source['path']];

        if ($source['type'] === 'wordpress') {
            return [...$in, ...array_filter(['collection' => $options['collection'], 'pages' => $options['pages']]), 'media' => $options['media'], 'redirects' => $options['redirects']];
        }

        $in['collection'] = $options['collection'];

        foreach ($this->columns($source) as $i => $column) {
            $in['map'][$column] = $options[self::column($i)];
        }

        return $in;
    }

    /** Whether there is enough to preview: a CSV and a folder of Markdown need a collection named. */
    public function ready(array $source): bool
    {
        return $this->get($source)['collection'] !== '';
    }

    private function write(callable $change): void
    {
        Files::ensureDir(dirname($this->file()));
        $handle = fopen($this->file(), 'c+b');

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            $handle && fclose($handle);
            throw new \RuntimeException(__('The file could not be saved.'));
        }

        try {
            $all = (array) (json_decode((string) stream_get_contents($handle), true) ?: []);
            $json = json_encode((object) $change($all), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
