<?php

declare(strict_types=1);

namespace Cms\Blocks;

/**
 * Wraps each word of rendered HTML in spans, so words can animate without a script
 * and before first paint. The text stays real text: no duplicates for screen readers,
 * tags stay where they are, a link stays one tab stop and spaces stay between words.
 *
 *   The <em>CMS</em>  →  <span class="cms-w"><span class="cms-w__i" style="--i:0">The</span></span> <em>…</em>
 */
final class TextSplit
{
    /** Past this many words, animating each one is not worth the markup. */
    public const int LIMIT = 200;

    private const array SKIP = ['script', 'style', 'svg', 'pre', 'code', 'textarea', 'select', 'noscript'];

    public static function html(string $html, bool $mask = false): string
    {
        if (str_contains($html, 'cms-w__i')) {
            return $html;
        }

        $tokens = preg_split('/(<!--.*?-->|<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        $skip = 0;
        $words = 0;

        foreach ($tokens as $token) {
            if ($token[0] === '<') {
                if (preg_match('#^<(/?)([a-z0-9-]+)#i', $token, $m) && in_array(strtolower($m[2]), self::SKIP, true)) {
                    $skip += $m[1] === '' ? 1 : -1;
                }

                $out .= $token;

                continue;
            }

            if ($skip > 0) {
                $out .= $token;

                continue;
            }

            foreach (preg_split('/(\s+)/u', $token, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (trim($part) === '') {
                    $out .= $part;

                    continue;
                }

                if (++$words > self::LIMIT) {
                    return $html;
                }

                $out .= '<span class="cms-w'.($mask ? ' cms-w--mask' : '').'"><span class="cms-w__i" style="--i:'.($words - 1).'">'.$part.'</span></span>';
            }
        }

        return $out;
    }
}
