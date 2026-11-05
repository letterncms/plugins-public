<?php

declare(strict_types=1);

namespace Cms\Seo;

use Cms\Content\Entries\Entry;
use Cms\Cms;
use Cms\Modules\Site\Rendering\Renderer;
use Cms\Support\Html\Sanitizer;

/**
 * Clean Markdown version of a page (/page.md) for AI crawlers and agents:
 * text, headings, lists, tables, links and image alt text — no layout markup.
 */
final class MarkdownTwin
{
    public function __construct(private readonly Cms $cms, private readonly Meta $meta) {}

    public function render(Entry $entry): string
    {
        $m = $this->meta->forEntry($entry);
        $lines = ['# '.$entry->title, ''];

        if ($m['description'] !== '') {
            $lines[] = '> '.$m['description'];
            $lines[] = '';
        }

        $blueprint = $entry->collection()->blueprint();

        foreach ($blueprint->fields() as $field) {
            $value = $entry->data[$field->handle] ?? null;

            if ($field->section === 'seo' || $value === null || $value === '' || $value === []) {
                continue;
            }

            $chunk = match ($field->type) {
                'blocks' => $this->blocks($this->materialize($entry, (array) $value)),
                'markdown' => (string) $value,
                'richtext' => $this->inline((string) $value),
                'text', 'textarea' => in_array($field->handle, ['excerpt', 'summary', 'description'], true) ? '' : '**'.$field->label.':** '.$value,
                default => '',
            };

            if (trim($chunk) !== '') {
                $lines[] = trim($chunk);
                $lines[] = '';
            }
        }

        $lines[] = '---';
        $lines[] = 'Source: '.$m['canonical'].($entry->updatedAt ? ' · Updated '.$entry->updatedAt->toDateString() : '');

        return trim(implode("\n", $lines))."\n";
    }

    private function blocks(array $nodes): string
    {
        $out = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || ! empty($node['hidden'])) {
                continue;
            }

            $p = (array) ($node['props'] ?? []);
            $out[] = match ($node['type'] ?? '') {
                'paragraph' => $this->inline((string) ($p['text'] ?? '')),
                'heading' => str_repeat('#', max(2, min(6, (int) ($p['level'] ?? 2)))).' '.$this->inline((string) ($p['text'] ?? '')),
                'list' => implode("\n", array_map(fn ($item, $i) => (! empty($p['ordered']) ? ($i + 1).'.' : '-').' '.$this->inline((string) $item), (array) ($p['items'] ?? []), array_keys((array) ($p['items'] ?? [])))),
                'quote' => '> '.$this->inline((string) ($p['text'] ?? '')).(! empty($p['cite']) ? "\n> — ".$this->inline((string) $p['cite']) : ''),
                'callout' => '> **Note:** '.$this->inline((string) ($p['text'] ?? '')),
                'code' => "```".($p['language'] ?? '')."\n".($p['code'] ?? '')."\n```",
                'table' => $this->table($p),
                'image' => ! empty($p['alt']) ? '!['.$p['alt'].']('.($this->meta->absolute((string) app(\Cms\Modules\Media\Images::class)->url($p['image'] ?? null)) ?? '').')' : '',
                'button' => ! empty($p['url']) ? '['.$this->inline((string) ($p['label'] ?? '')).']('.$this->meta->absolute((string) $p['url']).')' : '',
                'link' => ! empty($p['url']) && trim(strip_tags((string) ($p['label'] ?? ''))) !== '' ? '['.$this->inline((string) $p['label']).']('.$this->meta->absolute((string) $p['url']).')' : '',
                'accordion' => implode("\n\n", array_map(fn ($i) => '**'.($i['question'] ?? '').'**'."\n\n".$this->inline((string) ($i['answer'] ?? '')), (array) ($p['items'] ?? []))),
                'divider' => '---',
                'tab-panel' => '### '.$this->inline((string) ($p['label'] ?? '')),
                'entries' => $this->entries($p),
                default => '',
            };

            if (! empty($node['children'])) {
                $out[] = $this->blocks((array) $node['children']);
            }
        }

        return implode("\n\n", array_filter($out, static fn ($s) => trim($s) !== ''));
    }

    /** Components, Repeats and bindings resolved, so the twin has the words visitors see. */
    private function materialize(Entry $entry, array $nodes): array
    {
        return app(Renderer::class)->materialize($entry, $nodes);
    }

    private function entries(array $p): string
    {
        if (empty($p['collection'])) {
            return '';
        }

        $items = $this->cms->entries()->query()->collection((string) $p['collection'])->live()->orderBy((string) ($p['sort'] ?? '-published_at'))->limit((int) ($p['limit'] ?? 6))->get();

        return implode("\n", array_map(fn (Entry $e) => '- ['.$e->title.']('.$this->meta->absolute($e->uri).')', $items));
    }

    private function table(array $p): string
    {
        $rows = array_values(array_filter((array) ($p['rows'] ?? []), 'is_array'));

        if ($rows === []) {
            return '';
        }

        $width = max(array_map('count', $rows));
        $line = fn (array $r) => '| '.implode(' | ', array_map(fn ($c) => str_replace('|', '\|', $this->inline((string) $c)), array_pad($r, $width, ''))).' |';
        $out = [$line($rows[0]), '|'.str_repeat(' --- |', $width)];

        foreach (array_slice($rows, 1) as $r) {
            $out[] = $line($r);
        }

        return implode("\n", $out);
    }

    private function inline(string $html): string
    {
        $md = preg_replace(['#<(strong|b)>(.*?)</\1>#s', '#<(em|i)>(.*?)</\1>#s', '#<code>(.*?)</code>#s', '#<br\s*/?>#'], ['**$2**', '_$2_', '`$1`', "\n"], $html) ?? $html;
        $md = preg_replace_callback('#<a href="([^"]*)"[^>]*>(.*?)</a>#s', fn ($m) => '['.strip_tags($m[2]).']('.($this->meta->absolute(html_entity_decode($m[1])) ?? $m[1]).')', $md) ?? $md;

        return trim(Sanitizer::text($md) === '' ? '' : html_entity_decode(strip_tags($md), ENT_QUOTES | ENT_HTML5));
    }
}
