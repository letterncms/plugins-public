<?php

declare(strict_types=1);

namespace Cms\Site\Tests;

use Cms\Site\Build\LinkChecker;
use Cms\Tests\TestCase;

final class LinkCheckerTest extends TestCase
{
    private string $root;

    protected function plugins(): array
    {
        return ['letterncms/blocks', 'letterncms/site'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/cms-links-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/guide', 0777, true);
        mkdir($this->root.'/_cms', 0777, true);
        file_put_contents($this->root.'/index.html', '<!doctype html><html><body><h1 id="top">Home</h1></body></html>');
        file_put_contents($this->root.'/guide/index.html', '<!doctype html><html><body><h2 id="install">Install</h2></body></html>');
        file_put_contents($this->root.'/logo.svg', '<svg/>');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    /** @return list<string> */
    private function broken(string $body): array
    {
        file_put_contents($this->root.'/page.html', '<!doctype html><html><body>'.$body.'</body></html>');

        return array_column((new LinkChecker($this->root, 'https://example.test'))->check()['broken'], 'url');
    }

    public function test_links_that_lead_somewhere_pass(): void
    {
        $this->assertSame([], $this->broken('<a href="/">a</a><a href="/guide">b</a><a href="/guide/">c</a><a href="/guide#install">d</a><a href="#here" id="here">e</a><img src="/logo.svg" alt=""><a href="https://example.test/guide">f</a><a href="/guide?x=1">g</a>'));
    }

    public function test_external_links_are_left_alone(): void
    {
        $this->assertSame([], $this->broken('<a href="https://elsewhere.test/nope">a</a><a href="mailto:a@b.test">b</a><a href="tel:+1">c</a><a href="//cdn.test/x.js">d</a>'));
    }

    public function test_what_the_application_answers_is_left_alone(): void
    {
        $this->assertSame([], $this->broken('<img src="/api/analytics/view?p=%2F" alt=""><a href="/admin">a</a><a href="/admin/login">b</a><a href="/install">c</a><a href="/downloads/cms-latest.zip">f</a>'));
    }

    public function test_an_address_only_counts_when_something_answers_it(): void
    {
        $this->assertSame(['/account/licenses', '/reset'], $this->broken('<a href="/account/licenses">d</a><a href="/reset">e</a>'));
    }

    public function test_a_query_does_not_hide_the_anchor_after_it(): void
    {
        $this->assertSame(['/guide?x=1#missing'], $this->broken('<a href="/guide?x=1#install">a</a><a href="/guide?x=1#missing">b</a>'));
    }

    public function test_it_finds_missing_pages_files_and_anchors(): void
    {
        $this->assertSame(
            ['/nope', '/guide#missing', '#gone', 'https://example.test/old', '/missing.png'],
            $this->broken('<a href="/nope">a</a><a href="/guide#missing">b</a><a href="#gone">c</a><a href="https://example.test/old">d</a><img src="/missing.png" alt="">'),
        );
    }

    public function test_it_reads_the_candidates_of_a_srcset(): void
    {
        $this->assertSame(['/small.webp'], $this->broken('<img src="/logo.svg" srcset="/small.webp 400w, /logo.svg 800w" alt="">'));
    }

    public function test_code_samples_are_not_links(): void
    {
        $this->assertSame([], $this->broken('<pre><code>&lt;a href="/nope"&gt;</code></pre>'));
    }
}
