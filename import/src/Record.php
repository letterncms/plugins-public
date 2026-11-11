<?php

declare(strict_types=1);

namespace Cms\Import;

/** One thing to import as an entry, as its source describes it: the same shape from WordPress, a CSV row or a Markdown file. */
final class Record
{
    /**
     * @param  string  $key  What it is in its source, the same on every run: `wordpress:example.com:42`, `markdown:notes:guides/start.md`.
     * @param  array<string, mixed>  $data  Field handle => value, as the source has it.
     * @param  string|null  $body  The main text, as Markdown.
     * @param  list<string>  $redirectFrom  Paths it used to be at.
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $key,
        public string $collection,
        public string $title,
        public ?string $slug = null,
        public string $status = 'draft',
        public ?string $at = null,
        public array $data = [],
        public ?string $body = null,
        public array $redirectFrom = [],
        public ?string $parentKey = null,
        public ?string $image = null,
        public array $warnings = [],
    ) {}
}
