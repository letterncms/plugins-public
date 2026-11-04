<?php

declare(strict_types=1);

namespace Cms\Seo;

use Cms\Content\Entries\Entry;

/**
 * On-page checks for search and generative engines. Each check is pass/warn/fail
 * with a one-line, actionable message.
 */
final class Analyzer
{
    public function __construct(private readonly Meta $meta) {}

    /** @return array{score: int, checks: list<array{id: string, status: string, label: string, message: string}>} */
    public function analyze(Entry $entry): array
    {
        $entry = $entry->withWorkingCopy();
        $m = $this->meta->forEntry($entry);
        $seo = (array) ($entry->data['seo'] ?? []);
        $stats = $this->walk($entry->data);
        $checks = [];

        $titleLength = mb_strlen(trim((string) ($seo['title'] ?? '')) ?: $entry->title);
        $checks[] = $this->check('title', $titleLength >= 15 && $titleLength <= 60 ? 'pass' : 'warn', match (true) {
            $titleLength < 15 => __('Only :count characters. Add specifics people search for.', ['count' => $titleLength]),
            $titleLength > 60 => __(':count characters. It may be cut off in results (aim for 60 at most).', ['count' => $titleLength]),
            default => __(':count characters.', ['count' => $titleLength]),
        });

        $descLength = mb_strlen((string) ($seo['description'] ?? ''));
        $checks[] = $this->check('description', $descLength >= 70 && $descLength <= 160 ? 'pass' : 'warn', $descLength === 0
            ? __('Not set: a summary is generated from the content. Write one to control the snippet.')
            : __(':count characters (aim for 70–160).', ['count' => $descLength]));

        $checks[] = $this->check('h1', $stats['h1'] <= 1 ? 'pass' : 'warn', $stats['h1'] <= 1
            ? __('The title is the only H1.')
            : __(':count H1 headings in the content. Use H2 for sections.', ['count' => $stats['h1']]));

        $checks[] = $this->check('headings', $stats['words'] < 300 || $stats['h2'] > 0 ? 'pass' : 'warn', match (true) {
            $stats['h2'] === 1 => __('1 section heading helps readers and AI find answers.'),
            $stats['h2'] > 1 => __(':count section headings help readers and AI find answers.', ['count' => $stats['h2']]),
            default => __('Add H2 headings to break up long text.'),
        });

        $checks[] = $this->check('alt', $stats['images_missing_alt'] === 0 ? 'pass' : 'fail', match (true) {
            $stats['images_missing_alt'] > 0 => __(':missing of :count images have no alt text.', ['missing' => $stats['images_missing_alt'], 'count' => $stats['images']]),
            $stats['images'] === 0 => __('No images.'),
            $stats['images'] === 1 => __('The image is described.'),
            default => __('All :count images are described.', ['count' => $stats['images']]),
        });

        $checks[] = $this->check('length', $stats['words'] >= 300 ? 'pass' : ($stats['words'] >= 120 ? 'warn' : 'fail'), $stats['words'] < 300
            ? __(':count words. Thin pages rarely rank or get cited.', ['count' => $stats['words']])
            : __(':count words.', ['count' => $stats['words']]));

        $first = $this->meta->firstParagraph($entry->data);
        $firstWords = self::words($first);
        $checks[] = $this->check('answer-first', $first !== '' && $firstWords <= 60 ? 'pass' : 'warn', match (true) {
            $first === '' => __('Start with a short paragraph that answers the main question.'),
            $firstWords <= 60 => __('The opening paragraph is short and quotable.'),
            default => __('The opening paragraph has :count words. AI answers quote short, direct openings (60 words at most).', ['count' => $firstWords]),
        });

        $checks[] = $this->check('questions', $stats['questions'] > 0 || $stats['faq'] ? 'pass' : 'warn', $stats['questions'] > 0 || $stats['faq']
            ? __('Questions as headings (or an FAQ) match how people ask AI assistants.')
            : __('Phrase a few headings as the questions people ask, or add an FAQ.'));

        $checks[] = $this->check('links', $stats['internal_links'] > 0 ? 'pass' : 'warn', match (true) {
            $stats['internal_links'] === 1 => __('1 link to another page.'),
            $stats['internal_links'] > 1 => __(':count links to other pages.', ['count' => $stats['internal_links']]),
            default => __('Link to related pages so readers and crawlers find more.'),
        });

        $checks[] = $this->check('indexable', empty($seo['noindex']) ? 'pass' : 'warn', empty($seo['noindex'])
            ? __('Search engines may index this page.')
            : __('Marked noindex: it will not appear in search.'));

        $checks[] = $this->check('image', $m['image'] ? 'pass' : 'warn', $m['image']
            ? __('Shared links show an image.')
            : __('Add a social image so links look good when shared.'));

        $points = ['pass' => 1, 'warn' => 0.5, 'fail' => 0];
        $score = (int) round(array_sum(array_map(static fn ($c) => $points[$c['status']], $checks)) / count($checks) * 100);

        return ['score' => $score, 'checks' => $checks, 'meta' => $m];
    }

