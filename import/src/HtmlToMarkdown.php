<?php

declare(strict_types=1);

namespace Cms\Import;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * What a WordPress post holds, as Markdown: headings, paragraphs, lists, quotes, code, links,
 * images, rules and simple tables. Every field type takes Markdown (FieldType::fromMarkdown), so
 * this is the one conversion an import needs. Scripts, styles, forms and embeds are dropped.
 */
final class HtmlToMarkdown
{
    private const array DROPPED = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'noscript', 'template', 'svg', 'head'];

    private const array BLOCKS = ['p', 'div', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'pre', 'figure', 'table', 'hr', 'header', 'footer', 'aside', 'main'];

    public static function convert(string $html): string
    {
        $html = self::prepare($html);

        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="cms-import-root">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('cms-import-root');

        return $root === null ? '' : trim((string) preg_replace("/\n{3,}/", "\n\n", self::blocks($root)));
    }

    /** WordPress stores paragraphs as blank lines, and wraps images in caption shortcodes. */
    private static function prepare(string $html): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        // [caption …]<img …> The caption[/caption] → a figure.
        $html = (string) preg_replace_callback('/\[caption[^\]]*\](.*?)\[\/caption\]/s', static function (array $m): string {
            if (preg_match('/^(\s*(?:<a[^>]*>)?\s*<img[^>]*>\s*(?:<\/a>)?)(.*)$/s', $m[1], $parts)) {
                return '<figure>'.$parts[1].'<figcaption>'.trim($parts[2]).'</figcaption></figure>';
            }

            return $m[1];
        }, $html);

        if (! preg_match('/<(p|div|h[1-6]|ul|ol|blockquote|pre|figure|table)\b/i', $html)) {
            // The classic editor: a blank line is a paragraph, a single one a line break.
            $html = implode('', array_map(static fn (string $p): string => '<p>'.str_replace("\n", '<br>', trim($p)).'</p>', array_filter(preg_split("/\n\s*\n/", $html) ?: [], static fn (string $p): bool => trim($p) !== '')));
        } elseif (! str_contains($html, '<!-- wp:')) {
            // Classic content with some block tags: loose text between them is paragraphs too.
            $html = implode("\n\n", array_map(static fn (string $p): string => preg_match('/^\s*<(p|div|h[1-6]|ul|ol|li|blockquote|pre|figure|table|hr|\/)/i', $p) ? $p : '<p>'.str_replace("\n", '<br>', trim($p)).'</p>', array_filter(preg_split("/\n\s*\n/", $html) ?: [], static fn (string $p): bool => trim($p) !== '')));
        }

        return $html;
    }

    private static function blocks(DOMNode $parent): string
    {
        $out = '';
        $inline = '';
        $flush = static function () use (&$out, &$inline): void {
            if (trim($inline) !== '') {
                $out .= trim($inline)."\n\n";
            }
            $inline = '';
        };

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && in_array($name = strtolower($node->nodeName), self::BLOCKS, true)) {
                $flush();
                $out .= self::block($node, $name);
            } else {
                $inline .= self::inline($node);
            }
        }

        $flush();

        return $out;
    }

    private static function block(DOMElement $node, string $name): string
    {
        switch ($name) {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $text = trim(self::inlineChildren($node));

                return $text === '' ? '' : str_repeat('#', (int) $name[1]).' '.str_replace("\n", ' ', $text)."\n\n";
            case 'p':
                // A paragraph that only wraps an image or blocks reads as them.
                $text = trim(self::inlineChildren($node));

                return $text === '' ? '' : $text."\n\n";
            case 'ul': case 'ol':
                return self::listing($node, $name === 'ol')."\n";
            case 'blockquote':
                $inner = trim(self::blocks($node));

                return $inner === '' ? '' : implode("\n", array_map(static fn (string $line): string => rtrim('> '.$line), explode("\n", $inner)))."\n\n";
            case 'pre':
                $code = rtrim($node->textContent, "\n");
                $fence = str_contains($code, '```') ? '````' : '```';
                $language = '';
                $inner = $node->getElementsByTagName('code')->item(0);

                if ($inner instanceof DOMElement && preg_match('/(?:language|lang)-([a-z0-9+#-]+)/i', $inner->getAttribute('class'), $m)) {
                    $language = strtolower($m[1]);
                }

                return $fence.$language."\n".$code."\n".$fence."\n\n";
            case 'hr':
                return "---\n\n";
            case 'figure':
                $caption = '';
                $body = '';

                foreach ($node->childNodes as $child) {
                    if ($child instanceof DOMElement && strtolower($child->nodeName) === 'figcaption') {
                        $caption = trim(self::inlineChildren($child));
                    } elseif ($child instanceof DOMElement && in_array(strtolower($child->nodeName), self::BLOCKS, true)) {
                        $body .= self::block($child, strtolower($child->nodeName));
                    } else {
                        $body .= self::inline($child);
                    }
                }

                $body = trim($body);

                return $body === '' ? '' : $body."\n\n".($caption === '' ? '' : '*'.$caption."*\n\n");
            case 'table':
                return self::table($node);
            default:
                return self::blocks($node);
        }
    }

    private static function listing(DOMElement $list, bool $ordered, int $depth = 0): string
    {
        $out = '';
        $n = max(1, (int) ($list->getAttribute('start') ?: 1));

        foreach ($list->childNodes as $item) {
            if (! $item instanceof DOMElement || strtolower($item->nodeName) !== 'li') {
                continue;
            }

            $text = '';
            $nested = '';

            foreach ($item->childNodes as $child) {
                $name = $child instanceof DOMElement ? strtolower($child->nodeName) : '';

                if ($name === 'ul' || $name === 'ol') {
                    $nested .= self::listing($child, $name === 'ol', $depth + 1);
                } elseif ($name === 'p' || $name === 'div') {
                    $text .= ' '.self::inlineChildren($child);
                } else {
                    $text .= self::inline($child);
                }
            }

            $marker = $ordered ? ($n++).'.' : '-';
            $out .= str_repeat('  ', $depth).$marker.' '.trim((string) preg_replace('/\s*\n\s*/', ' ', $text))."\n".$nested;
        }

        return $out;
    }

    private static function table(DOMElement $table): string
    {
        $rows = [];

        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];

            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = str_replace(['|', "\n"], ['\\|', ' '], trim(self::inlineChildren($cell)));
                }
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return '';
        }

        $width = max(array_map('count', $rows));
        $line = static fn (array $cells): string => '| '.implode(' | ', array_pad($cells, $width, '')).' |';

        return $line($rows[0])."\n".'|'.str_repeat(' --- |', $width)."\n".implode("\n", array_map($line, array_slice($rows, 1))).(count($rows) > 1 ? "\n" : '')."\n";
    }

    private static function inlineChildren(DOMNode $node): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            $out .= self::inline($child);
        }

        return $out;
    }

    private static function inline(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            // Whitespace as HTML reads it; what Markdown would take for markup is escaped.
            return (string) preg_replace('/([\\\\`*_\[\]])/', '\\\\$1', (string) preg_replace('/\s+/', ' ', $node->nodeValue ?? ''));
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $name = strtolower($node->nodeName);

        if (in_array($name, self::DROPPED, true)) {
            return '';
        }

        switch ($name) {
            case 'br':
                return "  \n";
            case 'strong': case 'b':
                return self::wrap('**', self::inlineChildren($node));
            case 'em': case 'i':
                return self::wrap('*', self::inlineChildren($node));
            case 'del': case 's': case 'strike':
                return self::wrap('~~', self::inlineChildren($node));
            case 'code': case 'kbd': case 'tt':
                $code = $node->textContent;

                return $code === '' ? '' : (str_contains($code, '`') ? '`` '.$code.' ``' : '`'.$code.'`');
            case 'a':
                $text = trim(self::inlineChildren($node));
                $href = self::url($node->getAttribute('href'));

                if ($href === '') {
                    return $text;
                }

                return $text === '' ? '' : '['.$text.']('.$href.')';
            case 'img':
                $src = self::url($node->getAttribute('src'));

                return $src === '' ? '' : '!['.str_replace(['[', ']', "\n"], ['', '', ' '], $node->getAttribute('alt')).']('.$src.')';
            default:
                // A block inside inline content (a div in a table cell): its text, in line.
                return self::inlineChildren($node);
        }
    }

    private static function wrap(string $mark, string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }

        // Spaces stay outside the marks, where Markdown wants them.
        preg_match('/^(\s*)(.*?)(\s*)$/s', $text, $m);

        return $m[1].$mark.$m[2].$mark.$m[3];
    }

    /** Only addresses a page may link to; `javascript:` and the like become nothing. */
    private static function url(string $url): string
    {
        $url = trim($url);

        if ($url === '' || ! preg_match('~^(https?://|mailto:|tel:|/|#|\./|\.\./)~i', $url) && preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
            return '';
        }

        return str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $url);
    }
}
