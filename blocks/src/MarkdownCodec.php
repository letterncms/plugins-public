<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Cms\Support\Html\Sanitizer;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Block Markdown: blocks ⇄ Markdown. Text blocks become plain Markdown; every
 * other block becomes a fenced directive with its props as JSON:
 *
 *   ::: section {"props":{"width":"wide"}}
 *   ## Heading
 *
 *   Some **text**.
 *   :::
 *
 * Nested directives use one more colon per level. Used for flat-file storage,
 * the .md twins of every page (GEO) and for agents that prefer writing Markdown.
 */
final class MarkdownCodec
{
    /** Node keys besides type, props and children that a directive carries. */
    private const array META = ['id', 'label', 'style', 'attrs', 'bind', 'logic', 'motion', 'anchor', 'class', 'hidden'];

    /** The ids reading gives blocks that have none in the file (see `stableIds`). */
    private const string READ_ID = '/^m[0-9a-f]{10}$/';

    private ?MarkdownParser $parser = null;

    private ?HtmlRenderer $renderer = null;

    /** The whole document being encoded, to tell whether anything points at a block's id. */
    private string $document = '';

    /**
     * Hand-written Block Markdown has no block ids, but the editor needs one per block. Derive
     * them from each block's place so they are the same on every read. Writing leaves out an
     * id like this when nothing points at it, so the Markdown stays as it was written.
     */
    public static function stableIds(array $nodes, string $path = 'r'): array
    {
        foreach ($nodes as $i => &$node) {
            if (! is_array($node)) {
                continue;
            }

            $here = $path.'.'.$i.':'.($node['type'] ?? '');

            if (! is_string($node['id'] ?? null) || $node['id'] === '') {
                $node['id'] = 'm'.substr(md5($here), 0, 10);
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = self::stableIds($node['children'], $here);
            }
        }

        return $nodes;
    }

    // Encoding

    public function encode(array $nodes): string
    {
        $this->document = (string) json_encode($nodes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->encodeNodes($nodes, 0);
    }

    private function encodeNodes(array $nodes, int $depth): string
    {
        $parts = [];
        $list = false;

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            // Two lists in a row read back as one, so the second keeps its fence.
            $chunk = $this->encodeNode($node, $depth, plain: ! ($list && ($node['type'] ?? '') === 'list'));
            $list = ($node['type'] ?? '') === 'list' && ! str_starts_with($chunk, ':');
            $parts[] = $chunk;
        }

        return implode("\n\n", $parts);
    }

    private function encodeNode(array $node, int $depth, bool $plain): string
    {
        $props = (array) ($node['props'] ?? []);

        if ($plain && $this->writesPlain($node) && ($markdown = $this->plain((string) ($node['type'] ?? ''), $props)) !== null && $this->readsBack($markdown, $node)) {
            return $markdown;
        }

        return $this->directive($node, $depth);
    }

    /** Nothing but its props, and an id that reading gives it anyway, if anything. */
    private function writesPlain(array $node): bool
    {
        foreach (self::META as $key) {
            if ($key !== 'id' && ! empty($node[$key])) {
                return false;
            }
        }

        return ! $this->keepsId($node);
    }

    /** The plain form reads back as this very block: same type, same props, nothing lost. */
    private function readsBack(string $markdown, array $node): bool
    {
        $read = $this->markdownToNodes($markdown);

        return count($read) === 1 && $read[0]['type'] === ($node['type'] ?? null) && self::same((array) ($node['props'] ?? []), $read[0]['props']);
    }

    /** Whether everything set in `$props` is in `$read` with the same value; empty values may go. */
    private static function same(array $props, array $read): bool
    {
        foreach ($props as $key => $value) {
            if ($value === '' || $value === null || $value === false || $value === []) {
                continue;
            }

            $other = $read[$key] ?? null;
            $equal = is_array($value)
                ? is_array($other) && count($value) === count($other) && array_is_list($value) === array_is_list($other) && self::same($value, $other) && self::same($other, $value)
                : is_scalar($other) && (string) $value === (string) $other;

            if (! $equal) {
                return false;
            }
        }

        return true;
    }

    /** Markdown for a text block, or null when it has no plain form. Whether it fits is up to `readsBack`. */
    private function plain(string $type, array $props): ?string
    {
        switch ($type) {
            case 'paragraph':
                return $this->escapeLeading($this->inline((string) ($props['text'] ?? '')));
            case 'heading':
                return str_repeat('#', max(1, min(6, (int) ($props['level'] ?? 2)))).' '.$this->inline((string) ($props['text'] ?? ''));
            case 'list':
                $i = 0;

                return implode("\n", array_map(function ($item) use ($props, &$i) {
                    return (! empty($props['ordered']) ? (++$i).'.' : '-').' '.$this->inline((string) $item);
                }, (array) ($props['items'] ?? [])));
            case 'quote':
                return '> '.str_replace("\n", "\n> ", $this->inline((string) ($props['text'] ?? '')));
            case 'code':
                $fence = str_contains((string) ($props['code'] ?? ''), '```') ? '~~~~' : '```';
                $lang = ($props['language'] ?? 'plain') === 'plain' ? '' : (string) $props['language'];

                return $fence.$lang."\n".rtrim((string) ($props['code'] ?? ''))."\n".$fence;
            case 'divider':
                return '---';
            case 'image':
                if (! is_string($props['image'] ?? null)) {
                    return null;
                }

                $caption = isset($props['caption']) && $props['caption'] !== '' ? ' "'.str_replace('"', '\"', strip_tags((string) $props['caption'])).'"' : '';

                return '!['.str_replace(']', '\]', (string) ($props['alt'] ?? '')).']('.$props['image'].$caption.')';
            case 'table':
                return $this->table($props);
        }

        return null;
    }

