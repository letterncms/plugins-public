<?php

declare(strict_types=1);

namespace Cms\Site\Rendering;

use Cms\Content\Entries\Entry;

/**
 * State of the page being rendered: what it depends on (for incremental builds),
 * pagination, extra head/foot markup and CSS collected from blocks.
 *
 * The mode says who the output is for. Only `request` is for the person asking:
 * `build` goes to a public file, so nothing about a person may end up in it.
 */
final class PageContext
{
    public const string BUILD = 'build';

    public const string REQUEST = 'request';

    public const string PREVIEW = 'preview';

    public const string EDITOR = 'editor';

    private static ?self $current = null;

    private static bool $building = false;

    public readonly string $mode;

    /** @var array<string, true> e.g. "collection:posts", "entry:01…", "globals" */
    public array $dependencies = [];

    /** @var array<string, string> key => markup */
    public array $head = [];

    /** @var array<string, string> */
    public array $foot = [];

    /** @var array<string, array{0: int, 1: string}> rule key => [order, CSS] */
    public array $styles = [];

    public int $pages = 1;

    /** @var array{current?: int, total?: int, prev?: ?string, next?: ?string, count?: int} set by a paginated Repeat */
    public array $pagination = [];

    public function __construct(
        public readonly ?Entry $entry,
        public readonly int $page = 1,
        public readonly bool $preview = false,
        public readonly bool $editor = false,
        public readonly string $uri = '/',
        private readonly ?string $language = null,
    ) {
        $this->mode = match (true) {
            $editor => self::EDITOR,
            $preview => self::PREVIEW,
            self::$building => self::BUILD,
            default => self::REQUEST,
        };
    }

    /** Pages rendered inside $callback are for a public file. */
    public static function building(callable $callback): mixed
    {
        $previous = self::$building;
        self::$building = true;

        try {
            return $callback();
        } finally {
            self::$building = $previous;
        }
    }

    /** The language of this page: its entry's, or for a page without one (404) what its URL says. */
    public function locale(): string
    {
        return $this->entry?->locale ?? $this->language ?? cms()->defaultLocale();
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    /** Run $callback with this context active (contexts nest for partial renders). */
    public function run(callable $callback): mixed
    {
        $previous = self::$current;
        self::$current = $this;

        try {
            return $callback($this);
        } finally {
            self::$current = $previous;
        }
    }

    public static function depend(string $key): void
    {
        if (self::$current) {
            self::$current->dependencies[$key] = true;
        }
    }

    public function addHead(string $key, string $html): void
    {
        $this->head[$key] = $html;
    }

    public function addFoot(string $key, string $html): void
    {
        $this->foot[$key] = $html;
    }

    public function addStyle(string $key, string $css, int $order = 0): void
    {
        $this->styles[$key] = [$order, $css];
    }

    /** Collected CSS, base rules first and breakpoints/states after so they win. */
    public function css(): string
    {
        $rules = $this->styles;
        uasort($rules, static fn ($a, $b) => $a[0] <=> $b[0]);

        return implode('', array_column($rules, 1));
    }
}
