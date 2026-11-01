<?php

declare(strict_types=1);

namespace Cms\Blocks;

/**
 * The built-in block catalog. Each is ordinary data plus a PHP render
 * function; plugins add more the same way (BlockRegistry::register).
 *
 * Primitives (box, text, heading, link, image, icon, repeat, outlet) compose
 * anything; the content blocks (section, columns, card, quote…) are shortcuts
 * available in the same shared block editor.
 *
 * Titles, descriptions and field labels are English; `blocks/types` hands them to
 * the admin in the user's language. Placeholders are marked with __() so the
 * dictionaries list them (this runs at boot: they stay English).
 */
final class CoreBlocks
{
    private const array TEXT = ['layout', 'position', 'size', 'spacing', 'typography', 'fill', 'border', 'effects', 'transform', 'transition'];

    /** @return list<array> */
    public static function all(): array
    {
        $space = static fn (string $default) => ['type' => 'select', 'options' => [
            'token:space.0' => 'None', 'token:space.2' => 'XS', 'token:space.4' => 'S', 'token:space.6' => 'M', 'token:space.8' => 'L', 'token:space.12' => 'XL', 'token:space.16' => '2XL',
        ], 'default' => $default];
        $tags = static fn (array $tags, string $default) => ['handle' => 'tag', 'type' => 'select', 'label' => 'HTML Tag', 'options' => array_combine($tags, $tags), 'default' => $default];
        // Links pick a page (stored as entry:<id>, so they follow it), an anchor, a URL, an email or a phone number.
        $url = static fn (string $handle, string $label, array $extra = []) => ['handle' => $handle, 'type' => 'url', 'component' => 'link', 'reference' => true, 'label' => $label, ...$extra];
        $link = [
            $url('link', 'Link'),
            ['handle' => 'new_tab', 'type' => 'toggle', 'label' => 'Open in New Tab'],
        ];

        return [
            // ── Layout ─────────────────────────────────────────────
            [
                'name' => 'box', 'title' => 'Box', 'icon' => 'square', 'category' => 'layout',
                'description' => 'A container for anything: lay out its children as a stack or grid with the Design panel. Any HTML tag, optionally a link or button.',
                'keywords' => ['div', 'frame', 'container', 'group', 'wrapper', 'flex', 'stack', 'row', 'nav', 'header'],
                'children' => true, 'html' => 'div',
                'fields' => [$tags(Elements::BOX_TAGS, 'div'), ...$link, ['handle' => 'type', 'type' => 'select', 'label' => 'Button Type', 'options' => ['button' => 'Button', 'submit' => 'Submit'], 'default' => 'button', 'if' => ['tag' => 'button']]],
                'render' => Elements::box(...),
                'example' => ['tag' => 'nav'],
            ],
            [
                'name' => 'section', 'title' => 'Section', 'icon' => 'section', 'category' => 'layout',
                'description' => 'A full-width band with a centered inner width.', 'keywords' => ['container', 'band', 'hero', 'wrapper'],
                'children' => true, 'html' => 'section',
                'fields' => [
                    ['handle' => 'width', 'type' => 'select', 'label' => 'Content Width', 'options' => ['prose' => 'Text', 'content' => 'Content', 'wide' => 'Wide', 'full' => 'Full'], 'default' => 'content', 'display' => 'segmented'],
                    ['handle' => 'tag', 'type' => 'select', 'label' => 'HTML Tag', 'options' => ['section' => 'section', 'header' => 'header', 'footer' => 'footer', 'aside' => 'aside', 'main' => 'main', 'nav' => 'nav', 'article' => 'article', 'div' => 'div'], 'default' => 'section'],
                    ['handle' => 'background_image', 'type' => 'media', 'fallback' => 'url', 'label' => 'Background Image', 'max' => 1],
                    ['handle' => 'overlay', 'type' => 'select', 'label' => 'Overlay', 'options' => ['none' => 'None', 'light' => 'Light', 'dark' => 'Dark'], 'default' => 'none'],
                ],
                'defaults' => ['width' => 'content'],
                'template' => [['type' => 'heading', 'props' => ['text' => '', 'level' => '2']], ['type' => 'paragraph', 'props' => ['text' => '']]],
                'render' => Elements::section(...),
            ],
            [
                'name' => 'stack', 'title' => 'Stack', 'icon' => 'layers', 'category' => 'layout',
                'description' => 'Children in a row or column with even spacing.', 'keywords' => ['group', 'row', 'flex', 'container'],
                'children' => true,
                'fields' => [
                    ['handle' => 'direction', 'type' => 'select', 'label' => 'Direction', 'options' => ['vertical' => 'Vertical', 'horizontal' => 'Horizontal'], 'default' => 'vertical', 'display' => 'segmented'],
                    ['handle' => 'gap', 'label' => 'Gap', ...$space('token:space.4')],
                    ['handle' => 'align', 'type' => 'select', 'label' => 'Align', 'options' => ['stretch' => 'Stretch', 'start' => 'Start', 'center' => 'Center', 'end' => 'End'], 'default' => 'stretch'],
                    ['handle' => 'justify', 'type' => 'select', 'label' => 'Justify', 'options' => ['start' => 'Start', 'center' => 'Center', 'end' => 'End', 'between' => 'Space Between'], 'default' => 'start'],
                    ['handle' => 'wrap', 'type' => 'toggle', 'label' => 'Wrap'],
                    ...$link,
                ],
                'derive' => static fn (array $p) => ['base' => array_filter([
                    'display' => 'flex',
                    'flexDirection' => ($p['direction'] ?? 'vertical') === 'horizontal' ? 'row' : 'column',
                    'gap' => $p['gap'] ?? 'token:space.4',
                    'alignItems' => ['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end'][$p['align'] ?? ''] ?? (($p['direction'] ?? '') === 'horizontal' ? 'center' : null),
                    'justifyContent' => ['center' => 'center', 'end' => 'flex-end', 'between' => 'space-between'][$p['justify'] ?? ''] ?? null,
                    'flexWrap' => ! empty($p['wrap']) ? 'wrap' : null,
                ])],
                'render' => Elements::box(...),
            ],
            [
                'name' => 'columns', 'title' => 'Columns', 'icon' => 'columns', 'category' => 'layout',
                'description' => 'Side-by-side columns that stack on small screens.', 'keywords' => ['grid', 'split', 'two columns'],
                'children' => ['column'],
                'fields' => [
                    ['handle' => 'layout', 'type' => 'select', 'label' => 'Layout', 'options' => ['1-1' => '½ ½', '1-2' => '⅓ ⅔', '2-1' => '⅔ ⅓', '1-1-1' => '⅓ ⅓ ⅓', '1-1-1-1' => '¼ ¼ ¼ ¼', '3-2' => '⅗ ⅖', '2-3' => '⅖ ⅗'], 'default' => '1-1'],
                    ['handle' => 'gap', 'label' => 'Gap', ...$space('token:space.8')],
                    ['handle' => 'align', 'type' => 'select', 'label' => 'Vertical Align', 'options' => ['stretch' => 'Stretch', 'start' => 'Top', 'center' => 'Middle', 'end' => 'Bottom'], 'default' => 'start'],
                    ['handle' => 'stack', 'type' => 'select', 'label' => 'Stack Below', 'options' => ['md' => 'Tablet', 'lg' => 'Desktop', 'never' => 'Never'], 'default' => 'md'],
                    ['handle' => 'reverse', 'type' => 'toggle', 'label' => 'Reverse Order When Stacked'],
                ],
                'derive' => static function (array $p): array {
                    $layout = preg_match('/^\d(-\d){1,3}$/', (string) ($p['layout'] ?? '')) ? (string) $p['layout'] : '1-1';
                    $cols = implode(' ', array_map(static fn ($n) => "minmax(0,{$n}fr)", explode('-', $layout)));
                    $stack = $p['stack'] ?? 'md';
                    $style = ['base' => ['display' => 'grid', 'gap' => $p['gap'] ?? 'token:space.8', 'alignItems' => ['start' => 'start', 'center' => 'center', 'end' => 'end', 'stretch' => 'stretch'][$p['align'] ?? 'start'] ?? 'start', 'gridTemplateColumns' => $cols]];

                    if ($stack !== 'never') {
                        $style[$stack === 'lg' ? 'tablet' : 'mobile'] = ['gridTemplateColumns' => 'minmax(0,1fr)'];
                    }

                    return $style;
                },
                'template' => [
                    ['type' => 'column', 'children' => [['type' => 'paragraph', 'props' => ['text' => '']]]],
                    ['type' => 'column', 'children' => [['type' => 'paragraph', 'props' => ['text' => '']]]],
                ],
                'render' => static fn (array $d) => '<div '.$d['attrs'].(! empty($d['block']['reverse']) ? ' data-reverse' : '').' data-stack="'.e((string) ($d['block']['stack'] ?? 'md')).'">'.$d['content'].'</div>',
            ],
            ['name' => 'column', 'title' => 'Column', 'icon' => 'square', 'category' => 'layout', 'parent' => 'columns', 'children' => true, 'description' => 'One column of a Columns block.', 'render' => Elements::container('div')],
            [
                'name' => 'grid', 'title' => 'Grid', 'icon' => 'grid', 'category' => 'layout',
                'description' => 'A responsive grid of items (cards, features, logos).', 'keywords' => ['cards', 'features', 'logos', 'tiles'],
                'children' => true,
                'fields' => [
                    ['handle' => 'columns', 'type' => 'number', 'label' => 'Columns', 'min' => 1, 'max' => 12, 'default' => 3],
                    ['handle' => 'min', 'type' => 'select', 'label' => 'Minimum Item Width', 'options' => ['10rem' => 'XS', '12rem' => 'S', '16rem' => 'M', '20rem' => 'L', '24rem' => 'XL'], 'default' => '16rem'],
                    ['handle' => 'gap', 'label' => 'Gap', ...$space('token:space.6')],
                ],
                'derive' => static function (array $p): array {
                    $n = max(1, min(12, (int) ($p['columns'] ?? 3)));
                    $min = in_array($p['min'] ?? '16rem', ['10rem', '12rem', '16rem', '20rem', '24rem'], true) ? ($p['min'] ?? '16rem') : '16rem';

                    return [
                        'base' => ['display' => 'grid', 'gap' => $p['gap'] ?? 'token:space.6', 'gridTemplateColumns' => "repeat({$n},minmax(0,1fr))"],
                        'tablet' => ['gridTemplateColumns' => "repeat(auto-fill,minmax(min(100%,{$min}),1fr))"],
                    ];
                },
                'template' => [
                    ['type' => 'card', 'children' => [['type' => 'heading', 'props' => ['text' => 'Feature', 'level' => '3']], ['type' => 'paragraph', 'props' => ['text' => '']]]],
                    ['type' => 'card', 'children' => [['type' => 'heading', 'props' => ['text' => 'Feature', 'level' => '3']], ['type' => 'paragraph', 'props' => ['text' => '']]]],
                    ['type' => 'card', 'children' => [['type' => 'heading', 'props' => ['text' => 'Feature', 'level' => '3']], ['type' => 'paragraph', 'props' => ['text' => '']]]],
                ],
                'render' => Elements::container('div'),
            ],
            [
                'name' => 'card', 'title' => 'Card', 'icon' => 'cards', 'category' => 'layout',
                'description' => 'A padded container, optionally linked.', 'keywords' => ['box', 'panel', 'tile'],
                'children' => true,
                'fields' => [
                    $url('link', 'Link'),
                    ['handle' => 'variant', 'type' => 'select', 'label' => 'Look', 'options' => ['plain' => 'Plain', 'outline' => 'Outline', 'filled' => 'Filled', 'raised' => 'Raised'], 'default' => 'outline', 'display' => 'segmented'],
                ],
                'render' => Elements::card(...),
            ],
            [
                'name' => 'gate-break', 'title' => 'Teaser Ends Here', 'icon' => 'divider', 'category' => 'dynamic',
                'description' => 'On a page with a teaser, readers without access see what comes before this.', 'keywords' => ['paywall', 'members', 'more'], 'styles' => [],
                'render' => static fn (array $d) => $d['editor'] ? '<div '.$d['attrs'].'><div class="cms-placeholder">'.e(__('Teaser ends here')).'</div></div>' : '',
            ],
            ['name' => 'divider', 'title' => 'Divider', 'icon' => 'divider', 'category' => 'layout', 'description' => 'A horizontal rule.', 'keywords' => ['hr', 'line', 'separator'], 'render' => static fn (array $d) => '<hr '.$d['attrs'].'>'],
            [
                'name' => 'spacer', 'title' => 'Spacer', 'icon' => 'height', 'category' => 'layout', 'description' => 'Vertical space.', 'keywords' => ['gap', 'space'],
                'fields' => [['handle' => 'size', 'label' => 'Size', ...$space('token:space.12')]],
                'derive' => static fn (array $p) => ['base' => ['height' => $p['size'] ?? 'token:space.12']],
                'render' => static fn (array $d) => '<div '.$d['attrs'].' aria-hidden="true"></div>',
            ],

            // ── Text ───────────────────────────────────────────────
            [
                'name' => 'paragraph', 'title' => 'Text', 'icon' => 'paragraph', 'category' => 'text',
                'description' => 'A paragraph — or any short text (label, caption, eyebrow) with the tag you choose.', 'keywords' => ['p', 'text', 'body', 'span', 'label'],
                'inline' => ['text'], 'styles' => self::TEXT, 'html' => 'p', 'placeholders' => ['text' => __('Write something…')],
                'fields' => [
                    ['handle' => 'text', 'type' => 'richtext', 'label' => 'Text', 'inline' => true],
                    $tags(Elements::TEXT_TAGS, 'p'),
                ],
                'render' => Elements::text(...),
                'example' => ['text' => 'Write something <strong>meaningful</strong>.'],
            ],
            [
                'name' => 'heading', 'title' => 'Heading', 'icon' => 'heading', 'category' => 'text',
                'description' => 'A title (h1–h6).', 'keywords' => ['title', 'h1', 'h2', 'h3'],
                'inline' => ['text'], 'styles' => self::TEXT, 'html' => 'h2', 'placeholders' => ['text' => __('Heading')],
                'fields' => [
                    ['handle' => 'text', 'type' => 'richtext', 'label' => 'Text', 'inline' => true],
                    ['handle' => 'level', 'type' => 'select', 'label' => 'Level', 'options' => ['1' => 'H1', '2' => 'H2', '3' => 'H3', '4' => 'H4', '5' => 'H5', '6' => 'H6'], 'default' => '2', 'display' => 'segmented', 'required' => true],
                    ['handle' => 'anchor', 'type' => 'slug', 'label' => 'Anchor'],
                    ['handle' => 'permalink', 'type' => 'toggle', 'label' => 'Show # Link on Hover'],
                ],
                'render' => Elements::heading(...),
                'example' => ['text' => 'Why teams switch', 'level' => '2'],
            ],
            [
                'name' => 'link', 'title' => 'Link', 'icon' => 'link', 'category' => 'text',
                'description' => 'A text link. Can hold other blocks (an icon, a label) too. Marks itself as current on its own page.', 'keywords' => ['a', 'href', 'nav item', 'anchor'],
                'inline' => ['label'], 'children' => true, 'html' => 'a', 'placeholders' => ['label' => __('Link')],
                'fields' => [
                    ['handle' => 'label', 'type' => 'richtext', 'label' => 'Label', 'inline' => true],
                    $url('url', 'Link'),
                    ['handle' => 'new_tab', 'type' => 'toggle', 'label' => 'Open in New Tab'],
                ],
                'render' => Elements::link(...),
                'example' => ['label' => 'About', 'url' => '/about'],
            ],
            [
                'name' => 'list', 'title' => 'List', 'icon' => 'list', 'category' => 'text',
                'description' => 'Bulleted or numbered list.', 'keywords' => ['ul', 'ol', 'bullets'],
                'inline' => ['items'], 'styles' => self::TEXT, 'placeholders' => ['items' => __('List item')],
                'fields' => [
                    ['handle' => 'items', 'type' => 'json', 'label' => 'Items', 'default' => ['']],
                    ['handle' => 'ordered', 'type' => 'toggle', 'label' => 'Numbered'],
                ],
                'render' => Elements::list(...),
                'example' => ['items' => ['Fast', 'Accessible', 'Yours'], 'ordered' => false],
            ],
            [
                'name' => 'quote', 'title' => 'Quote', 'icon' => 'quote', 'category' => 'text',
                'description' => 'A quotation or testimonial.', 'keywords' => ['blockquote', 'testimonial', 'cite'],
                'inline' => ['text', 'cite'], 'placeholders' => ['text' => __('Quote')],
                'fields' => [
                    ['handle' => 'text', 'type' => 'richtext', 'label' => 'Quote', 'inline' => true],
                    ['handle' => 'cite', 'type' => 'richtext', 'label' => 'Attribution', 'inline' => true],
                ],
                'render' => Elements::quote(...),
            ],
            [
                'name' => 'code', 'title' => 'Code', 'icon' => 'code', 'category' => 'text',
                'description' => 'Preformatted code with syntax highlighting and a copy button.', 'keywords' => ['pre', 'snippet', 'terminal'],
                'fields' => [
                    ['handle' => 'code', 'type' => 'code', 'label' => 'Code'],
                    ['handle' => 'language', 'type' => 'select', 'label' => 'Language', 'options' => array_keys(Elements::LANGUAGES), 'default' => 'plain'],
                    ['handle' => 'title', 'type' => 'text', 'label' => 'Title', 'placeholder' => 'File name'],
                    ['handle' => 'highlight', 'type' => 'toggle', 'label' => 'Highlight Syntax', 'default' => true],
                    ['handle' => 'copy', 'type' => 'toggle', 'label' => 'Copy Button', 'default' => true],
                ],
                'render' => Elements::code(...),
            ],
            [
                'name' => 'callout', 'title' => 'Callout', 'icon' => 'info', 'category' => 'text',
                'description' => 'Highlighted note, tip or warning.', 'keywords' => ['note', 'tip', 'warning', 'alert'],
                'inline' => ['text'], 'placeholders' => ['text' => __('Write something…')],
                'fields' => [
                    ['handle' => 'text', 'type' => 'richtext', 'label' => 'Text', 'inline' => true],
                    ['handle' => 'tone', 'type' => 'select', 'label' => 'Tone', 'options' => ['note' => 'Note', 'tip' => 'Tip', 'warning' => 'Warning', 'danger' => 'Danger'], 'default' => 'note', 'display' => 'segmented'],
                ],
                'render' => Elements::callout(...),
            ],
            [
                'name' => 'table', 'title' => 'Table', 'icon' => 'table', 'category' => 'text',
                'description' => 'Rows and columns — great for comparisons (and for AI answers).', 'keywords' => ['grid', 'comparison', 'pricing'],
                'fields' => [
                    ['handle' => 'rows', 'type' => 'json', 'label' => 'Rows', 'default' => [['', ''], ['', '']]],
                    ['handle' => 'header', 'type' => 'toggle', 'label' => 'First Row Is a Header', 'default' => true],
                    ['handle' => 'row_headers', 'type' => 'toggle', 'label' => 'First Column Is a Header', 'default' => false],
                    ['handle' => 'highlight', 'type' => 'number', 'label' => 'Highlight Column', 'help' => 'The column to mark, counting from 1. Empty marks none.'],
                    ['handle' => 'look', 'type' => 'select', 'label' => 'Look', 'options' => ['lines' => 'Lines', 'soft' => 'Soft'], 'default' => 'lines', 'display' => 'segmented'],
                    ['handle' => 'caption', 'type' => 'text', 'label' => 'Caption'],
                ],
                'render' => Elements::table(...),
            ],

            // ── Media ──────────────────────────────────────────────
            [
                'name' => 'image', 'title' => 'Image', 'icon' => 'image', 'category' => 'media',
                'description' => 'A responsive image (WebP/AVIF, lazy-loaded).', 'keywords' => ['photo', 'picture', 'figure'],
                'inline' => ['caption'], 'html' => 'figure',
                'fields' => [
                    ['handle' => 'image', 'type' => 'media', 'fallback' => 'url', 'label' => 'Image', 'max' => 1, 'accept' => 'image/*'],
                    ['handle' => 'alt', 'type' => 'text', 'label' => 'Alt Text', 'placeholder' => 'Decorative', 'instructions' => 'Describes the image for screen readers and search engines.'],
                    ['handle' => 'caption', 'type' => 'richtext', 'label' => 'Caption', 'inline' => true],
                    $url('link', 'Link'),
                    ['handle' => 'aspect', 'type' => 'select', 'label' => 'Aspect Ratio', 'options' => ['auto' => 'Original', '1/1' => 'Square', '4/3' => '4:3', '3/2' => '3:2', '16/9' => '16:9', '21/9' => 'Cinema', '3/4' => 'Portrait', '4/5' => '4:5'], 'default' => 'auto'],
                    ['handle' => 'priority', 'type' => 'toggle', 'label' => 'Load First', 'instructions' => 'For the main image above the fold.'],
                    ['handle' => 'sizes', 'type' => 'text', 'label' => 'Sizes', 'placeholder' => '100vw', 'mono' => true, 'instructions' => 'How wide it is shown, e.g. (min-width: 64rem) 50vw, 100vw.'],
                ],
                'render' => Elements::image(...),
            ],
            [
                'name' => 'video', 'title' => 'Video', 'icon' => 'video', 'category' => 'media',
                'description' => 'YouTube, Vimeo or a video file — also as a silent background loop.', 'keywords' => ['youtube', 'vimeo', 'mp4', 'background'],
                'inline' => ['caption'], 'html' => 'figure',
                'fields' => [
                    ['handle' => 'url', 'type' => 'url', 'label' => 'Video URL', 'required' => true, 'placeholder' => 'YouTube, Vimeo or a video file'],
                    ['handle' => 'caption', 'type' => 'richtext', 'label' => 'Caption', 'inline' => true],
                    ['handle' => 'poster', 'type' => 'media', 'fallback' => 'url', 'label' => 'Poster Image', 'max' => 1],
                    ['handle' => 'autoplay', 'type' => 'toggle', 'label' => 'Autoplay Muted'],
                    ['handle' => 'controls', 'type' => 'toggle', 'label' => 'Show Controls', 'default' => true],
                    ['handle' => 'loop', 'type' => 'toggle', 'label' => 'Loop'],
                ],
                'render' => Elements::video(...),
            ],
            [
                'name' => 'icon', 'title' => 'Icon', 'icon' => 'star', 'category' => 'media',
                'description' => 'An icon from the Phosphor set (1,500+ icons in four weights). Takes the text color; size it with width and height.', 'keywords' => ['svg', 'symbol', 'glyph', 'arrow'],
                'html' => 'span',
                'fields' => [
                    ['handle' => 'icon', 'type' => 'text', 'component' => 'blocks-icon', 'label' => 'Icon', 'default' => 'star'],
                    ['handle' => 'label', 'type' => 'text', 'label' => 'Accessible Label', 'placeholder' => 'Decorative'],
                    ['handle' => 'svg', 'type' => 'code', 'label' => 'Custom SVG', 'language' => 'html', 'instructions' => 'Used instead of a named icon.'],
                ],
                'render' => Elements::icon(...),
                'example' => ['icon' => 'arrow-up-right'],
            ],
            [
                'name' => 'embed', 'title' => 'Embed', 'icon' => 'embed', 'category' => 'media',
                'description' => 'Maps, forms and other iframes from trusted providers.', 'keywords' => ['iframe', 'map', 'spotify', 'figma'],
                'fields' => [
                    ['handle' => 'url', 'type' => 'url', 'label' => 'URL', 'required' => true, 'placeholder' => 'YouTube, Maps, Spotify, Figma…'],
                    ['handle' => 'title', 'type' => 'text', 'label' => 'Title', 'placeholder' => 'Embedded content', 'instructions' => 'Describes the embed for screen readers.'],
                    ['handle' => 'aspect', 'type' => 'select', 'label' => 'Aspect Ratio', 'options' => ['16/9' => '16:9', '4/3' => '4:3', '1/1' => 'Square', '21/9' => 'Wide'], 'default' => '16/9'],
                ],
                'render' => Elements::embed(...),
            ],

            // ── Interactive ────────────────────────────────────────
            [
                'name' => 'button', 'title' => 'Button', 'icon' => 'button', 'category' => 'interactive',
                'description' => 'A link or button styled with the design system’s button styles.', 'keywords' => ['cta', 'link', 'action'],
                'inline' => ['label'], 'html' => 'a', 'placeholders' => ['label' => __('Button')],
                'fields' => [
                    ['handle' => 'label', 'type' => 'richtext', 'label' => 'Label', 'inline' => true],
                    $url('url', 'Link', ['placeholder' => 'No link (runs an interaction)']),
                    ['handle' => 'variant', 'type' => 'select', 'label' => 'Variant', 'options' => [], 'dynamic' => 'button-variants', 'default' => 'primary'],
                    ['handle' => 'size', 'type' => 'select', 'label' => 'Size', 'options' => ['sm' => 'S', 'md' => 'M', 'lg' => 'L'], 'default' => 'md', 'display' => 'segmented'],
                    ['handle' => 'icon', 'type' => 'text', 'component' => 'blocks-icon', 'label' => 'Icon', 'placeholder' => 'None'],
                    ['handle' => 'icon_position', 'type' => 'select', 'label' => 'Icon Position', 'options' => ['start' => 'Before', 'end' => 'After'], 'default' => 'end', 'display' => 'segmented'],
                    ['handle' => 'new_tab', 'type' => 'toggle', 'label' => 'Open in New Tab'],
                    ['handle' => 'type', 'type' => 'select', 'label' => 'Button Type', 'options' => ['button' => 'Button', 'submit' => 'Submit'], 'default' => 'button'],
                ],
                'defaults' => ['variant' => 'primary', 'size' => 'md'],
                'render' => Elements::button(...),
                'example' => ['label' => 'Get Started', 'url' => '/start', 'variant' => 'primary'],
            ],
            [
                'name' => 'buttons', 'title' => 'Buttons', 'icon' => 'button', 'category' => 'interactive',
                'description' => 'A row of buttons.', 'keywords' => ['ctas', 'actions'],
                'children' => ['button'],
                'fields' => [['handle' => 'justify', 'type' => 'select', 'label' => 'Align', 'options' => ['start' => 'Start', 'center' => 'Center', 'end' => 'End'], 'default' => 'start', 'display' => 'segmented']],
                'template' => [['type' => 'button', 'props' => ['label' => 'Get Started', 'variant' => 'primary']], ['type' => 'button', 'props' => ['label' => 'Learn More', 'variant' => 'secondary']]],
                'render' => Elements::buttons(...),
            ],
            [
                'name' => 'tabs', 'title' => 'Tabs', 'icon' => 'tabs', 'category' => 'interactive',
                'description' => 'Panels behind tabs, with keyboard support. Without scripts every panel shows, one after the other.', 'keywords' => ['tabbed', 'panels', 'switch'],
                'children' => ['tab-panel'], 'defer' => true,
                'fields' => [
                    ['handle' => 'label', 'type' => 'text', 'label' => 'Name for Screen Readers', 'default' => 'Tabs'],
                    ['handle' => 'default', 'type' => 'number', 'label' => 'Open First', 'default' => 1, 'min' => 1],
                    ['handle' => 'look', 'type' => 'select', 'label' => 'Look', 'options' => ['lines' => 'Underlined', 'segmented' => 'Segmented', 'vertical' => 'Vertical'], 'default' => 'lines', 'display' => 'segmented'],
                ],
                'template' => [
                    ['type' => 'tab-panel', 'props' => ['label' => 'First'], 'children' => [['type' => 'paragraph', 'props' => ['text' => '']]]],
                    ['type' => 'tab-panel', 'props' => ['label' => 'Second'], 'children' => [['type' => 'paragraph', 'props' => ['text' => '']]]],
                ],
                'render' => Interactive::tabs(...),
            ],
            [
                'name' => 'tab-panel', 'title' => 'Tab', 'icon' => 'square', 'category' => 'interactive', 'parent' => 'tabs', 'children' => true,
                'description' => 'One panel of Tabs.',
                'fields' => [['handle' => 'label', 'type' => 'text', 'label' => 'Label', 'default' => 'Tab']],
                'render' => Interactive::tabPanel(...),
            ],
            [
                'name' => 'list-filter', 'title' => 'List Filter', 'icon' => 'funnel', 'category' => 'interactive',
                'description' => 'Lets visitors filter a Repeat inside it by search and choices. Without scripts the whole list shows. Set Filter By and Search In on the Repeat.', 'keywords' => ['facets', 'catalog', 'search', 'categories'],
                'children' => true, 'defer' => true,
                'fields' => [['handle' => 'status', 'type' => 'text', 'label' => 'Result Count for Screen Readers', 'default' => ':count of :total']],
                'template' => [
                    ['type' => 'filter-search', 'props' => []],
                    ['type' => 'repeat', 'props' => ['source' => 'entries', 'search_in' => 'item.title']],
                    ['type' => 'filter-empty', 'children' => [['type' => 'paragraph', 'props' => ['text' => 'Nothing matches.']]]],
                ],
                'render' => Interactive::listFilter(...),
            ],
            [
                'name' => 'filter-search', 'title' => 'Filter Search', 'icon' => 'search', 'category' => 'interactive', 'parent' => 'list-filter',
                'description' => 'A search field for a List Filter.',
                'fields' => [
                    ['handle' => 'label', 'type' => 'text', 'label' => 'Label', 'default' => 'Search'],
                    ['handle' => 'placeholder', 'type' => 'text', 'label' => 'Placeholder'],
                ],
                'render' => Interactive::filterSearch(...),
            ],
            [
                'name' => 'filter-choice', 'title' => 'Filter Choice', 'icon' => 'list', 'category' => 'interactive', 'parent' => 'list-filter',
                'description' => 'One choice for a List Filter. Its options and counts come from the items.',
                'fields' => [
                    ['handle' => 'facet', 'type' => 'text', 'label' => 'Field', 'required' => true, 'instructions' => 'One of the Repeat’s Filter By fields, e.g. category.'],
                    ['handle' => 'label', 'type' => 'text', 'label' => 'Label', 'default' => 'Category'],
                    ['handle' => 'all_label', 'type' => 'text', 'label' => 'Label for All', 'default' => 'All'],
                    ['handle' => 'look', 'type' => 'select', 'label' => 'Look', 'options' => ['chips' => 'Chips', 'list' => 'List'], 'default' => 'chips', 'display' => 'segmented'],
                ],
                'render' => Interactive::filterChoice(...),
            ],
            [
                'name' => 'filter-sort', 'title' => 'Filter Sort', 'icon' => 'sort-ascending', 'category' => 'interactive', 'parent' => 'list-filter',
                'description' => 'Lets visitors reorder a List Filter’s items. The first option is the order the Repeat already has.',
                'fields' => [
                    ['handle' => 'label', 'type' => 'text', 'label' => 'Label', 'default' => 'Sort'],
                    ['handle' => 'options', 'type' => 'repeater', 'label' => 'Options', 'fields' => [
                        ['handle' => 'label', 'type' => 'text', 'label' => 'Label'],
                        ['handle' => 'field', 'type' => 'text', 'label' => 'Field', 'instructions' => 'One of the Repeat’s Sort By fields, e.g. title. Empty: the Repeat’s own order.'],
                        ['handle' => 'direction', 'type' => 'select', 'label' => 'Direction', 'options' => ['asc' => 'Lowest First', 'desc' => 'Highest First'], 'default' => 'asc'],
                    ]],
                ],
                'render' => Interactive::filterSort(...),
            ],
            [
                'name' => 'filter-empty', 'title' => 'Filter Empty', 'icon' => 'info', 'category' => 'interactive', 'parent' => 'list-filter', 'children' => true,
                'description' => 'Shown when nothing matches the List Filter.',
                'render' => Interactive::filterEmpty(...),
            ],
            [
                'name' => 'motion-toggle', 'title' => 'Motion Toggle', 'icon' => 'pause', 'category' => 'interactive',
                'description' => 'Lets visitors pause what moves by itself. Needed on a page with an animation that runs forever.', 'keywords' => ['pause', 'animation', 'accessibility', 'reduced motion'],
                'html' => 'button',
                'fields' => [
                    ['handle' => 'pause_label', 'type' => 'text', 'label' => 'Pause Label', 'default' => 'Pause animations'],
                    ['handle' => 'play_label', 'type' => 'text', 'label' => 'Play Label', 'default' => 'Play animations'],
                ],
                'render' => Elements::motionToggle(...),
            ],
            [
                'name' => 'accordion', 'title' => 'Accordion', 'icon' => 'list-tree', 'category' => 'interactive',
                'description' => 'Expandable questions and answers (adds FAQ structured data).', 'keywords' => ['faq', 'collapse', 'details', 'questions'],
                'fields' => [
                    ['handle' => 'items', 'type' => 'repeater', 'label' => 'Items', 'fields' => [
                        ['handle' => 'question', 'type' => 'text', 'label' => 'Question', 'required' => true],
                        ['handle' => 'answer', 'type' => 'richtext', 'label' => 'Answer'],
                    ]],
                    ['handle' => 'faq', 'type' => 'toggle', 'label' => 'Mark Up as FAQ', 'default' => true, 'instructions' => 'Lets search engines and assistants quote the answers.'],
                    ['handle' => 'look', 'type' => 'select', 'label' => 'Look', 'options' => ['lines' => 'Lines', 'cards' => 'Cards'], 'default' => 'lines', 'display' => 'segmented'],
                ],
                'render' => Elements::accordion(...),
            ],
            [
                'name' => 'input', 'title' => 'Input', 'icon' => 'textbox', 'category' => 'interactive',
                'description' => 'A text field, checkbox, slider or dropdown for interactive pages: keep its value in a state variable (Interact → Input value) and use it in scripts.', 'keywords' => ['field', 'filter', 'range', 'slider', 'checkbox', 'select', 'search box'],
                'html' => 'input',
                'fields' => [
                    ['handle' => 'type', 'type' => 'select', 'label' => 'Type', 'options' => ['text' => 'Text', 'search' => 'Search', 'email' => 'Email', 'password' => 'Password', 'number' => 'Number', 'range' => 'Slider', 'checkbox' => 'Checkbox', 'select' => 'Dropdown', 'textarea' => 'Long text'], 'default' => 'text'],
                    ['handle' => 'label', 'type' => 'text', 'label' => 'Accessible Label', 'required' => true, 'default' => 'Search'],
                    ['handle' => 'name', 'type' => 'text', 'label' => 'Name', 'mono' => true, 'instructions' => 'Sent with a form.'],
                    ['handle' => 'placeholder', 'type' => 'text', 'label' => 'Placeholder'],
                    ['handle' => 'value', 'type' => 'text', 'label' => 'Initial Value'],
                    ['handle' => 'options', 'type' => 'tags', 'label' => 'Options', 'if' => ['type' => 'select']],
                    ['handle' => 'min', 'type' => 'number', 'label' => 'Minimum', 'if' => ['type' => ['number', 'range']]],
                    ['handle' => 'max', 'type' => 'number', 'label' => 'Maximum', 'if' => ['type' => ['number', 'range']]],
                    ['handle' => 'step', 'type' => 'number', 'label' => 'Step', 'if' => ['type' => ['number', 'range']]],
                ],
                'render' => Elements::input(...),
            ],
            [
                'name' => 'search', 'title' => 'Search', 'icon' => 'search', 'category' => 'interactive',
                'description' => 'A search button that opens instant search over every page (⌘K). Works on static sites.', 'keywords' => ['find', 'command', 'cmdk'],
                'html' => 'button',
                'fields' => [
                    ['handle' => 'label', 'type' => 'text', 'label' => 'Label', 'default' => 'Search'],
                    ['handle' => 'placeholder', 'type' => 'text', 'label' => 'Placeholder', 'default' => 'Search…'],
                    ['handle' => 'empty', 'type' => 'text', 'label' => 'No results text', 'default' => 'No results'],
                    ['handle' => 'shortcut', 'type' => 'toggle', 'label' => 'Open with ⌘K', 'default' => true],
                    ['handle' => 'compact', 'type' => 'select', 'label' => 'Icon Only', 'options' => ['never' => 'Never', 'mobile' => 'On phones', 'always' => 'Always'], 'default' => 'never'],
                ],
                'render' => Elements::search(...),
            ],

            // ── Data ───────────────────────────────────────────────
            [
                'name' => 'repeat', 'title' => 'Repeat', 'icon' => 'stack', 'category' => 'data',
                'description' => 'Repeats its children for every item of a list: entries of a collection, a menu, child pages, headings, breadcrumbs… Inside, bind blocks to item.title, item.url and so on.',
                'keywords' => ['collection list', 'loop', 'query', 'posts', 'menu', 'navigation', 'for each'],
                'children' => true, 'defer' => true,
                'fields' => [
                    ['handle' => 'source', 'type' => 'select', 'label' => 'List', 'options' => [], 'dynamic' => 'sources', 'default' => 'entries', 'required' => true],
                    $tags(Elements::BOX_TAGS, 'div'),
                    ['handle' => 'as', 'type' => 'text', 'label' => 'Item Name', 'default' => 'item', 'instructions' => 'Name the item for nested lists (e.g. post, link).'],
                    ['handle' => 'group_by', 'type' => 'text', 'label' => 'Group By', 'instructions' => 'A field of the items. Repeats once per group, with item.label and item.items.'],
                    ['handle' => 'filter_by', 'type' => 'text', 'label' => 'Filter By', 'placeholder' => 'item.category, item.tags', 'instructions' => 'In a List Filter: the fields its choices filter on.'],
                    ['handle' => 'search_in', 'type' => 'text', 'label' => 'Search In', 'placeholder' => 'item.title, item.summary', 'instructions' => 'In a List Filter: the fields its search looks through.'],
                    ['handle' => 'sort_by', 'type' => 'text', 'label' => 'Sort By', 'placeholder' => 'item.title, item.price_minor', 'instructions' => 'In a List Filter: the fields its Filter Sort can order by.'],
                    ['handle' => 'empty', 'type' => 'text', 'label' => 'When Empty', 'placeholder' => 'Show Nothing'],
                ],
                'render' => Elements::repeat(...),
                'example' => ['source' => 'entries', 'collection' => 'posts', 'limit' => 3, 'sort' => '-published_at'],
            ],
            [
                'name' => 'outlet', 'title' => 'Page Content', 'icon' => 'article', 'category' => 'data',
                'description' => 'In a template: where each page’s own blocks go.', 'keywords' => ['slot', 'content', 'body', 'main', 'template'],
                'fields' => [
                    ['handle' => 'wrap', 'type' => 'toggle', 'label' => 'Wrap in a Container', 'instructions' => 'Style the content area as one element.'],
                    ['handle' => 'permalinks', 'type' => 'toggle', 'label' => 'Add # Links to Headings'],
                ],
                'render' => Elements::outlet(...),
            ],
            [
                'name' => 'pattern', 'title' => 'Component', 'icon' => 'symbol', 'category' => 'data',
                'description' => 'An instance of a component (synced pattern) — edit the component once, every instance updates. Set its properties per instance.', 'keywords' => ['reusable', 'synced', 'symbol', 'component', 'header', 'footer'],
                'fields' => [
                    ['handle' => 'ref', 'type' => 'select', 'label' => 'Component', 'options' => [], 'dynamic' => 'patterns', 'required' => true],
                    ['handle' => 'values', 'type' => 'json', 'label' => 'Properties'],
                ],
            ],
            [
                'name' => 'html', 'title' => 'Custom HTML', 'icon' => 'code', 'category' => 'embed',
                'description' => 'Raw HTML for embed codes. Only people with the “Custom HTML” permission can add it.', 'keywords' => ['embed', 'snippet', 'script'],
                'fields' => [['handle' => 'html', 'type' => 'code', 'label' => 'HTML', 'language' => 'html']],
                'placeholders' => ['html' => __('Custom HTML')],
                'render' => static fn (array $d) => '<div '.$d['attrs'].Elements::placeholder($d, 'html').'>'.($d['block']['html'] ?? '').'</div>',
            ],
        ];
    }
}