    private function directive(array $node, int $depth): string
    {
        $fence = str_repeat(':', 3 + $depth);
        $meta = ['id' => $this->keepsId($node) ? $node['id'] : null, 'props' => ($node['props'] ?? []) ?: null];

        foreach (self::META as $key) {
            $meta[$key] ??= $key === 'id' ? null : ($node[$key] ?? null);
        }

        $meta['style'] = self::style($meta['style']);
        $meta = array_filter($meta, static fn ($v) => $v !== null && $v !== []);

        $open = $fence.' '.($node['type'] ?? '').($meta ? ' '.json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
        $body = isset($node['children']) && $node['children'] !== [] ? "\n".$this->encodeNodes((array) $node['children'], $depth + 1) : '';

        return $open.$body."\n".$fence;
    }

    /** An id that reading would give the block anyway, and nothing points at, stays out of the file. */
    private function keepsId(array $node): bool
    {
        $id = $node['id'] ?? null;

        return is_string($id) && $id !== '' && (preg_match(self::READ_ID, $id) !== 1 || substr_count($this->document, $id) > 1);
    }

    /** A style without its empty breakpoints, which JSON can't tell from lists. */
    private static function style(mixed $style): ?array
    {
        return is_array($style) ? array_filter($style, static fn ($declarations) => $declarations !== [] && $declarations !== null) : null;
    }

    private function table(array $props): ?string
    {
        $rows = array_values(array_filter((array) ($props['rows'] ?? []), 'is_array'));

        if ($rows === [] || empty($props['header']) || ! empty($props['caption'])) {
            return null;
        }

        $width = max(array_map('count', $rows));
        $line = fn (array $cells) => '| '.implode(' | ', array_map(fn ($c) => str_replace(['|', "\n"], ['\|', ' '], $this->inline((string) $c)), array_pad($cells, $width, ''))).' |';
        $out = [$line($rows[0]), '|'.str_repeat(' --- |', $width)];

        foreach (array_slice($rows, 1) as $row) {
            $out[] = $line($row);
        }

        return implode("\n", $out);
    }

    /** Inline HTML → Markdown for common marks; anything else stays as inline HTML (valid Markdown). */
    private function inline(string $html): string
    {
        $md = preg_replace(['#<(strong|b)>(.*?)</\1>#s', '#<(em|i)>(.*?)</\1>#s', '#<code>(.*?)</code>#s', '#<(s|del)>(.*?)</\1>#s', '#<br\s*/?>\n?#'], ['**$2**', '_$2_', '`$1`', '~~$2~~', "  \n"], $html) ?? $html;
        $md = preg_replace_callback('#<a href="([^"]*)"[^>]*>(.*?)</a>#s', static fn ($m) => '['.$m[2].']('.html_entity_decode($m[1]).')', $md) ?? $md;

        return html_entity_decode($md, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function escapeLeading(string $text): string
    {
        return preg_match('/^(#{1,6}\s|[-*+]\s|\d+\.\s|>|:::|```|---\s*$)/', $text) ? '\\'.$text : $text;
    }

    // Decoding

    /** @return list<array> */
    public function decode(string $markdown): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
        $nodes = [];
        $buffer = [];
        $code = null;

        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];

            if ($code !== null) {
                $buffer[] = $line;

                if (preg_match('/^'.preg_quote($code, '/').'\s*$/', $line)) {
                    $code = null;
                }

                continue;
            }

            if (preg_match('/^(```+|~~~+)/', $line, $m)) {
                $code = $m[1];
                $buffer[] = $line;
                continue;
            }

            if (preg_match('/^(:{3,})\s*([a-z0-9][a-z0-9\/-]*)\s*(\{.*\})?\s*$/', $line, $m)) {
                array_push($nodes, ...$this->markdownToNodes(implode("\n", $buffer)));
                $buffer = [];

                // Find the matching close fence (same colon count).
                $inner = [];
                $j = $i + 1;

                for (; $j < $n && ! preg_match('/^'.$m[1].'\s*$/', $lines[$j]); $j++) {
                    $inner[] = $lines[$j];
                }

                $meta = isset($m[3]) ? (json_decode($m[3], true) ?: []) : [];
                $node = ['type' => $m[2], 'props' => (array) ($meta['props'] ?? [])];

                $meta['style'] = self::style($meta['style'] ?? null);

                foreach (self::META as $key) {
                    if (isset($meta[$key]) && $meta[$key] !== []) {
                        $node[$key] = $meta[$key];
                    }
                }

                $children = $this->decode(implode("\n", $inner));

                if ($children !== []) {
                    $node['children'] = $children;
                }

                $nodes[] = $node;
                $i = $j;
                continue;
            }

            $buffer[] = $line;
        }

        array_push($nodes, ...$this->markdownToNodes(implode("\n", $buffer)));

        return $nodes;
    }

