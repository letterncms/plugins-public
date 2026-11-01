<?php

declare(strict_types=1);

namespace Cms\Media\Tests;

use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Media\MediaItem;
use Cms\Media\MediaManifest;
use Cms\Media\MediaReferences;
use Cms\Media\MediaStore;
use Cms\Media\Variants;
use Cms\Support\Files;
use Cms\Support\Yaml;
use Cms\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

final class MediaManifestTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/media'];
    }

    private function jpeg(int $w = 1200, int $h = 800, int $blue = 200): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, $blue));
        $file = tempnam(sys_get_temp_dir(), 'cms');
        imagejpeg($im, $file, 85);

        return $file;
    }

    private function manifest(): MediaManifest
    {
        return app(MediaManifest::class);
    }

    private function store(string $name = 'photo.jpg', int $blue = 200): MediaItem
    {
        return app(MediaStore::class)->store($this->jpeg(blue: $blue), $name);
    }

    public function test_every_change_is_written_to_a_record(): void
    {
        $item = $this->store();
        $file = content_path("media/{$item->id}.yaml");
        $this->assertFileExists($file);

        $record = Yaml::read($file);
        $this->assertSame($item->id, $record['id']);
        $this->assertSame($item->path, $record['path']);
        $this->assertSame($item->hash, $record['hash']);
        $this->assertArrayNotHasKey('user_id', $record);

        app(MediaStore::class)->update($item, ['alt' => 'Blue', 'focal' => ['x' => 10, 'y' => 20]]);
        $record = Yaml::read($file);
        $this->assertSame('Blue', $record['alt']);
        $this->assertSame(['x' => 10, 'y' => 20], $record['focal']);

        app(MediaStore::class)->delete($item);
        $this->assertFileDoesNotExist($file);
    }

    public function test_the_table_rebuilds_from_the_records_after_a_reset(): void
    {
        $item = $this->store();
        app(MediaStore::class)->update($item, ['alt' => 'Kept']);
        $id = $item->id;
        MediaItem::query()->delete();

        $report = $this->manifest()->import();

        $this->assertSame([$id], $report['added']);
        $back = MediaItem::query()->findOrFail($id);
        $this->assertSame('Kept', $back->alt);
        $this->assertSame($item->path, $back->path);
        $this->assertSame([1200, 800], [$back->width, $back->height]);
    }

    public function test_sync_imports_the_records(): void
    {
        $id = $this->store()->id;
        MediaItem::query()->delete();

        Artisan::call('sync');

        $this->assertNotNull(MediaItem::query()->find($id));
    }

    public function test_a_record_with_only_an_id_and_a_path_reads_the_rest_from_the_file(): void
    {
        Files::ensureDir(public_path('media/screens'));
        copy($this->jpeg(640, 400), public_path('media/screens/a.jpg'));
        $id = '01m5x9jmrth1ggeatmbzxcz7vk';
        Yaml::write(content_path("media/{$id}.yaml"), ['id' => $id, 'path' => 'media/screens/a.jpg']);

        $this->manifest()->import();

        $item = MediaItem::query()->findOrFail($id);
        $this->assertSame('image/jpeg', $item->mime);
        $this->assertSame([640, 400], [$item->width, $item->height]);
        $this->assertSame(hash_file('sha256', public_path('media/screens/a.jpg')), $item->hash);
        $this->assertSame(['id', 'path'], array_keys(Yaml::read(content_path("media/{$id}.yaml"))), 'not written back');

        // New pixels at the same path: the record stays, the row and the variant URLs follow.
        $before = app(Variants::class)->url($item, 480, 'webp');
        copy($this->jpeg(640, 400, 10), public_path('media/screens/a.jpg'));
        Yaml::write(content_path("media/{$id}.yaml"), ['id' => $id, 'path' => 'media/screens/a.jpg', 'alt' => 'New']);

        $report = $this->manifest()->import();

        $this->assertSame([$id], $report['updated']);
        $this->assertNotSame($before, app(Variants::class)->url($item->fresh(), 480, 'webp'));
    }

    public function test_a_hand_written_date_does_not_count_as_a_change_every_time(): void
    {
        $item = $this->store();
        $file = content_path("media/{$item->id}.yaml");
        file_put_contents($file, preg_replace("/created_at: .*/", 'created_at: 2026-10-30 12:00:00', (string) file_get_contents($file)));

        $this->manifest()->import();
        $this->assertSame([], $this->manifest()->import()['updated']);
    }

    public function test_a_removed_record_removes_the_row_but_not_while_another_file_is_broken(): void
    {
        $a = $this->store('a.jpg', 40);
        $b = $this->store('b.jpg', 140);
        $this->manifest()->import();

        @unlink(content_path("media/{$a->id}.yaml"));
        file_put_contents(content_path("media/{$b->id}.yaml"), "id: [unclosed\n");

        $report = $this->manifest()->import();
        $this->assertNotSame([], $report['parse_errors']);
        $this->assertNotNull(MediaItem::query()->find($a->id), 'a bad merge cannot delete a row');

        app(MediaManifest::class)->write($b->fresh());
        $report = $this->manifest()->import();
        $this->assertSame([$a->id], $report['removed']);
        $this->assertNull(MediaItem::query()->find($a->id));
        $this->assertFileExists(public_path($a->path), 'the binary is the deploy\'s business');
    }

    public function test_a_record_without_a_file_stays_and_the_others_still_import(): void
    {
        $good = $this->store();
        $missing = '01m5x9jmrth1ggeatmbzxcz7vm';
        Yaml::write(content_path("media/{$missing}.yaml"), ['id' => $missing, 'path' => 'media/gone.jpg']);
        MediaItem::query()->delete();

        $report = $this->manifest()->import();

        $this->assertSame([$missing], $report['missing_files']);
        $this->assertNotNull(MediaItem::query()->find($good->id));
        $this->assertNotNull(MediaItem::query()->find($missing));
        $this->assertNull(cms()->hooks()->filter('media.url', null, $missing), 'a page never links to a file that is not there');
    }

    public function test_records_are_held_to_the_same_bar_as_uploads(): void
    {
        Files::ensureDir(public_path('media'));
        file_put_contents(public_path('media/x.svg'), '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        file_put_contents(public_path('media/x.jpg'), "<?php echo 1;");
        $cases = [
            '01m5x9jmrth1ggeatmbzxcz7v1' => ['path' => '_cms/assets/x.js', 'reason' => 'invalid_path'],
            '01m5x9jmrth1ggeatmbzxcz7v2' => ['path' => 'media/../../x.jpg', 'reason' => 'invalid_path'],
            '01m5x9jmrth1ggeatmbzxcz7v3' => ['path' => 'media/x.svg', 'reason' => 'unsafe_svg'],
            '01m5x9jmrth1ggeatmbzxcz7v4' => ['path' => 'media/x.jpg', 'reason' => 'refused_type'],
        ];

        foreach ($cases as $id => $case) {
            Yaml::write(content_path("media/{$id}.yaml"), ['id' => $id, 'path' => $case['path']]);
        }

        $report = $this->manifest()->import();

        $this->assertEqualsCanonicalizing(
            array_map(static fn ($id, $case) => ['id' => $id, 'reason' => $case['reason']], array_keys($cases), $cases),
            $report['refused'],
        );
        $this->assertSame(0, MediaItem::query()->count());
    }

    public function test_an_overwrite_with_the_same_file_count_is_seen_and_own_writes_are_not(): void
    {
        $item = $this->store();
        $this->assertFalse($this->manifest()->stale(), 'own writes do not count as a change');

        $file = content_path("media/{$item->id}.yaml");
        file_put_contents($file, (string) file_get_contents($file)."caption: From FTP\n");
        $this->assertTrue($this->manifest()->stale());

        $this->manifest()->import();
        $this->assertFalse($this->manifest()->stale());
        $this->assertSame('From FTP', $item->fresh()->caption);
    }

    public function test_an_upload_that_cannot_write_its_record_leaves_nothing_behind(): void
    {
        Files::ensureDir(content_path());
        file_put_contents(content_path('media'), 'in the way');

        try {
            app(MediaStore::class)->store($this->jpeg(), 'photo.jpg');
            $this->fail('expected the upload to be refused');
        } catch (AbilityException $e) {
            $this->assertStringContainsString('content/media', $e->getMessage());
        } finally {
            @unlink(content_path('media'));
        }

        $this->assertSame(0, MediaItem::query()->count());
        $this->assertSame([], glob(public_path('media/*/*/*')) ?: []);
    }

    public function test_a_url_of_a_file_with_a_record_renders_responsive(): void
    {
        Files::ensureDir(public_path('media/screens'));
        copy($this->jpeg(1600, 1000), public_path('media/screens/editor.jpg'));
        $id = '01m5x9jmrth1ggeatmbzxcz7vk';
        Yaml::write(content_path("media/{$id}.yaml"), ['id' => $id, 'path' => 'media/screens/editor.jpg']);
        $this->manifest()->import();

        $html = cms()->hooks()->filter('media.img', null, '/media/screens/editor.jpg', 'Editor', ['priority' => true, 'sizes' => '100vw']);

        $this->assertStringContainsString('<picture>', $html);
        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('width="1600" height="1000"', $html);
        $this->assertStringContainsString('fetchpriority="high"', $html);
    }

    public function test_the_report_names_what_is_broken_and_delete_is_refused_while_in_use(): void
    {
        $used = $this->store('used.jpg', 40);
        $free = $this->store('free.jpg', 140);
        $byUrl = $this->store('url.jpg', 240);
        $gone = '01m5x9jmrth1ggeatmbzxcz7zz';
        Files::ensureDir(public_path('media/loose'));
        file_put_contents(public_path('media/loose/a.txt'), 'x');
        Files::put(content_path('design/tokens.json'), (string) json_encode(['color' => ['hero' => "media:{$used->id}"]]));
        Yaml::write(content_path('globals/brand.yaml'), ['logo' => ['image' => "/{$byUrl->path}"], 'other' => "media:{$gone}", 'broken' => ['image' => '/media/nope.webp']]);

        $report = app(MediaReferences::class)->report();

        $this->assertSame(3, $report['items']);
        $this->assertEqualsCanonicalizing([$gone, '/media/nope.webp'], array_column($report['dangling'], 'id'));
        $this->assertSame([$free->id], $report['unused']);
        $this->assertSame(['media/loose/a.txt'], $report['orphan_files']);

        $ctx = AbilityContext::forUser($this->admin(), 'admin');
        $abilities = $this->cms()->abilities();

        try {
            $abilities->execute('media/delete', ['id' => $used->id], $ctx);
            $this->fail('expected a conflict');
        } catch (AbilityException $e) {
            $this->assertStringContainsString('Design tokens', $e->getMessage());
        }

        $this->assertNotNull(MediaItem::query()->find($used->id));
        $abilities->execute('media/delete', ['id' => $used->id, 'force' => true], $ctx);
        $this->assertNull(MediaItem::query()->find($used->id));
        $abilities->execute('media/delete', ['id' => $free->id], $ctx);
    }

    public function test_the_check_command_fails_strict_on_what_is_broken(): void
    {
        $this->store();
        $this->assertSame(0, Artisan::call('media:check', ['--strict' => true]));

        $id = '01m5x9jmrth1ggeatmbzxcz7vm';
        Yaml::write(content_path("media/{$id}.yaml"), ['id' => $id, 'path' => 'media/gone.jpg']);
        $this->assertSame(0, Artisan::call('media:check'));
        $this->assertSame(1, Artisan::call('media:check', ['--strict' => true]));
    }
}