    /** The name of a check, in the reader's language. */
    public static function label(string $id): string
    {
        return match ($id) {
            'title' => __('Title Length'),
            'description' => __('Meta Description'),
            'h1' => __('One Main Heading'),
            'headings' => __('Clear Structure'),
            'alt' => __('Image Alt Text'),
            'length' => __('Enough Substance'),
            'answer-first' => __('Answer First'),
            'questions' => __('Question Headings'),
            'links' => __('Internal Links'),
            'indexable' => __('Indexable'),
            'image' => __('Social Image'),
            default => $id,
        };
    }

    private function check(string $id, string $status, string $message): array
    {
        return ['id' => $id, 'status' => $status, 'label' => self::label($id), 'message' => $message];
    }

    private function walk(array $data): array
    {
        $stats = ['words' => 0, 'h1' => 0, 'h2' => 0, 'questions' => 0, 'images' => 0, 'images_missing_alt' => 0, 'internal_links' => 0, 'faq' => false];
        $site = $this->meta->siteUrl();

        $walk = function ($value) use (&$walk, &$stats, $site): void {
            if (is_string($value)) {
                preg_match_all('#href="(/[^"]*|'.preg_quote($site, '#').'[^"]*)"#', $value, $m);
                $stats['internal_links'] += count($m[0]);

                return;
            }

            if (! is_array($value)) {
                return;
            }

            $type = $value['type'] ?? null;
            $props = (array) ($value['props'] ?? []);

            if ($type === 'heading') {
                $level = (int) ($props['level'] ?? 2);
                $level === 1 ? $stats['h1']++ : ($level === 2 ? $stats['h2']++ : null);

                if (str_ends_with(trim(strip_tags((string) ($props['text'] ?? ''))), '?')) {
                    $stats['questions']++;
                }
            }

            if ($type === 'image') {
                $stats['images']++;

                if (trim((string) ($props['alt'] ?? '')) === '') {
                    $stats['images_missing_alt']++;
                }
            }

            if ($type === 'accordion' && ! empty($props['faq'])) {
                $stats['faq'] = true;
            }

            if ($type === 'button' && isset($props['url']) && str_starts_with((string) $props['url'], '/')) {
                $stats['internal_links']++;
            }

            foreach (['text', 'cite', 'items', 'label'] as $key) {
                foreach ((array) ($props[$key] ?? []) as $text) {
                    if (is_string($text)) {
                        $stats['words'] += self::words(strip_tags($text));
                    }
                }
            }

            if (is_string($value['body'] ?? null)) {
                $stats['words'] += self::words(strip_tags($value['body']));
            }

            foreach ($value as $key => $child) {
                if ($key !== 'props' || $type === null) {
                    $walk($child);
                } else {
                    foreach ($child as $c) {
                        $walk($c);
                    }
                }
            }
        };

        $walk($data);

        // Markdown bodies.
        foreach ($data as $value) {
            if (is_string($value) && strlen($value) > 200) {
                $stats['words'] += self::words(strip_tags($value));
                $stats['h2'] += preg_match_all('/^## /m', $value);
                $stats['questions'] += preg_match_all('/^#{2,3} .*\?\s*$/m', $value);
            }
        }

        return $stats;
    }

    /** Words in any language: str_word_count() splits at ü and é. */
    private static function words(string $text): int
    {
        return (int) preg_match_all("/[\\p{L}\\p{N}]+(?:['’-][\\p{L}\\p{N}]+)*/u", $text);
    }
}
