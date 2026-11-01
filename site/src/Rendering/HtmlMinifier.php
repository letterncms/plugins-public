<?php

declare(strict_types=1);

namespace Cms\Site\Rendering;

/** Conservative HTML minifier: collapses whitespace outside pre/textarea/script/style, drops comments. */
final class HtmlMinifier
{
    public function minify(string $html): string
    {
        $preserved = [];

        $html = preg_replace_callback('#<(pre|textarea|script|style)\b[^>]*>.*?</\1>#is', static function (array $m) use (&$preserved): string {
            $key = "\x1a".count($preserved)."\x1a";
            $preserved[$key] = $m[0];

            return $key;
        }, $html) ?? $html;

        $html = preg_replace('/<!--(?!\[if).*?-->/s', '', $html) ?? $html;
        $html = preg_replace('/\s{2,}/', ' ', $html) ?? $html;
        $html = preg_replace('/>\s+</', '> <', $html) ?? $html;
        $html = preg_replace('#\s*(</?(?:html|head|body|meta|link|title|div|section|header|footer|main|nav|ul|ol|li|p|h[1-6]|article|aside|figure|picture|source|br|hr|table|thead|tbody|tr|td|th)\b[^>]*>)\s*#i', '$1', $html) ?? $html;

        return trim(strtr($html, $preserved));
    }
}
