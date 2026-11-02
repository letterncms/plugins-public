<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Cms\Support\Html\Sanitizer;

/**
 * HTML for the core blocks. Each method gets the render data from
 * BlockRenderer (block, node, content, attrs, editor, scope, renderer).
 */
final class Elements
{
    public const array BOX_TAGS = ['div', 'section', 'header', 'footer', 'main', 'nav', 'aside', 'article', 'ul', 'ol', 'li', 'figure', 'figcaption', 'span', 'label', 'button', 'form', 'details', 'summary', 'dialog', 'address', 'blockquote', 'dl', 'dt', 'dd', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p'];

    public const array TEXT_TAGS = ['p', 'span', 'div', 'small', 'strong', 'em', 'label', 'li', 'figcaption', 'blockquote', 'cite', 'time', 'address', 'dt', 'dd', 'legend', 'kbd', 'code'];

    public static function box(array $d): string
    {
        $b = $d['block'];
        $tag = in_array($b['tag'] ?? 'div', self::BOX_TAGS, true) ? ($b['tag'] ?? 'div') : 'div';
        $link = self::url($b['link'] ?? null);

        if ($link !== null && ! in_array($tag, ['ul', 'ol', 'li', 'button', 'form', 'details', 'summary', 'dialog'], true)) {
            return '<a '.$d['attrs'].self::href($link, ! empty($b['new_tab']), $d['scope']).'>'.$d['content'].'</a>';
        }

        $extra = match ($tag) {
            'button' => ' type="'.(in_array($b['type'] ?? 'button', ['button', 'submit', 'reset'], true) ? ($b['type'] ?? 'button') : 'button').'"',
            default => '',
        };

        return "<{$tag} {$d['attrs']}{$extra}>{$d['content']}</{$tag}>";
    }

    /**
     * Editor only: the attribute that shows a muted placeholder in an element whose
     * text prop is empty (`:empty::before`, so editing in place starts from nothing).
     */
    public static function placeholder(array $d, string $prop): string
    {
        $text = $d['placeholders'][$prop] ?? null;

        return $text === null ? '' : ' data-cms-placeholder="'.e($text).'"';
    }

    /** Source props and exact entry metadata bindings have explicit editing targets. */
    public static function editable(array $d, string $prop, ?int $index = null): string
    {
        if (! $d['editor'] || ! empty($d['node']['logic']['text'])) return '';
        if (! in_array($prop, $d['type']->inline, true) && ! ($d['type']->name === 'code' && $prop === 'code')) return '';
        if (($d['node']['bind'][$prop] ?? null) === 'entry.title' && $d['scope']->get('entry') !== null) {
            return ' data-cms-entry-field="title"';
        }
        $binding = $d['node']['bind'][$prop] ?? null;
        if ($index === null && is_string($binding) && ($instance = $d['renderer']->instanceProperty($binding))) {
            return ' data-cms-instance-document="'.e($instance['document']).'" data-cms-instance-node="'.e($instance['node']).'" data-cms-instance-prop="'.e($instance['prop']).'" data-cms-instance-type="'.e($instance['type']).'" data-cms-instance-format="plain" data-cms-instance-value="'.e($instance['value']).'"';
        }
        if (empty($d['editable']) || isset($d['node']['bind'][$prop])) return '';

        return ' data-cms-prop="'.e($prop).'"'.($index !== null ? ' data-cms-index="'.$index.'"' : '');
    }

    /** Editor only: a neutral graphic where media will go, in the shape it will have. */
    public static function mediaPlaceholder(array $d, string $icon, string $aspect = '16/9', string $tag = 'figure'): string
    {
        $aspect = preg_match('#^\d+(\.\d+)?/\d+(\.\d+)?$#', $aspect) ? $aspect : '16/9';

        return "<{$tag} {$d['attrs']} data-cms-media><span class=\"cms-media-placeholder\" style=\"aspect-ratio:{$aspect}\">".Icons::svg($icon)."</span></{$tag}>";
    }

    public static function text(array $d): string
    {
        $tag = in_array($d['block']['tag'] ?? 'p', self::TEXT_TAGS, true) ? ($d['block']['tag'] ?? 'p') : 'p';
        $text = (string) ($d['block']['text'] ?? '');

        if ($text === '' && ! $d['editor']) {
            return '';
        }

        return "<{$tag} {$d['attrs']}".self::editable($d, 'text').self::placeholder($d, 'text').">{$text}</{$tag}>";
    }

    public static function heading(array $d): string
    {
        $level = max(1, min(6, (int) ($d['block']['level'] ?? 2)));
        $text = (string) ($d['block']['text'] ?? '');

        if ($text === '' && ! $d['editor']) {
            return '';
        }

        $id = ! empty($d['block']['anchor']) && ! str_contains((string) $d['attrs'], ' id="') && ! str_starts_with((string) $d['attrs'], 'id="') ? ' id="'.e($d['block']['anchor']).'"' : '';

        $permalink = (! empty($d['block']['permalink']) || ($d['renderer']->headingLinks && $level > 1)) && ! empty($d['block']['anchor']) && ! isset($d['placeholders']['text'])
            ? '<a class="cms-heading__link" href="#'.e((string) $d['block']['anchor']).'" aria-label="'.e(__('Link to this section')).'">#</a>'
            : '';

        return "<h{$level} {$d['attrs']}{$id}".self::editable($d, 'text').self::placeholder($d, 'text').'>'.$permalink.$text."</h{$level}>";
    }

    public static function link(array $d): string
    {
        $b = $d['block'];
        $url = self::url($b['url'] ?? null);
        $label = (string) ($b['label'] ?? '');
        $placeholder = (empty($d['node']['children']) ? self::editable($d, 'label') : '').(trim((string) $d['content']) === '' ? self::placeholder($d, 'label') : '');

        return $url === null
            ? '<span '.$d['attrs'].$placeholder.'>'.$label.$d['content'].'</span>'
            : '<a '.$d['attrs'].$placeholder.self::href($url, ! empty($b['new_tab']), $d['scope']).'>'.$label.$d['content'].'</a>';
    }

    public static function button(array $d): string
    {
        $b = $d['block'];
        $url = self::url($b['url'] ?? null);
        $meta = ' data-variant="'.e((string) ($b['variant'] ?? 'primary')).'" data-size="'.e((string) ($b['size'] ?? 'md')).'"';
        $icon = ! empty($b['icon']) ? Icons::svg((string) $b['icon'], 'cms-button__icon') : '';
        $label = '<span'.self::editable($d, 'label').self::placeholder($d, 'label').'>'.($b['label'] ?? '').'</span>';
        $inner = ($b['icon_position'] ?? 'end') === 'start' ? $icon.$label : $label.$icon;

        if ($url !== null) {
            return '<a '.$d['attrs'].$meta.self::href($url, ! empty($b['new_tab']), $d['scope']).'>'.$inner.'</a>';
        }

        $type = in_array($b['type'] ?? 'button', ['button', 'submit'], true) ? ($b['type'] ?? 'button') : 'button';

        return '<button type="'.$type.'" '.$d['attrs'].$meta.'>'.$inner.'</button>';
    }

    public static function icon(array $d): string
    {
        $b = $d['block'];
        $label = trim((string) ($b['label'] ?? ''));
        $svg = Icons::svg((string) ($b['icon'] ?? ''));

        if ($svg === '' && ! empty($b['svg'])) {
            $svg = (string) cms()->hooks()->filter('blocks.svg', '', (string) $b['svg']);
        }

        if ($svg === '') {
            return $d['editor'] ? '<span '.$d['attrs'].' data-cms-media aria-hidden="true">'.Icons::svg('star').'</span>' : '';
        }

        $a11y = $label !== '' ? ' role="img" aria-label="'.e($label).'"' : ' aria-hidden="true"';

        return '<span '.$d['attrs'].$a11y.'>'.$svg.'</span>';
    }

    public static function image(array $d): string
    {
        $b = $d['block'];
        $aspect = ($b['aspect'] ?? 'auto') !== 'auto' ? $b['aspect'] : null;
        $img = Media::img($b['image'] ?? null, (string) ($b['alt'] ?? ''), ['priority' => ! empty($b['priority']), 'aspect' => $aspect, 'sizes' => (string) ($b['sizes'] ?? '') ?: '(min-width: 72rem) 72rem, 100vw']);

        if ($img === '') {
            return $d['editor'] ? self::mediaPlaceholder($d, 'image', $aspect ?? '3/2') : '';
        }

        $link = self::url($b['link'] ?? null);
        $inner = $link !== null ? '<a href="'.e($link).'">'.$img.'</a>' : $img;
        $slot = self::editable($d, 'caption');
        $caption = ! empty($b['caption']) ? '<figcaption'.$slot.self::placeholder($d, 'caption').'>'.($b['caption'] ?? '').'</figcaption>' : '';

        return '<figure '.$d['attrs'].'>'.$inner.$caption.'</figure>';
    }

    public static function video(array $d): string
    {
        $b = $d['block'];
        $embed = Embeds::video((string) ($b['url'] ?? ''), ! empty($b['autoplay']));

        if (! $embed) {
            return $d['editor'] ? self::mediaPlaceholder($d, 'play') : '';
        }

        $slot = self::editable($d, 'caption');
        $caption = ! empty($b['caption']) ? '<figcaption'.$slot.self::placeholder($d, 'caption').'>'.($b['caption'] ?? '').'</figcaption>' : '';

        if ($embed['kind'] === 'iframe') {
            $title = e(strip_tags((string) ($b['caption'] ?? '')) ?: 'Video');

            return '<figure '.$d['attrs'].'><div class="cms-ratio" style="aspect-ratio:16/9"><iframe src="'.e($embed['src']).'" title="'.$title.'" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>'.$caption.'</figure>';
        }

        $poster = Media::url($b['poster'] ?? null);
        $flags = ! empty($b['autoplay']) ? ' autoplay muted loop playsinline' : (! empty($b['controls'] ?? true) ? ' controls' : '');
        $flags .= empty($b['autoplay']) && ! empty($b['loop']) ? ' loop' : '';

        return '<figure '.$d['attrs'].'><video src="'.e($embed['src']).'"'.$flags.' preload="metadata"'.($poster ? ' poster="'.e($poster).'"' : '').'></video>'.$caption.'</figure>';
    }

    /** Hidden until the motion runtime shows it: without scripts nothing runs forever, so there's nothing to pause. */
    public static function motionToggle(array $d): string
    {
        $b = $d['block'];
        $pause = (string) ($b['pause_label'] ?? 'Pause animations');

        $d['renderer']->uses('motion');
        $d['renderer']->uses('motion-toggle');

        return '<button type="button" '.$d['attrs'].' aria-pressed="false" data-cms-motion-toggle data-pause-label="'.e($pause).'" data-play-label="'.e((string) ($b['play_label'] ?? 'Play animations')).'"'.($d['editor'] ? '' : ' hidden').'>'.e($pause).'</button>';
    }

    public static function code(array $d): string
    {
        $b = $d['block'];
        $language = (string) ($b['language'] ?? 'plain');
        $class = $language !== 'plain' ? ' class="language-'.e($language).'"' : '';
        $highlight = $language !== 'plain' && ($b['highlight'] ?? true);
        $copy = (bool) ($b['copy'] ?? false);
        $title = trim((string) ($b['title'] ?? ''));

        if ($highlight || $copy) {
            $d['renderer']->uses('code');
        }

        $code = (string) ($b['code'] ?? '');
        $placeholder = $code === '' && $d['editor'] ? ' data-cms-placeholder="'.e(__('Code')).'"' : '';
        // A long line scrolls, and keyboard users can only scroll what takes focus.
        $pre = '<pre tabindex="0"><code'.$class.self::editable($d, 'code').($highlight ? ' data-cms-highlight' : '').$placeholder.'>'.e($code).'</code></pre>';

        if (! $copy && $title === '') {
            return '<div '.$d['attrs'].'>'.$pre.'</div>';
        }

        $head = '<div class="cms-code__head"><span>'.e($title !== '' ? $title : self::LANGUAGES[$language] ?? 'Code').'</span>'
            .($copy ? '<button type="button" class="cms-code__copy" data-cms-copy aria-label="Copy code">'.Icons::svg('copy').'<span>Copy</span></button>' : '').'</div>';

        return '<div '.$d['attrs'].' data-titled>'.$head.$pre.'</div>';
    }

    public const array LANGUAGES = ['bash' => 'Terminal', 'php' => 'PHP', 'javascript' => 'JavaScript', 'typescript' => 'TypeScript', 'json' => 'JSON', 'yaml' => 'YAML', 'html' => 'HTML', 'css' => 'CSS', 'markdown' => 'Markdown', 'python' => 'Python', 'sql' => 'SQL', 'plain' => 'Text'];

    public static function repeat(array $d): string
    {
        $b = $d['block'];
        $tag = in_array($b['tag'] ?? 'div', self::BOX_TAGS, true) ? ($b['tag'] ?? 'div') : 'div';
        $items = $d['renderer']->repeat($d['node'], (string) ($b['source'] ?? 'list'), $b);

        if ($items === []) {
            // The canvas: no children yet, so the empty drop area shows (with data, sample items do).
            if ($d['editor']) {
                return "<{$tag} {$d['attrs']}></{$tag}>";
            }

            return ! empty($b['empty']) ? '<p '.$d['attrs'].' data-empty>'.e((string) $b['empty']).'</p>' : '';
        }

        return "<{$tag} {$d['attrs']}>".implode('', $items)."</{$tag}>";
    }

    public static function outlet(array $d): string
    {
        $document = $d['renderer']->outletEditorRoot();
        if ($document !== null) $d['attrs'] .= ' data-cms-editor-root="'.e($document).'"';
        $html = $d['renderer']->outlet(! empty($d['block']['permalinks']));

        if ($html === '' && $d['editor']) {
            return '<div '.$d['attrs'].'><div class="cms-placeholder"'.($document !== null ? ' data-cms-editor-empty-root' : '').'>'.e(__('Page Content')).'</div></div>';
        }

        return $d['block']['wrap'] ?? false ? '<div '.$d['attrs'].'>'.$html.'</div>' : ($d['editor'] ? '<div '.$d['attrs'].' style="display:contents">'.$html.'</div>' : $html);
    }

    public static function input(array $d): string
    {
        $b = $d['block'];
        $type = in_array($b['type'] ?? 'text', ['text', 'search', 'email', 'password', 'number', 'range', 'checkbox', 'select', 'textarea'], true) ? ($b['type'] ?? 'text') : 'text';
        $common = ' aria-label="'.e((string) ($b['label'] ?? '')).'"'.(! empty($b['name']) ? ' name="'.e((string) $b['name']).'"' : '');

        if ($type === 'select') {
            $options = implode('', array_map(static fn ($o) => '<option'.((string) $o === (string) ($b['value'] ?? '') ? ' selected' : '').'>'.e((string) $o).'</option>', (array) ($b['options'] ?? [])));

            return '<select '.$d['attrs'].$common.'>'.$options.'</select>';
        }

        if ($type === 'textarea') {
            return '<textarea '.$d['attrs'].$common.' placeholder="'.e((string) ($b['placeholder'] ?? '')).'">'.e((string) ($b['value'] ?? '')).'</textarea>';
        }

        $extra = '';

        foreach (['min', 'max', 'step'] as $attr) {
            if (isset($b[$attr]) && is_numeric($b[$attr])) {
                $extra .= ' '.$attr.'="'.e((string) $b[$attr]).'"';
            }
        }

        if ($type === 'checkbox') {
            return '<input type="checkbox" '.$d['attrs'].$common.(! empty($b['value']) ? ' checked' : '').'>';
        }

        return '<input type="'.$type.'" '.$d['attrs'].$common.$extra.' placeholder="'.e((string) ($b['placeholder'] ?? '')).'" value="'.e((string) ($b['value'] ?? '')).'">';
    }

    public static function search(array $d): string
    {
        $b = $d['block'];
        $d['renderer']->uses('search');
        $config = json_encode(['placeholder' => (string) ($b['placeholder'] ?? 'Search…'), 'empty' => (string) ($b['empty'] ?? 'No results'), 'shortcut' => (bool) ($b['shortcut'] ?? true)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $label = (string) ($b['label'] ?? 'Search');
        $kbd = ($b['shortcut'] ?? true) ? '<span class="cms-search__kbd" aria-hidden="true"><kbd>⌘</kbd><kbd>K</kbd></span>' : '';
        $compact = in_array($b['compact'] ?? 'never', ['mobile', 'always'], true) ? ' data-compact="'.$b['compact'].'"' : '';

        return '<button type="button" '.$d['attrs'].$compact.' data-cms-search="'.e((string) $config).'" aria-haspopup="dialog">'.Icons::svg('magnifying-glass', 'cms-search__icon').'<span class="cms-search__label">'.e($label).'</span>'.$kbd.'</button>';
    }

    public static function section(array $d): string
    {
        $b = $d['block'];
        $tag = in_array($b['tag'] ?? 'section', ['section', 'header', 'footer', 'aside', 'div', 'main', 'nav', 'article'], true) ? ($b['tag'] ?? 'section') : 'section';
        $bg = Media::url($b['background_image'] ?? null);
        $style = $bg ? ' data-overlay="'.e((string) ($b['overlay'] ?? 'none')).'" style="background-image:url(\''.e($bg).'\')"' : '';

        return "<{$tag} {$d['attrs']} data-width=\"".e((string) ($b['width'] ?? 'content'))."\"{$style}><div class=\"cms-section__inner\">{$d['content']}</div></{$tag}>";
    }

    public static function container(string $tag): \Closure
    {
        return static fn (array $d) => "<{$tag} {$d['attrs']}>{$d['content']}</{$tag}>";
    }

    public static function card(array $d): string
    {
        $link = self::url($d['block']['link'] ?? null);
        $variant = ' data-variant="'.e((string) ($d['block']['variant'] ?? 'outline')).'"';

        return $link !== null
            ? '<a '.$d['attrs'].$variant.self::href($link, false, $d['scope']).'>'.$d['content'].'</a>'
            : '<div '.$d['attrs'].$variant.'>'.$d['content'].'</div>';
    }

    public static function buttons(array $d): string
    {
        return '<div '.$d['attrs'].' data-justify="'.e((string) ($d['block']['justify'] ?? 'start')).'">'.$d['content'].'</div>';
    }

    public static function list(array $d): string
    {
        $tag = ! empty($d['block']['ordered']) ? 'ol' : 'ul';
        $empty = $d['editor'] ? ' data-cms-placeholder="'.e(__('List item')).'"' : '';
        $items = '';
        foreach (array_values(array_filter((array) ($d['block']['items'] ?? []), 'is_string')) as $index => $item) {
            $items .= '<li'.self::editable($d, 'items', $index).(trim(strip_tags($item)) === '' ? $empty : '').'>'.$item.'</li>';
        }

        return "<{$tag} {$d['attrs']}>{$items}</{$tag}>";
    }

    public static function quote(array $d): string
    {
        $slot = self::editable($d, 'cite');
        $cite = ! empty($d['block']['cite']) ? '<figcaption'.$slot.self::placeholder($d, 'cite').'>'.($d['block']['cite'] ?? '').'</figcaption>' : '';

        return '<figure '.$d['attrs'].'><blockquote'.self::editable($d, 'text').self::placeholder($d, 'text').'>'.($d['block']['text'] ?? '').'</blockquote>'.$cite.'</figure>';
    }

    public static function callout(array $d): string
    {
        return '<aside '.$d['attrs'].' data-tone="'.e((string) ($d['block']['tone'] ?? 'note')).'" role="note"><p'.self::editable($d, 'text').self::placeholder($d, 'text').'>'.($d['block']['text'] ?? '').'</p></aside>';
    }

    public static function table(array $d): string
    {
        $b = $d['block'];
        $rows = array_values(array_filter((array) ($b['rows'] ?? []), 'is_array'));
        $highlight = (int) ($b['highlight'] ?? 0);
        $rowHeaders = ! empty($b['row_headers']);
        $columns = max([0, ...array_map('count', $rows)]);
        $look = ($b['look'] ?? 'lines') === 'soft' ? ' data-look="soft" data-columns="'.$columns.'"' : '';
        $html = '<figure '.$d['attrs'].$look.'><table>';
        $html .= ! empty($b['caption']) ? '<caption>'.e((string) $b['caption']).'</caption>' : '';
        $body = '';
        // Empty cells show what goes there on the canvas, so a new table is visible.
        $hint = static fn (string $c, string $label) => $c === '' && $d['editor'] ? ' data-cms-placeholder="'.e($label).'"' : '';
        $mark = static fn (int $column) => $column + 1 === $highlight ? ' data-highlight' : '';

        foreach ($rows as $i => $row) {
            $cells = array_values(array_map(static fn ($c) => Sanitizer::inline((string) $c), $row));

            if ($i === 0 && ! empty($b['header'])) {
                $html .= '<thead><tr>'.implode('', array_map(static fn ($c, $n) => '<th scope="col"'.$mark($n).$hint($c, __('Heading')).'>'.$c.'</th>', $cells, array_keys($cells))).'</tr></thead>';
            } else {
                $body .= '<tr>'.implode('', array_map(static fn ($c, $n) => $n === 0 && $rowHeaders
                    ? '<th scope="row"'.$mark($n).$hint($c, __('Heading')).'>'.$c.'</th>'
                    : '<td'.$mark($n).$hint($c, __('Cell')).'>'.$c.'</td>', $cells, array_keys($cells))).'</tr>';
            }
        }

        return $html.'<tbody>'.$body.'</tbody></table></figure>';
    }

    public static function accordion(array $d): string
    {
        $items = array_values(array_filter((array) ($d['block']['items'] ?? []), static fn ($i) => is_array($i) && ! empty($i['question'])));
        $html = '';

        foreach ($items as $item) {
            $html .= '<details><summary>'.e((string) $item['question']).'</summary><div class="cms-accordion__answer">'.($item['answer'] ?? '').'</div></details>';
        }

        if ($items === [] && $d['editor']) {
            $html = str_repeat('<details data-cms-ghost><summary>'.e(__('Question')).'</summary></details>', 3);
        }

        if (! empty($d['block']['faq']) && $items !== [] && ! $d['editor']) {
            Structured::add('faq', ['@type' => 'FAQPage', 'mainEntity' => array_map(static fn ($i) => ['@type' => 'Question', 'name' => $i['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags((string) ($i['answer'] ?? ''))]], $items)]);
        }

        $look = ($d['block']['look'] ?? 'lines') === 'cards' ? ' data-look="cards"' : '';

        return '<div '.$d['attrs'].$look.'>'.$html.'</div>';
    }

    public static function embed(array $d): string
    {
        $src = Embeds::iframe((string) ($d['block']['url'] ?? ''));

        $aspect = preg_match('#^\d+/\d+$#', (string) ($d['block']['aspect'] ?? '')) ? $d['block']['aspect'] : '16/9';

        if (! $src) {
            return $d['editor'] ? self::mediaPlaceholder($d, 'browser', $aspect) : '';
        }

        return '<figure '.$d['attrs'].'><div class="cms-ratio" style="aspect-ratio:'.$aspect.'"><iframe src="'.e($src).'" title="'.e((string) ($d['block']['title'] ?? 'Embedded content')).'" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-presentation"></iframe></div></figure>';
    }

    /**
     * A URL people may link to (http(s), relative, anchors, mail, phone), or a page
     * by reference — `entry:<id>`, optionally `entry:<id>#section` — which follows
     * the page when it moves or is renamed (and rebuilds pages that link to it).
     */
    public static function url(mixed $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';

        if (preg_match('/^entry:([a-z0-9]{1,40})(#[\w-]{1,80})?$/i', $url, $m)) {
            return self::entryUrl(strtolower($m[1]), $m[2] ?? '');
        }

        return $url !== '' && preg_match('#^(https?://|/|\#|\?|mailto:|tel:)#i', $url) ? $url : null;
    }

    private static function entryUrl(string $id, string $fragment): ?string
    {
        cms()->hooks()->dispatch('render.depend', 'entry:'.$id);
        $entry = cms()->entries()->find($id);

        // The canvas links to drafts too; the site only to live pages.
        if (! $entry || $entry->uri === null || (! $entry->isLive() && ! app(BlockRenderer::class)->editor)) {
            return null;
        }

        return (function_exists('cms_url') ? cms_url($entry->uri) : $entry->uri).$fragment;
    }

    /** href plus target, rel and aria-current when it is the page being rendered. */
    public static function href(string $url, bool $newTab, Scope $scope): string
    {
        $out = ' href="'.e($url).'"';

        if ($newTab) {
            $out .= ' target="_blank" rel="noopener"';
        }

        $current = $scope->get('page.uri');

        if (is_string($current) && str_starts_with($url, '/') && rtrim((string) parse_url($url, PHP_URL_PATH), '/') === rtrim($current, '/')) {
            $out .= ' aria-current="page"';
        }

        return $out;
    }
}
