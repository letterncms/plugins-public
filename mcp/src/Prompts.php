<?php

declare(strict_types=1);

namespace Cms\Mcp;

use Cms\Cms;

/** Ready-made prompts that teach agents the CMS way of working. Plugins can add more (`mcp.prompts` filter). */
final class Prompts
{
    public function __construct(private readonly Cms $cms) {}

    /** @return list<array> */
    public function list(): array
    {
        return array_values(array_map(static fn ($p) => array_diff_key($p, ['template' => 1]), $this->all()));
    }

    public function get(string $name, array $arguments): array
    {
        $prompt = $this->all()[$name] ?? throw new RpcError(-32602, "Unknown prompt {$name}.");

        foreach ($prompt['arguments'] as $arg) {
            if (($arg['required'] ?? false) && trim((string) ($arguments[$arg['name']] ?? '')) === '') {
                throw new RpcError(-32602, "Missing argument {$arg['name']}.");
            }
        }

        $text = preg_replace_callback('/\{(\w+)\}/', static fn ($m) => (string) ($arguments[$m[1]] ?? ''), $prompt['template']) ?? '';

        return ['description' => $prompt['description'], 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => trim($text)]]]];
    }

    /** @return array<string, array{name: string, title: string, description: string, arguments: list<array>, template: string}> */
    private function all(): array
    {
        $prompts = [
            'build-page' => [
                'name' => 'build-page',
                'title' => 'Build a Page',
                'description' => 'Design and publish a complete page from a brief, using the theme tokens, blocks and patterns.',
                'arguments' => [
                    ['name' => 'brief', 'description' => 'What the page is for and what it should say', 'required' => true],
                    ['name' => 'collection', 'description' => 'Collection to create it in (default: pages)'],
                ],
                'template' => <<<'TXT'
                Build a page for this brief: {brief}

                1. Read cms://site/design, cms://blocks and cms://collections. Check patterns/list for components and sections, templates/list for page templates.
                2. Plan the sections (hero, proof, features, FAQ, call to action…) before writing. Lead with the answer; keep paragraphs short.
                3. Create the entry with entries.create in collection "{collection}" (pages if empty), body as blocks: top-level `section` blocks, styles only with tokens.
                4. Give every image alt text; write an SEO description (seo.description) of 70–160 characters.
                5. Run seo.analyze on the new entry and fix warnings, then publish with entries.publish and report the URL.
                TXT,
            ],
            'write-post' => [
                'name' => 'write-post',
                'title' => 'Write a Blog Post',
                'description' => 'Draft a well-structured, citable article as a draft for review.',
                'arguments' => [
                    ['name' => 'topic', 'description' => 'Topic or working title', 'required' => true],
                    ['name' => 'audience', 'description' => 'Who it is for'],
                ],
                'template' => <<<'TXT'
                Write a blog post about: {topic}
                Audience: {audience}

                Open with a 2–3 sentence answer to the main question. Use question-style H2 headings, short paragraphs, one comparison table if useful, and an FAQ accordion with 3–5 questions at the end.
                Find the posts collection with collections.list, then create a draft with entries.create (do not publish). Fill excerpt and SEO fields. Reply with a summary and the draft's id.
                TXT,
            ],
            'audit-site' => [
                'name' => 'audit-site',
                'title' => 'Audit the Site',
                'description' => 'Check every published page for SEO, GEO and accessibility problems and propose fixes.',
                'arguments' => [],
                'template' => <<<'TXT'
                Audit this site. For each collection in collections.list, list published entries (entries.list with status "live") and run seo.analyze on each.
                Summarize the biggest problems first (missing alt text, thin pages, missing descriptions, no question headings), then propose concrete fixes. Do not change anything until I confirm.
                TXT,
            ],
        ];

        return $this->cms->hooks()->filter('mcp.prompts', $prompts);
    }
}
