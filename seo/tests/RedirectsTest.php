<?php

declare(strict_types=1);

namespace Cms\Seo\Tests;

use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Tests\TestCase;

final class RedirectsTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/seo'];
    }

    private function run_(string $ability, array $input = []): mixed
    {
        return $this->cms()->abilities()->execute($ability, $input, AbilityContext::forUser($this->admin(), 'admin'));
    }

    private function tab(): array
    {
        return $this->redirects()['children'][0];
    }

    /** The Redirects tab of Settings › SEO. */
    private function redirects(): array
    {
        $tabs = $this->run_('views/get', ['id' => 'settings', 'params' => ['namespace' => 'seo']])['root']['children'][0]['children'];

        return array_values(array_filter($tabs, static fn (array $tab) => $tab['props']['id'] === 'redirects'))[0];
    }

    public function test_new_redirect_is_in_the_bar_once_and_the_tab_is_empty_and_then_the_table(): void
    {
        // The one way to make a redirect is the tab's action in the bar, with or without redirects.
        $action = $this->redirects()['props']['actions'][0];
        $this->assertSame(['New Redirect', 'redirects/create', 'Create Redirect'], [$action['label'], $action['ability'], $action['form']['confirm']]);

        // With none yet: a title, and no second button.
        $empty = $this->tab();
        $this->assertSame(['ui/empty', 'No redirects yet'], [$empty['type'], $empty['props']['title']]);
        $this->assertArrayNotHasKey('action', $empty['props']);

        $made = $this->run_('redirects/create', ['from' => 'old-page/', 'to' => '/new-page']);
        $this->assertSame(['/old-page', '/new-page', 301], [$made['from'], $made['to'], $made['status']]);
        $this->assertSame('/new-page', $this->app->make(\Cms\Seo\Redirects::class)->match('/old-page/')['to']);

        $table = $this->tab();
        $this->assertSame(['ui/form', 'redirects/list', 'redirects/save'], [$table['type'], $table['props']['source']['ability'], $table['props']['save']['ability']]);
        $this->assertSame(['/old-page'], array_column($this->run_('redirects/list'), 'from'));
        $this->assertSame('redirects/create', $this->redirects()['props']['actions'][0]['ability']);
    }

    public function test_an_address_redirects_once_and_only_to_somewhere(): void
    {
        $this->run_('redirects/create', ['from' => '/old', 'to' => 'https://example.com/new', 'status' => 302]);

        foreach ([['from' => '/old', 'to' => '/elsewhere'], ['from' => '/other', 'to' => 'nowhere']] as $input) {
            try {
                $this->run_('redirects/create', $input);
                $this->fail('A redirect that should be refused was made.');
            } catch (AbilityException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([['/old', 'https://example.com/new', 302]], array_map(static fn (array $r) => [$r['from'], $r['to'], $r['status']], $this->run_('redirects/list')));
    }
}
