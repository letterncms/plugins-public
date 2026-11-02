<?php

declare(strict_types=1);

namespace Cms\Seo;

use Cms\Abilities\AbilityException;
use Cms\Support\RecordTimestamps;
use Cms\Support\Yaml;

/**
 * Redirects in content/redirects.yaml (git-friendly). Served by PHP and, for
 * static builds, as tiny HTML files with a canonical + meta refresh.
 */
final class Redirects
{
    private ?array $cache = null;

    /** @return list<array{from: string, to: string, status: int}> */
    public function all(): array
    {
        return $this->cache ??= array_values(array_filter(array_map(static fn ($r) => is_array($r) && isset($r['from'], $r['to']) ? [
            'from' => '/'.trim((string) $r['from'], '/'),
            'to' => (string) $r['to'],
            ...RecordTimestamps::api($r),
            'status' => in_array((int) ($r['status'] ?? 301), [301, 302, 307, 308], true) ? (int) ($r['status'] ?? 301) : 301,
        ] : null, (array) (Yaml::read(content_path('redirects.yaml'))['redirects'] ?? []))));
    }

    public function match(string $path): ?array
    {
        $path = '/'.trim($path, '/');

        foreach ($this->all() as $redirect) {
            if ($redirect['from'] === $path) {
                return $redirect;
            }
        }

        return null;
    }

    public function save(array $redirects): array
    {
        $previous = array_column($this->all(), null, 'from');
        $clean = [];

        foreach ($redirects as $r) {
            $from = '/'.trim((string) ($r['from'] ?? ''), '/');
            $to = trim((string) ($r['to'] ?? ''));

            if ($from === '/' && $to === '') {
                continue;
            }

            if (! preg_match('~^/[^\s?#]*$~', $from) || ! preg_match('#^(/[^\s]*|https?://\S+)$#', $to)) {
                throw AbilityException::invalid(__('Invalid redirect :from → :to.', ['from' => $from, 'to' => $to]));
            }

            if ($from === $to) {
                continue;
            }

            $record = ['from' => $from, 'to' => $to, 'status' => (int) ($r['status'] ?? 301)];
            $old = $previous[$from] ?? null;
            $unchanged = $old !== null && $record === array_intersect_key($old, $record);
            $dates = $unchanged
                ? ['published_at' => $old['publishedAt'], 'updated_at' => $old['updatedAt']]
                : RecordTimestamps::saved($old);
            $clean[$from] = [...$record, ...$dates];
        }

        Yaml::write(content_path('redirects.yaml'), ['redirects' => array_values($clean)]);
        $this->cache = null;
        cms()->hooks()->dispatch('redirects.saved');

        return $this->all();
    }

    /** Called when a live entry moves: old URL → new URL; chains are collapsed. */
    public function moved(string $from, string $to): void
    {
        $list = array_filter($this->all(), static fn ($r) => $r['from'] !== $to);
        $list = array_map(static fn ($r) => $r['to'] === $from ? [...$r, 'to' => $to] : $r, $list);
        $list[] = ['from' => $from, 'to' => $to, 'status' => 301];
        $this->save($list);
    }

    public function html(array $redirect): string
    {
        $to = e($redirect['to']);

        return "<!doctype html><meta charset=\"utf-8\"><title>Moved</title><link rel=\"canonical\" href=\"{$to}\"><meta name=\"robots\" content=\"noindex\"><meta http-equiv=\"refresh\" content=\"0; url={$to}\"><p><a href=\"{$to}\">This page has moved.</a></p>\n";
    }
}
