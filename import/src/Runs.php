<?php

declare(strict_types=1);

namespace Cms\Import;

use Illuminate\Support\Str;
use Cms\Abilities\AbilityException;
use Cms\Support\Files;
use Cms\Users\User;
use Throwable;

/**
 * Imports started in the admin, each a file in storage/app/import-runs/. A run is done a turn at
 * a time: every turn imports for a few seconds and writes down how far it is, so it can be
 * watched, survives a reload, and carries on from any later turn. Nothing is kept of where it
 * stopped: the importer skips what is already in, which is what makes a run safe to repeat.
 */
final class Runs
{
    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    public const string CANCELED = 'canceled';

    /** How many finished runs are kept; older ones go when a new one starts. */
    private const int KEPT = 50;

    public function __construct(private readonly Importer $importer, private readonly SourceOptions $options) {}

    private function folder(): string
    {
        return storage_path('app/import-runs');
    }

    private function file(string $id): string
    {
        if (! preg_match('/^[0-9a-z]{26}$/', $id)) {
            throw new AbilityException(__('Import not found.'), 'not_found');
        }

        return $this->folder().'/'.$id.'.json';
    }

    /** Start importing a source with the options chosen for it. Refuses what the import itself would refuse. */
    public function start(string $path, ?User $user): array
    {
        $source = $this->options->source($path);
        $input = $this->options->input($source);
        // Read once before anything is promised: a source that cannot be read never becomes a run.
        $plan = $this->importer->plan($input);

        // One run per file: two starts at once agree on the one, which is the run already going.
        return $this->waiting($this->folder().'/start.lock', function () use ($source, $input, $plan, $user): array {
            foreach ($this->all() as $run) {
                if ($run['status'] === self::RUNNING && $run['path'] === $source['path']) {
                    return $run;
                }
            }

            return $this->begin($source, $input, $plan, $user);
        });
    }

    /** @param  array{records: int, redirectsWritten: bool}  $plan */
    private function begin(array $source, array $input, array $plan, ?User $user): array
    {
        $run = [
            'id' => strtolower((string) Str::ulid()), 'path' => $source['path'], 'type' => $source['type'], 'input' => $input, 'status' => self::RUNNING,
            'userId' => $user?->id !== null ? (string) $user->id : null, 'userName' => $user?->name !== null ? (string) $user->name : null,
            'startedAt' => now()->toIso8601String(), 'updatedAt' => now()->toIso8601String(), 'finishedAt' => null,
            'records' => $plan['records'], 'error' => null, 'redirectsWritten' => $plan['redirectsWritten'], 'warnings' => [], 'items' => [], 'media' => [], 'redirects' => [],
        ];
        $this->write($run);
        $this->prune();

        return $run;
    }

    public function find(string $id): array
    {
        $file = $this->file($id);

        return is_file($file) ? self::shape(Files::readJson($file)) : throw new AbilityException(__('Import not found.'), 'not_found');
    }

    /** @return list<array> newest first */
    public function all(): array
    {
        $runs = array_map(static fn (string $file) => self::shape(Files::readJson($file)), glob($this->folder().'/*.json') ?: []);
        usort($runs, static fn (array $a, array $b) => strcmp($b['id'], $a['id']));

        return $runs;
    }

    /** @return list<array> the ones that are not finished */
    public function running(): array
    {
        return array_values(array_filter($this->all(), static fn (array $run) => $run['status'] === self::RUNNING));
    }

    /**
     * Import for up to $seconds and write down how far it got. One turn at a time: while another
     * request has it, this answers with how it stands.
     */
    public function step(string $id, float $seconds = 4.0): array
    {
        $this->find($id);

        return Files::locked($this->folder().'/'.$id.'.lock', function () use ($id, $seconds): array {
            $run = $this->find($id);

            if ($run['status'] !== self::RUNNING) {
                return $run;
            }

            try {
                $user = $run['userId'] !== null ? User::query()->find($run['userId']) : null;
                // What failed in an earlier turn is not tried again: a failure is no progress, so it cannot hold the run in place.
                $failed = [...array_keys(array_filter($run['media'], static fn (array $f) => $f['action'] === 'failed')), ...array_keys(array_filter($run['items'], static fn (array $i) => $i['action'] === 'failed'))];
                $report = $this->importer->run($run['input'], $user, until: microtime(true) + $seconds, failed: $failed);
            } catch (AbilityException $e) {
                return $this->write([...$run, 'status' => self::FAILED, 'error' => $e->getMessage(), 'finishedAt' => now()->toIso8601String()]);
            } catch (Throwable $e) {
                report($e);

                return $this->write([...$run, 'status' => self::FAILED, 'error' => __('The import stopped on an error.'), 'finishedAt' => now()->toIso8601String()]);
            }

            // What a later turn skips as already in keeps what the turn that made it said.
            foreach ($report['items'] as $item) {
                if (! isset($run['items'][$item['key']]) || $run['items'][$item['key']]['action'] === 'skip' || $run['items'][$item['key']]['action'] === 'failed') {
                    $run['items'][$item['key']] = $item;
                }
            }

            foreach ($report['media'] as $file) {
                if (! isset($run['media'][$file['url']]) || $file['action'] !== 'skip') {
                    $run['media'][$file['url']] = $file;
                }
            }

            foreach ($report['redirects'] as $redirect) {
                if (! isset($run['redirects'][$redirect['from']]) || $redirect['action'] === 'add') {
                    $run['redirects'][$redirect['from']] = $redirect;
                }
            }

            $run['warnings'] = array_values(array_unique([...$run['warnings'], ...$report['warnings']]));
            $run['records'] = max($run['records'], $report['records']);
            $run['redirectsWritten'] = $report['redirectsWritten'];

            // A cancel waits for this turn to be through (cancel() takes the same lock), so the run is still its own here.
            if ($report['complete']) {
                $run['status'] = self::DONE;
                $run['finishedAt'] = now()->toIso8601String();
            }

            return $this->write($run);
        }, fn (): array => $this->find($id));
    }

