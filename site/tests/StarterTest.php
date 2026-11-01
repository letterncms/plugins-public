<?php

declare(strict_types=1);

namespace Cms\Site\Tests;

use Cms\Blocks\Document;
use Cms\Content\Collections\Collection;
use Cms\Site\Build\SiteBuilder;
use Cms\Site\Starter;
use Cms\Support\Yaml;
use Cms\Tests\TestCase;
use Cms\Users\User;

final class StarterTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks', 'letterncms/site', 'letterncms/navigation', 'letterncms/seo'];
    }

    protected function collections(): array
    {
        return [];
    }

    public function test_new_sites_include_a_working_block_blog_and_narrow_article_template(): void
    {
        $admin = $this->admin();
        $this->assertSame(6, app(Starter::class)->seed($admin));
        $posts = $this->cms()->collections()->findOrFail('posts');
        $this->assertSame('Blog', $posts->title);
        $sidebar = array_column($this->cms()->navigation()->forUser($admin)['sidebar']['sections'], null, 'id');
        $this->assertSame(['collection.pages', 'collection.posts'], array_slice($sidebar['content']['items'], 0, 2));
        $this->assertSame('content', $posts->body);
        $this->assertSame('blocks', $posts->blueprint()->field('content')->type);
        $this->assertNull($posts->blueprint()->field('excerpt'));
        $this->assertArrayNotHasKey('editor', $posts->toYaml());
        $entries = $this->cms()->entries()->query()->collection('posts')->get();
        $this->assertCount(3, $entries);
        foreach ($entries as $entry) {
            $this->assertSame($admin->id, $entry->authorId);
            $this->assertIsArray($entry->data['content']);
            $this->assertNotEmpty($entry->data['content']);
            $this->assertNotEmpty(app(Document::class)->text($entry->data['content']));
        }
        $article = $this->cms()->entries()->query()->collection('templates')->where('slug', 'post')->first();
        $main = collect($article->data['blocks'])->firstWhere('id', 'p-main');
        $this->assertSame('token:container.prose', $main['style']['base']['maxWidth']);
        $this->assertSame([], app(SiteBuilder::class)->buildAll()['errors']);
        $this->assertFileExists(public_path('blog/index.html'));
        $this->assertFileExists(public_path('blog/hello-world/index.html'));
        $this->assertStringContainsString('Welcome to your blog', file_get_contents(public_path('blog/index.html')));
        $this->assertStringContainsString('Notes, stories and useful ideas from this site.', file_get_contents(public_path('blog/index.html')));
        $this->assertStringContainsString('same blocks as pages', file_get_contents(public_path('blog/hello-world/index.html')));
        $this->assertSame(0, app(Starter::class)->seed($admin));
    }

    public function test_explicit_refresh_keeps_accounts_settings_and_nonkit_content(): void
    {
        $admin = $this->admin();
        app(Starter::class)->seed($admin);
        $password = $admin->getRawOriginal('password');
        $siteSettings = Yaml::read(site_path('config/site.yaml'));
        $this->cms()->collections()->save(Collection::fromArray('private_notes', ['title' => 'Notes', 'fields' => []]));
        $note = $this->cms()->content()->create('private_notes', ['title' => 'Keep this'], $admin);
        $oldPost = $this->cms()->entries()->query()->collection('posts')->first();
        $this->cms()->content()->update($oldPost->id, ['title' => 'Unpublished experiment'], $admin);

        $this->assertSame(6, app(Starter::class)->refresh($admin));
        $this->assertSame(1, User::query()->count());
        $this->assertSame($password, $admin->fresh()->getRawOriginal('password'));
        $this->assertSame($siteSettings, Yaml::read(site_path('config/site.yaml')));
        $this->assertNotNull($this->cms()->entries()->find($note->id));
        $this->assertNull($this->cms()->entries()->find($oldPost->id));
        $this->assertSame(3, $this->cms()->entries()->query()->collection('posts')->count());
        $menu = Yaml::read(content_path('navigation/main.yaml'));
        $this->assertCount(2, $menu['items']);
        foreach ($menu['items'] as $item) $this->assertNotNull($this->cms()->entries()->find($item['entry']));
    }
}
