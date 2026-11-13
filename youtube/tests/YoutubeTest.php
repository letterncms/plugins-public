<?php

declare(strict_types=1);

namespace Cms\Youtube\Tests;

use Cms\Modules\Blocks\BlockRegistry;
use Cms\Modules\Blocks\BlockRenderer;
use Cms\Modules\Blocks\Scope;
use Cms\Tests\TestCase;
use Cms\Youtube\YoutubeVideo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class YoutubeTest extends TestCase
{
    private const string ID = 'aqz-KE-bpKQ';

    protected function plugins(): array
    {
        return ['letterncms/youtube'];
    }

    protected function collections(): array
    {
        return ['pages' => ['title' => 'Pages', 'route' => '/{slug}', 'fields' => [['handle' => 'content', 'type' => 'blocks', 'body' => true]]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** YouTube as it answers for a public video: its title, and a poster in standard size only. */
    private function youtube(int $oembed = 200): void
    {
        Http::fake([
            'www.youtube.com/oembed*' => $oembed === 200 ? Http::response(['title' => 'Big Buck Bunny', 'type' => 'video']) : Http::response('Unauthorized', $oembed),
            'i.ytimg.com/vi/*/maxresdefault.jpg' => Http::response(self::jpeg(120, 90), 404),
            'i.ytimg.com/vi/*/sddefault.jpg' => Http::response(self::jpeg(640, 480)),
        ]);
    }

    private static function jpeg(int $width, int $height): string
    {
        $im = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }

    private function render(array $props, bool $editor = false): string
    {
        $renderer = app(BlockRenderer::class);
        $renderer->begin();
        $renderer->editor = $editor;

        try {
            return $renderer->withEditorDocument($editor ? 'test-source' : null, fn () => $renderer->render([['id' => 'v1', 'type' => 'youtube/video', 'props' => $props]], new Scope([])));
        } finally {
            $renderer->editor = false;
        }
    }

    public function test_every_shape_of_link_names_the_video(): void
    {
        $links = [
            'https://www.youtube.com/watch?v=aqz-KE-bpKQ' => [false, null],
            'https://www.youtube.com/watch?feature=share&v=aqz-KE-bpKQ&t=1m30s' => [false, 90],
            'http://m.youtube.com/watch?v=aqz-KE-bpKQ#t=45' => [false, 45],
            'youtube.com/watch?v=aqz-KE-bpKQ' => [false, null],
            'https://music.youtube.com/watch?v=aqz-KE-bpKQ&list=RD1' => [false, null],
            'https://youtu.be/aqz-KE-bpKQ?si=Xc3&t=30' => [false, 30],
            'https://www.youtube.com/shorts/aqz-KE-bpKQ' => [true, null],
            'https://youtube.com/shorts/aqz-KE-bpKQ?feature=share' => [true, null],
            'https://www.youtube.com/embed/aqz-KE-bpKQ?start=12' => [false, 12],
            'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ' => [false, null],
            'https://www.youtube.com/live/aqz-KE-bpKQ' => [false, null],
            'aqz-KE-bpKQ' => [false, null],
        ];

        foreach ($links as $link => [$short, $start]) {
            $this->assertSame(['id' => self::ID, 'short' => $short, 'start' => $start], YoutubeVideo::parse($link), $link);
        }

        foreach (['', 'https://vimeo.com/76979871', 'https://www.youtube.com/@blender', 'https://www.youtube.com/playlist?list=PL123', 'https://www.youtube.com/watch?v=short', 'https://youtube.com.evil.example/watch?v=aqz-KE-bpKQ', 'https://notyoutube.com/watch?v=aqz-KE-bpKQ', 'javascript:alert(1)', 'https://user@youtube.com/watch?v=aqz-KE-bpKQ'] as $link) {
            $this->assertNull(YoutubeVideo::parse($link), $link);
        }

        $this->assertTrue(YoutubeVideo::isPlaylist('https://www.youtube.com/playlist?list=PL123'));
    }

    public function test_start_times_are_read_as_people_write_them(): void
    {
        foreach (['90' => 90, '90s' => 90, '1:30' => 90, '1m30s' => 90, '1h2m3s' => 3723, '1:02:03' => 3723, '2m' => 120, '' => null, '0' => null, 'soon' => null, '1:3' => null] as $time => $seconds) {
            $this->assertSame($seconds, YoutubeVideo::seconds((string) $time), "“{$time}”");
        }
    }

    public function test_the_page_shows_the_poster_and_loads_youtube_only_when_played(): void
    {
        $this->youtube();

        $html = $this->render(['url' => 'https://www.youtube.com/watch?v='.self::ID, 'start' => '1:30']);

        $this->assertStringNotContainsString('<iframe', $html, 'nothing from YouTube until the visitor chooses');
        $this->assertStringContainsString('<a class="cms-youtube-video__play" href="https://www.youtube.com/watch?v='.self::ID.'&amp;t=90s"', $html, 'without scripts, a link to the video');
        $this->assertStringContainsString('data-cms-youtube="https://www.youtube-nocookie.com/embed/'.self::ID.'?autoplay=1&amp;start=90&amp;cc_load_policy=1&amp;cc_lang_pref=en&amp;hl=en&amp;rel=0&amp;playsinline=1"', $html, 'privacy-enhanced, captions on, playing at once');
        $this->assertStringContainsString('data-cms-youtube-title="Big Buck Bunny"', $html, 'its title on YouTube, for screen readers');
        $this->assertStringContainsString('<span class="cms-youtube-video__label">Play video: Big Buck Bunny</span>', $html);
        $this->assertMatchesRegularExpression('#<img src="/_cms/youtube/'.self::ID.'\.jpg\?v=\w+" alt="" width="640" height="480" loading="lazy" decoding="async">#', $html, 'its largest poster, served by the site itself');
        $this->assertFileExists(public_path('_cms/youtube/'.self::ID.'.jpg'));
        $this->assertStringContainsString('<div class="cms-ratio" style="aspect-ratio:16/9">', $html);
        $this->assertTrue(app(BlockRenderer::class)->features['youtube/video'] ?? false, 'its stylesheet comes with it');
        $this->assertTrue(app(BlockRenderer::class)->features['youtube/facade'] ?? false, 'and the script that plays it');

        Http::assertSentCount(3);
        $this->render(['url' => 'https://youtu.be/'.self::ID]);
        Http::assertSentCount(3);
    }

    public function test_a_short_is_vertical_and_links_to_the_short(): void
    {
        $this->youtube();

        $html = $this->render(['url' => 'https://www.youtube.com/shorts/'.self::ID]);
        $this->assertStringContainsString('style="aspect-ratio:9/16"', $html);
        $this->assertStringContainsString('href="https://www.youtube.com/shorts/'.self::ID.'"', $html);

        $this->assertStringContainsString('style="aspect-ratio:1/1"', $this->render(['url' => 'https://www.youtube.com/shorts/'.self::ID, 'aspect' => '1/1']), 'a chosen shape wins');
        $this->assertStringContainsString('style="aspect-ratio:16/9"', $this->render(['url' => self::ID, 'aspect' => '7/2']), 'one it doesn’t offer does not');
    }

    public function test_it_can_load_youtube_directly_with_youtube_s_own_host_and_no_captions(): void
    {
        $this->youtube();

        $html = $this->render(['url' => self::ID, 'load_on_click' => false, 'privacy' => false, 'captions' => false, 'title' => 'Our trailer']);

        $this->assertStringContainsString('<iframe src="https://www.youtube.com/embed/'.self::ID.'?hl=en&amp;rel=0&amp;playsinline=1" title="Our trailer" loading="lazy"', $html);
        $this->assertArrayNotHasKey('youtube/facade', app(BlockRenderer::class)->features, 'no script to load');
        $this->assertStringNotContainsString('cms-youtube-video__play', $html);
    }

    public function test_a_poster_from_the_media_library_takes_the_place_of_youtube_s(): void
    {
        $this->youtube();
        $file = tempnam(sys_get_temp_dir(), 'cms');
        file_put_contents($file, self::jpeg(1600, 900));
        $poster = $this->app->make(\Cms\Modules\Media\MediaStore::class)->store($file, 'poster.jpg')->id;

        $html = $this->render(['url' => self::ID, 'poster' => [$poster]]);

        $this->assertMatchesRegularExpression('#<span class="cms-youtube-video__poster"><picture>.*/_cms/img/'.$poster.'/#', $html);
        $this->assertStringNotContainsString('/_cms/youtube/', $html);
    }

    public function test_what_it_says_is_escaped(): void
    {
        $this->youtube();

        $html = $this->render(['url' => self::ID, 'title' => '"><script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('data-cms-youtube-title="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
    }

    public function test_without_a_video_the_site_shows_nothing_and_the_canvas_says_why(): void
    {
        $this->youtube();

        foreach (['', 'https://vimeo.com/76979871', 'https://www.youtube.com/playlist?list=PL123'] as $url) {
            $this->assertSame('', $this->render(['url' => $url]), $url);
        }

        $this->assertMatchesRegularExpression('#^<div class="cms-youtube-video" data-cms-document="test-source" data-cms-node="v1" data-cms-type="youtube/video" data-cms-media><span class="cms-media-placeholder" style="aspect-ratio:16/9"><svg#', $this->render([], editor: true), 'an empty block has the shape of the video');
        $this->assertStringContainsString('This isn’t a link to a YouTube video. Use the link from the video’s Share button.', $this->render(['url' => 'https://vimeo.com/76979871'], editor: true));
        $this->assertStringContainsString('This is a playlist. Use the link of one video in it.', $this->render(['url' => 'https://www.youtube.com/playlist?list=PL123'], editor: true));
        Http::assertNothingSent();
    }

    public function test_a_private_video_shows_nothing_and_the_canvas_says_so(): void
    {
        $this->youtube(oembed: 401);

        $this->assertSame('', $this->render(['url' => self::ID]));
        $this->assertStringContainsString('This video is private, gone, or its owner doesn’t allow it on other sites.', $this->render(['url' => self::ID], editor: true));
        Http::assertSentCount(1);
    }

    public function test_when_youtube_cannot_be_reached_its_poster_comes_from_youtube_and_the_policy_names_it(): void
    {
        Http::fake(static fn () => throw new ConnectionException('offline'));
        $sources = [];
        $this->cms()->hooks()->on('site.policy.source', function (string $kind, mixed $url) use (&$sources) {
            $sources[] = [$kind, $url];
        });

        $html = $this->render(['url' => self::ID]);

        $this->assertStringContainsString('<img src="https://i.ytimg.com/vi/'.self::ID.'/hqdefault.jpg" alt="" width="480" height="360"', $html, 'the video still shows');
        $this->assertStringContainsString('data-cms-youtube-title="YouTube video"', $html);
        $this->assertContains(['img', 'https://i.ytimg.com/vi/'.self::ID.'/hqdefault.jpg'], $sources);
    }

    public function test_the_security_policy_allows_the_players_frame_and_the_block_is_in_the_catalog(): void
    {
        $policy = $this->cms()->hooks()->filter('site.policy', ['frame-src' => ["'self'"]]);
        $this->assertSame(["'self'", 'www.youtube-nocookie.com', 'www.youtube.com'], $policy['frame-src']);

        $registry = app(BlockRegistry::class);
        $type = $registry->get('youtube/video');
        $this->assertSame('YouTube', $type->title);
        $this->assertSame('youtube-logo', $type->icon);
        $this->assertSame('letterncms/youtube', $type->package);
        $this->assertSame(['youtube/video'], $type->uses);
        $this->assertNotEmpty($type->example);
        $this->assertArrayHasKey('youtube/video', $registry->features());
        $this->assertArrayHasKey('youtube/facade', $registry->features());
    }

    public function test_a_page_saves_it_with_a_bare_id(): void
    {
        $context = \Cms\Abilities\AbilityContext::forUser($this->admin(), 'admin');
        $entry = $this->cms()->abilities()->execute('entries/create', ['collection' => 'pages', 'title' => 'Listen', 'data' => ['content' => [['type' => 'youtube/video', 'props' => ['url' => 'aqz-KE-bpKQ']]]]], $context);

        $this->assertSame('aqz-KE-bpKQ', $this->cms()->entries()->find($entry['id'])->data['content'][0]['props']['url'], 'a bare id is no web address, and is kept');
    }
}