    /** Stop a run: once the turn under way (if any) is through, so what it did is written down first. */
    public function cancel(string $id): array
    {
        $this->find($id);

        return $this->waiting($this->folder().'/'.$id.'.lock', function () use ($id): array {
            $run = $this->find($id);

            return $run['status'] === self::RUNNING ? $this->write([...$run, 'status' => self::CANCELED, 'finishedAt' => now()->toIso8601String()]) : $run;
        });
    }

    /** Whether a file is being imported now. */
    public function importing(string $path): bool
    {
        return array_filter($this->running(), static fn (array $run) => $run['path'] === $path) !== [];
    }

    /** The work, holding the lock: waited for while a turn has it (a turn is a few seconds). */
    private function waiting(string $lock, callable $work): mixed
    {
        $miss = new \stdClass;

        for ($until = microtime(true) + 30; ; usleep(100_000)) {
            $done = Files::locked($lock, $work, static fn () => $miss);

            if ($done !== $miss) {
                return $done;
            }

            if (microtime(true) >= $until) {
                throw AbilityException::conflict(__('The import is busy. Try again in a moment.'));
            }
        }
    }

    public function delete(string $id): void
    {
        $run = $this->find($id);

        if ($run['status'] === self::RUNNING) {
            throw AbilityException::conflict(__('This import is still running. Cancel it first.'));
        }

        @unlink($this->file($id));
        @unlink($this->folder().'/'.$id.'.lock');
    }

    /** A run as abilities answer with it: how it stands, in numbers, without its lists. */
    public static function summary(array $run): array
    {
        $count = static fn (array $rows, string $action) => count(array_filter($rows, static fn (array $r) => $r['action'] === $action));

        return [
            'id' => $run['id'], 'path' => $run['path'], 'type' => $run['type'], 'status' => $run['status'], 'error' => $run['error'],
            'by' => $run['userName'], 'startedAt' => $run['startedAt'], 'finishedAt' => $run['finishedAt'],
            'records' => $run['records'], 'done' => count($run['items']),
            'summary' => [
                'create' => $count($run['items'], 'create'), 'update' => $count($run['items'], 'update'), 'skip' => $count($run['items'], 'skip'), 'failed' => $count($run['items'], 'failed'),
                'media' => $count($run['media'], 'download'), 'redirects' => $count($run['redirects'], 'add'),
                'warnings' => count($run['warnings']) + array_sum(array_map(static fn (array $i) => count($i['warnings']), $run['items'])),
            ],
            'redirectsWritten' => $run['redirectsWritten'], 'warnings' => $run['warnings'],
        ];
    }

    private static function shape(array $run): array
    {
        return [
            'id' => (string) ($run['id'] ?? ''), 'path' => (string) ($run['path'] ?? ''), 'type' => (string) ($run['type'] ?? ''), 'input' => (array) ($run['input'] ?? []),
            'status' => (string) ($run['status'] ?? self::FAILED), 'userId' => $run['userId'] ?? null, 'userName' => $run['userName'] ?? null,
            'startedAt' => $run['startedAt'] ?? null, 'updatedAt' => $run['updatedAt'] ?? null, 'finishedAt' => $run['finishedAt'] ?? null,
            'records' => (int) ($run['records'] ?? 0), 'error' => $run['error'] ?? null, 'redirectsWritten' => (bool) ($run['redirectsWritten'] ?? false),
            'warnings' => array_values((array) ($run['warnings'] ?? [])), 'items' => (array) ($run['items'] ?? []), 'media' => (array) ($run['media'] ?? []), 'redirects' => (array) ($run['redirects'] ?? []),
        ];
    }

    private function write(array $run): array
    {
        $run['updatedAt'] = now()->toIso8601String();
        Files::putJson($this->file($run['id']), [...$run, 'items' => (object) $run['items'], 'media' => (object) $run['media'], 'redirects' => (object) $run['redirects']]);

        return $run;
    }

    private function prune(): void
    {
        foreach (array_slice(array_values(array_filter($this->all(), static fn (array $run) => $run['status'] !== self::RUNNING)), self::KEPT) as $old) {
            @unlink($this->file($old['id']));
            @unlink($this->folder().'/'.$old['id'].'.lock');
        }
    }
}
