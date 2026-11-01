<?php

declare(strict_types=1);

namespace Cms\Seo\Tests;

use Cms\Abilities\AbilityContext;
use Cms\Tests\TestCase;

final class SeoHealthTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/seo'];
    }

    private function ability(string $name, array $input = []): mixed
    {
        return $this->cms()->abilities()->execute($name, $input, AbilityContext::system());
    }

    public function test_health_sums_up_published_pages_and_follows_saves(): void
    {
        $this->assertSame(0, $this->ability('seo/health')['pages']);

        $page = $this->ability('entries/create', ['collection' => 'pages', 'title' => 'About', 'status' => 'published']);
        $this->ability('entries/create', ['collection' => 'pages', 'title' => 'Hidden', 'status' => 'published', 'data' => ['seo' => ['noindex' => true]]]);
        $this->ability('entries/create', ['collection' => 'pages', 'title' => 'Draft', 'status' => 'draft']);

        $health = $this->ability('seo/health', ['limit' => 3]);

        // Only the published, indexable page counts; a near-empty page needs attention.
        $this->assertSame(1, $health['pages']);
        $this->assertSame(1, $health['attention']);
        $this->assertSame($page['id'], $health['worst'][0]['id']);
        $this->assertSame('Enough substance', $health['worst'][0]['top']);
        $this->assertContains('length', array_column($health['issues'], 'id'));
        $this->assertGreaterThan(0, $health['issues'][0]['failing']);
    }
}