    /** @return list<array> */
    private function markdownToNodes(string $markdown): array
    {
        if (trim($markdown) === '') {
            return [];
        }

        $document = $this->parser()->parse($markdown);
        $nodes = [];

        foreach ($document->children() as $block) {
            $node = $this->blockToNode($block);

            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private function blockToNode(Node $block): ?array
    {
        return match (true) {
            $block instanceof Heading => ['type' => 'heading', 'props' => ['text' => $this->inlineHtml($block), 'level' => (string) $block->getLevel()]],
            $block instanceof Paragraph => $this->paragraph($block),
            $block instanceof ListBlock => ['type' => 'list', 'props' => [
                'ordered' => $block->getListData()->type === ListBlock::TYPE_ORDERED,
                'items' => array_map(fn (ListItem $item) => $this->listItem($item), array_values(array_filter(iterator_to_array($block->children(), false), static fn ($c) => $c instanceof ListItem))),
            ]],
            $block instanceof BlockQuote => ['type' => 'quote', 'props' => ['text' => implode('<br>', array_map(fn ($p) => $this->inlineHtml($p), iterator_to_array($block->children(), false)))]],
            $block instanceof FencedCode => ['type' => 'code', 'props' => ['code' => rtrim($block->getLiteral(), "\n"), 'language' => ($block->getInfoWords()[0] ?? '') ?: 'plain']],
            $block instanceof IndentedCode => ['type' => 'code', 'props' => ['code' => rtrim($block->getLiteral(), "\n"), 'language' => 'plain']],
            $block instanceof ThematicBreak => ['type' => 'divider', 'props' => []],
            $block instanceof Table => $this->tableNode($block),
            $block instanceof HtmlBlock => ['type' => 'html', 'props' => ['html' => $block->getLiteral()]],
            default => null,
        };
    }

    private function paragraph(Paragraph $p): array
    {
        $children = iterator_to_array($p->children(), false);

        if (count($children) === 1 && $children[0] instanceof Image) {
            $image = $children[0];
            $alt = '';

            foreach ($image->children() as $c) {
                $alt .= method_exists($c, 'getLiteral') ? $c->getLiteral() : '';
            }

            return ['type' => 'image', 'props' => array_filter(['image' => $image->getUrl(), 'alt' => $alt, 'caption' => $image->getTitle()], static fn ($v) => $v !== null && $v !== '')];
        }

        return ['type' => 'paragraph', 'props' => ['text' => $this->inlineHtml($p)]];
    }

    private function listItem(ListItem $item): string
    {
        $parts = [];

        foreach ($item->children() as $child) {
            $parts[] = $child instanceof Paragraph ? $this->inlineHtml($child) : strip_tags($this->renderer()->renderNodes([$child]));
        }

        return implode(' ', $parts);
    }

    private function tableNode(Table $table): array
    {
        $rows = [];
        $header = false;

        foreach ($table->children() as $section) {
            if (! $section instanceof TableSection) {
                continue;
            }

            $header = $header || $section->isHead();

            foreach ($section->children() as $row) {
                if ($row instanceof TableRow) {
                    $rows[] = array_map(fn (TableCell $cell) => $this->inlineHtml($cell), array_values(array_filter(iterator_to_array($row->children(), false), static fn ($c) => $c instanceof TableCell)));
                }
            }
        }

        return ['type' => 'table', 'props' => ['rows' => $rows, 'header' => $header]];
    }

    /** Text as a save stores it, so a block reads back the same as it was written. */
    private function inlineHtml(Node $node): string
    {
        return Sanitizer::inline(trim((string) $this->renderer()->renderNodes(iterator_to_array($node->children(), false))));
    }

    private function parser(): MarkdownParser
    {
        if (! $this->parser) {
            $this->parser = new MarkdownParser($this->environment());
        }

        return $this->parser;
    }

    private function renderer(): HtmlRenderer
    {
        return $this->renderer ??= new HtmlRenderer($this->environment());
    }

    private ?Environment $environment = null;

    private function environment(): Environment
    {
        if (! $this->environment) {
            $this->environment = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => false]);
            $this->environment->addExtension(new CommonMarkCoreExtension);
            $this->environment->addExtension(new TableExtension);
            $this->environment->addExtension(new StrikethroughExtension);
        }

        return $this->environment;
    }
}
