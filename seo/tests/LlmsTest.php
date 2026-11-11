<?php

declare(strict_types=1);

namespace Cms\Seo\Tests;

use Cms\Abilities\AbilityContext;
use Cms\Tests\TestCase;

/** The site writes llms.txt; SEO makes it richer through `site.llms` and writes no file of its own. */
final class LlmsTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks', 'letterncms/site', 'letterncms/seo'];
    }

    private function page(string $title, array $data = []): array
    {
        return $this->cms()->abilities()->execute('entries/create', ['collection' => 'pages', 'title' => $title, 'data' => $data, 'status' => 'published'], AbilityContext::system('test'));
    }

    public function test_the_map_has_seos_summary_descriptions_and_markdown_twins_and_leaves_out_what_search_may_not_list(): void
    {
        $this->cms()->settingsRegistry()->save('seo', ['llms_summary' => 'We restore wooden boats.']);
        $this->page('About', ['seo' => ['description' => 'Who we are.']]);
        $this->page('Hidden', ['seo' => ['noindex' => true]]);

        $text = $this->get('/llms.txt')->assertOk()->getContent();

        $this->assertStringStartsWith("# Test site\n\n> We restore wooden boats.\n\n## Pages\n\n", $text);
        $this->assertStringContainsString('- [About](http://localhost/about.md): Who we are.', $text);
        $this->assertStringNotContainsString('Hidden', $text);

        // One file in the built site, the same as the route answers with.
        $extras = $this->cms()->hooks()->filter('site.build.extras', []);
        $this->assertSame(['llms.txt'], array_values(array_filter(array_column($extras, 'path'), static fn (string $path) => str_contains($path, 'llms'))));
        $this->assertSame($text, $extras['llms']['render']());
    }

    public function test_without_a_summary_of_its_own_the_sites_description_stays(): void
    {
        $this->cms()->settingsRegistry()->save('site', [...$this->cms()->setting('site'), 'description' => 'A boatyard in Ghent.']);
        $this->page('About');

        $this->assertStringContainsString("> A boatyard in Ghent.\n", $this->get('/llms.txt')->getContent());
    }
}
