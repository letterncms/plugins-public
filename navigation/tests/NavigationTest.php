<?php

declare(strict_types=1);

namespace Cms\Navigation\Tests;

use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Navigation\MenuResolver;
use Cms\Tests\TestCase;

final class NavigationTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/navigation'];
    }

    private function ability(string $ability, array $input = []): mixed
    {
        return $this->cms()->abilities()->execute($ability, $input, AbilityContext::system());
    }

    private function page(string $title, bool $live = true, ?string $parent = null): array
    {
        return $this->ability('entries/create', array_filter(['collection' => 'pages', 'title' => $title, 'status' => $live ? 'published' : 'draft', 'parentId' => $parent]));
    }

    public function test_menus_link_entries_and_urls_and_render_live_items_only(): void
    {
        $about = $this->page('About');
        $team = $this->page('Team', parent: $about['id']);
        $draft = $this->page('Secret', live: false);

        $saved = $this->ability('navigation/save', ['handle' => 'main', 'title' => 'Main', 'items' => [
            ['entry' => $about['id'], 'children' => [['entry' => $team['id'], 'label' => 'Our team']]],
            ['entry' => $draft['id']],
            ['url' => 'https://example.com/docs', 'label' => 'Docs', 'newTab' => true],
        ]]);

        $this->assertSame('About', $saved['items'][0]['target']['title']);
        $this->assertSame('draft', $saved['items'][1]['target']['status']);
        $this->assertFileExists(content_path('navigation/main.yaml'));
        $this->assertStringContainsString('new_tab: true', (string) file_get_contents(content_path('navigation/main.yaml')));

        $links = cms_menu('main');
        $this->assertSame(['About', 'Docs'], array_column($links, 'label'), 'draft entries stay hidden');
        $this->assertSame('/about', $links[0]['url']);
        $this->assertSame('Our team', $links[0]['children'][0]['label']);
        $this->assertSame('/about/team', $links[0]['children'][0]['url']);
        $this->assertTrue($links[1]['newTab']);

        $marked = $this->app->make(MenuResolver::class)->resolve('main', '/about/team/');
        $this->assertTrue($marked[0]['active']);
        $this->assertFalse($marked[0]['current']);
        $this->assertTrue($marked[0]['children'][0]['current']);
        $this->assertFalse($marked[1]['active']);

        $this->assertNull(cms_menu('missing'));
    }

    public function test_ids_are_kept_so_edits_are_stable(): void
    {
        $saved = $this->ability('navigation/save', ['handle' => 'footer', 'items' => [['url' => '/contact', 'label' => 'Contact']]]);
        $id = $saved['items'][0]['id'];

        $again = $this->ability('navigation/save', ['handle' => 'footer', 'items' => [['id' => $id, 'url' => '/contact', 'label' => 'Get in touch'], ['url' => '#top', 'label' => 'Top']]]);

        $this->assertSame($id, $again['items'][0]['id']);
        $this->assertSame('Footer', $again['title']);
        $this->assertSame(2, $this->ability('navigation/list')[0]['count']);
    }

    /** @return iterable<string, array{array, string}> */
    public static function invalid(): iterable
    {
        yield 'both targets' => [[['url' => '/a', 'entry' => 'x', 'label' => 'A']], 'either'];
        yield 'no target' => [[['label' => 'A']], 'either'];
        yield 'script url' => [[['url' => 'javascript:alert(1)', 'label' => 'A']], 'not a link'];
        yield 'protocol-relative' => [[['url' => '//evil.example', 'label' => 'A']], 'not a link'];
        yield 'url without label' => [[['url' => '/a']], 'label'];
        yield 'too deep' => [[['url' => '/a', 'label' => 'A', 'children' => [['url' => '/b', 'label' => 'B', 'children' => [['url' => '/c', 'label' => 'C']]]]]], 'levels'];
        yield 'unknown entry' => [[['entry' => '01aaaaaaaaaaaaaaaaaaaaaaaa']], 'do not exist'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function test_rejects_invalid_items(array $items, string $message): void
    {
        try {
            $this->ability('navigation/save', ['handle' => 'main', 'maxDepth' => 2, 'items' => $items]);
            $this->fail('Expected the menu to be rejected.');
        } catch (AbilityException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_deleting_an_entry_removes_its_items_and_lifts_children(): void
    {
        $about = $this->page('About');
        $this->ability('navigation/save', ['handle' => 'main', 'items' => [
            ['entry' => $about['id'], 'children' => [['url' => '/jobs', 'label' => 'Jobs']]],
            ['url' => '/blog', 'label' => 'Blog'],
        ]]);

        $this->ability('entries/delete', ['id' => $about['id']]);

        $this->assertSame(['Jobs', 'Blog'], array_column($this->ability('navigation/get', ['handle' => 'main'])['items'], 'label'));
    }

    public function test_permissions(): void
    {
        $this->actingAs($this->userWithRole('author'));
        $this->postJson('/api/admin/abilities/navigation/save', ['handle' => 'main', 'items' => []])->assertForbidden();

        $this->actingAs($this->userWithRole('editor'));
        $this->postJson('/api/admin/abilities/navigation/save', ['handle' => 'main', 'items' => []])->assertOk();
        $this->postJson('/api/admin/abilities/navigation/delete', ['handle' => 'main'])->assertOk()->assertJsonPath('data.deleted', true);
    }

    /** @return array the view as the admin draws it for this person */
    private function place(string $id, array $params = []): array
    {
        return $this->cms()->abilities()->execute('views/get', ['id' => $id, 'params' => $params], AbilityContext::forUser($this->admin(), 'admin'))['root'];
    }

    public function test_menus_are_views_and_made_renamed_and_deleted_from_them(): void
    {
        $this->assertSame('ui/records', $this->place('navigation')['children'][0]['type']);
        $this->assertSame('ui/empty', $this->place('menu', ['handle' => 'gone'])['children'][0]['type']);

        $menu = $this->ability('navigation/create', ['title' => 'Footer Links']);
        $this->assertSame('footer-links', $menu['handle']);
        $this->ability('navigation/save', ['handle' => 'footer-links', 'items' => [['url' => '/contact', 'label' => 'Contact']]]);
        $this->ability('navigation/save', ['handle' => 'footer-links', 'title' => 'Footer']);

        $saved = $this->ability('navigation/get', ['handle' => 'footer-links']);
        $this->assertSame('Footer', $saved['title']);
        $this->assertCount(1, $saved['items']);
        $this->assertSame('navigation/menu', $this->place('menu', ['handle' => 'footer-links'])['children'][0]['type']);
        $this->assertSame('1 link', $this->ability('navigation/list')[0]['links']);

        // Said on the name in the New Menu dialog.
        try {
            $this->ability('navigation/create', ['title' => 'Footer links']);
            $this->fail('A second footer-links menu was made.');
        } catch (AbilityException $e) {
            $this->assertSame(['conflict', 'title'], [$e->type, array_key_first($e->details)]);
        }
    }
}
