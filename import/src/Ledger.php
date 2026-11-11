<?php

declare(strict_types=1);

namespace Cms\Import;

use Cms\Support\Files;
use RuntimeException;

/**
 * What earlier imports made, in storage/app/import-ledger.json: each source record's entry
 * (with a fingerprint of what was imported, so an unchanged record is left alone) and each
 * downloaded file's media item. This is what makes a second run update instead of duplicate,
 * without a field in anyone's blueprint.
 *
 * A run remembers in memory and writes once, at its end (flush): the file is read again and
 * merged under a lock, so two runs at once keep each other's entries.
 */
final class Ledger
{
    /** @var array{entries: array<string, array{id: string, hash: string, missing?: list<string>}>, media: array<string, string>}|null what the file held when it was read */
    private ?array $data = null;

    /** @var array{entries: array<string, array>, media: array<string, string>} what this run learned, not yet written */
    private array $pending = ['entries' => [], 'media' => []];

    private function file(): string
    {
        return storage_path('app/import-ledger.json');
    }

    private function data(): array
    {
        if ($this->data === null) {
            $this->data = self::shape(is_file($this->file()) ? Files::readJson($this->file()) : []);
        }

        return $this->data;
    }

    private static function shape(mixed $read): array
    {
        return ['entries' => (array) ($read['entries'] ?? []), 'media' => (array) ($read['media'] ?? [])];
    }

    /** @return array{id: string, hash: string, missing: list<string>}|null */
    public function entry(string $key): ?array
    {
        $found = $this->pending['entries'][$key] ?? $this->data()['entries'][$key] ?? null;

        return is_array($found) && isset($found['id'])
            ? ['id' => (string) $found['id'], 'hash' => (string) ($found['hash'] ?? ''), 'missing' => array_values(array_map('strval', (array) ($found['missing'] ?? [])))]
            : null;
    }

    /** @param  list<string>  $missing  the files the entry shows that could not be fetched: a later run looks again */
    public function rememberEntry(string $key, string $id, string $hash, array $missing = []): void
    {
        $this->pending['entries'][$key] = ['id' => $id, 'hash' => $hash, ...($missing === [] ? [] : ['missing' => array_values($missing)])];
    }

    public function media(string $url): ?string
    {
        $id = $this->pending['media'][$url] ?? $this->data()['media'][$url] ?? null;

        return is_string($id) ? $id : null;
    }

    public function rememberMedia(string $url, string $id): void
    {
        $this->pending['media'][$url] = $id;
    }

    /** @return array{entries: int, media: int} how much has been brought over so far */
    public function counts(): array
    {
        return ['entries' => count($this->data()['entries'] + $this->pending['entries']), 'media' => count($this->data()['media'] + $this->pending['media'])];
    }

    /** Write what this run learned, once: over what the file holds now, read and written under one lock. */
    public function flush(): void
    {
        if ($this->pending['entries'] === [] && $this->pending['media'] === []) {
            return;
        }

        Files::ensureDir(dirname($this->file()));
        $handle = fopen($this->file(), 'c+b');

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            $handle && fclose($handle);
            throw new RuntimeException(__('The import ledger could not be written.'));
        }

        try {
            $now = self::shape(json_decode((string) stream_get_contents($handle), true));
            $this->data = ['entries' => array_replace($now['entries'], $this->pending['entries']), 'media' => array_replace($now['media'], $this->pending['media'])];
            $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);
            $this->pending = ['entries' => [], 'media' => []];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
