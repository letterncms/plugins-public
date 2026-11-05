<?php

declare(strict_types=1);

namespace Cms\Analytics\Tests;

use Carbon\CarbonImmutable;
use Cms\Abilities\AbilityContext;
use Cms\Support\Yaml;
use Cms\Tests\TestCase;
use Illuminate\Support\Facades\DB;

final class LanguagesTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/analytics', 'letterncms/languages'];
    }

    protected function prepareSite(string $site): void
    {
        Yaml::write($site.'/config/site.yaml', ['name' => 'Test site', 'url' => 'http://localhost', 'locale' => 'en', 'locales' => ['en', 'pt-BR']]);
    }

    public function test_views_count_for_the_language_whose_prefix_the_path_has(): void
    {
        $day = CarbonImmutable::now((string) config('app.timezone'))->toDateString();

        foreach (['/pt-br/precos', '/pt-br', '/pt-br/sobre', '/', '/pt-brasil'] as $path) {
            DB::table('analytics_pages')->insert(['day' => $day, 'path' => $path, 'views' => 1, 'visitors' => 0]);
        }

        $languages = $this->cms()->abilities()->execute('analytics/breakdown', ['period' => '7d', 'dimension' => 'languages'], AbilityContext::forUser($this->admin(), 'admin'));

        $this->assertSame(['pt-BR', 'en'], array_column($languages['items'], 'key'));
        $this->assertSame([3, 2], array_column($languages['items'], 'views'));
        $this->assertStringStartsWith('Portugu', $languages['items'][0]['label']);
    }
}
