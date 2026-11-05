<?php

declare(strict_types=1);

namespace Cms\Seo\Tests;

use Cms\Content\Entries\Entry;
use Cms\Content\Entries\EntryService;
use Cms\Modules\Site\Build\SiteBuilder;
use Cms\Modules\Site\Rendering\Renderer;
use Cms\Settings\SettingsRegistry;
use Cms\Tests\TestCase;

final class HeadTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/seo'];
    }

    private function publish(): Entry
    {
        return app(EntryService::class)->create('posts', ['title' => 'First', 'status' => 'published', 'at' => '2026-10-01T10:00:00+00:00', 'data' => ['excerpt' => 'About it']], $this->admin());
    }

    public function test_a_page_says_robots_once_and_a_preview_leaves_it_to_the_core(): void
    {
        $entry = $this->publish();
        $robots = static fn (string $html) => preg_match_all('#<meta name="?robots"? content="?([^">]+)"?>#', $html, $m) ? $m[1] : [];

        $this->assertSame(['index, follow, max-image-preview:large'], $robots(app(Renderer::class)->entry($entry)['html']));
        $this->assertSame(['noindex, nofollow'], $robots(app(Renderer::class)->entry($entry, preview: true)['html']));
    }

    public function test_changing_a_setting_builds_the_site_again(): void
    {
        $this->publish();
        $builder = app(SiteBuilder::class);
        $builder->buildAll();
        $this->assertFalse($builder->hasWork());

        app(SettingsRegistry::class)->save('seo', ['title_format' => '{title} | {site}']);

        $this->assertTrue($builder->hasWork());
    }
}
