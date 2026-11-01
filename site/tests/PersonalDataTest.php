<?php

declare(strict_types=1);

namespace Cms\Site\Tests;

use Cms\Site\Build\SiteBuilder;
use Cms\Site\Rendering\PageContext;
use Cms\Tests\TestCase;
use Illuminate\Support\Facades\Auth;

/** A build can run inside anybody's request; what it writes is for everybody. */
final class PersonalDataTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks', 'letterncms/site', 'letterncms/seo'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // What a member plugin would do when it trusts the guard alone.
        $this->cms()->hooks()->addFilter('site.foot', static fn (array $foot, PageContext $context) => [
            ...$foot,
            '<p data-who="'.e((string) (Auth::user()?->email ?? 'nobody')).'" data-mode="'.$context->mode.'"></p>',
        ]);
        $this->cms()->content()->create('notes', ['title' => 'Public', 'status' => 'published', 'data' => ['body' => 'Words']]);
    }

    public function test_a_build_in_a_signed_in_request_writes_nothing_about_them(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->assertSame([], app(SiteBuilder::class)->buildAll()['errors']);

        $html = file_get_contents(public_path('notes/public/index.html'));
        $this->assertStringContainsString('data-who="nobody"', $html);
        $this->assertStringContainsString('data-mode="build"', $html);

        foreach ($this->built() as $file) {
            $this->assertStringNotContainsString($admin->email, (string) file_get_contents($file), "{$file} holds the email");
        }

        // The request itself carries on as it was.
        $this->assertSame($admin->id, Auth::id());
    }

    public function test_a_page_for_somebody_signed_in_is_never_cached(): void
    {
        $this->actingAs($admin = $this->admin());

        $response = $this->get('/notes/public')->assertOk();

        $response->assertSee('data-who="'.$admin->email.'"', false);
        $response->assertSee('data-mode="request"', false);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function test_an_anonymous_page_may_be_cached_and_carries_no_cookie(): void
    {
        $response = $this->get('/notes/public')->assertOk();

        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertSame('60', $response->headers->getCacheControlDirective('s-maxage'));
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_a_visitor_is_nobody_on_every_guard_until_the_code_ends(): void
    {
        $this->actingAs($this->admin());

        $this->cms()->asVisitor(function (): void {
            $this->assertNull(Auth::user());
            $this->assertNull(Auth::guard('web')->user());
            $this->assertNull(Auth::guard('token')->user());
            $this->assertNull(request()->user());
            $this->assertFalse($this->cms()->signedIn());
        });

        $this->assertTrue($this->cms()->signedIn());
    }

    /** @return list<string> */
    private function built(): array
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(public_path(), \FilesystemIterator::SKIP_DOTS));

        return array_values(array_map('strval', array_filter(iterator_to_array($files, false), static fn (\SplFileInfo $file) => $file->isFile() && ! str_contains($file->getPathname(), '/_cms/'))));
    }
}
