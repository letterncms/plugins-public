<?php

declare(strict_types=1);

namespace Cms\Site\Build;

use DOMDocument;
use DOMElement;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Reads the built site in public/ and finds internal links, images and anchors that lead
 * nowhere. External links are left alone: other sites' uptime is not this site's failure.
 */
final class LinkChecker
{
    /** @var array<string, list<string>> ids per built file, read once */
    private array $anchors = [];

    /** @var array<string, DOMDocument|null> */
    private array $documents = [];

    private string $root;

    public function __construct(?string $root = null, private readonly ?string $siteUrl = null)
    {
        $this->root = rtrim($root ?? public_path(), '/');
    }

    /** @return array{pages: int, broken: list<array{page: string, url: string}>} */
    public function check(): array
    {
        $broken = [];
        $files = $this->pages();

        foreach ($files as $file) {
            $page = $this->pageUrl($file);
            $document = $this->document($file);

            if (! $document) {
                continue;
            }

            foreach ($this->references($document) as $url) {
                if (! $this->resolves($url, $file)) {
                    $broken[$page."\0".$url] = ['page' => $page, 'url' => $url];
                }
            }
        }

        return ['pages' => count($files), 'broken' => array_values($broken)];
    }

    /** @return list<string> every built HTML file */
    private function pages(): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = (string) $file;

            if (str_ends_with($path, '.html') && ! str_starts_with($path, $this->root.'/_cms/')) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function references(DOMDocument $document): array
    {
        $urls = [];

        foreach (['a' => 'href', 'link' => 'href', 'img' => 'src', 'source' => 'src', 'script' => 'src', 'video' => 'src'] as $tag => $attribute) {
            foreach ($document->getElementsByTagName($tag) as $element) {
                if ($element instanceof DOMElement && ($url = trim($element->getAttribute($attribute))) !== '') {
                    $urls[] = $url;
                }
            }
        }

        foreach ($document->getElementsByTagName('img') as $image) {
            if ($image instanceof DOMElement && ($srcset = $image->getAttribute('srcset')) !== '') {
                foreach (explode(',', $srcset) as $candidate) {
                    $urls[] = explode(' ', trim($candidate))[0];
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function resolves(string $url, string $from): bool
    {
        $site = rtrim($this->siteUrl ?? (string) config('app.url'), '/');

        if ($site !== '' && str_starts_with($url, $site.'/')) {
            $url = substr($url, strlen($site));
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $url)) {
            return true;
        }

        // Answered by the application, not by a file: the API, the admin and the installer.
        // Release zips are written by the release, not by a build.
        if (preg_match('#^/(api|admin|install)(/|\?|$)|^/downloads/#', $url)) {
            return true;
        }

        [$before, $fragment] = array_pad(explode('#', $url, 2), 2, '');
        $path = explode('?', $before)[0];

        // A plugin's own addresses, like the pages of a store.
        if (str_starts_with($path, '/') && function_exists('cms') && cms()->hooks()->filter('site.links.answered', false, $path)) {
            return true;
        }

        $file = $path === '' ? $from : $this->target($path, $from);

        if ($file === null) {
            return false;
        }

        return $fragment === '' || ! str_ends_with($file, '.html') || $this->hasAnchor($file, rawurldecode($fragment));
    }

    private function target(string $path, string $from): ?string
    {
        $base = str_starts_with($path, '/') ? $this->root : dirname($from);
        $full = $base.'/'.rawurldecode(ltrim($path, '/'));

        foreach ([$full, rtrim($full, '/').'/index.html', rtrim($full, '/').'.html'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function hasAnchor(string $file, string $id): bool
    {
        if (! isset($this->anchors[$file])) {
            $this->anchors[$file] = [];
            $document = $this->document($file);

            foreach ($document ? $document->getElementsByTagName('*') : [] as $element) {
                if ($element instanceof DOMElement && $element->hasAttribute('id')) {
                    $this->anchors[$file][] = $element->getAttribute('id');
                }
            }
        }

        return in_array($id, $this->anchors[$file], true);
    }

    private function document(string $file): ?DOMDocument
    {
        if (array_key_exists($file, $this->documents)) {
            return $this->documents[$file];
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.(string) file_get_contents($file), LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $this->documents[$file] = $loaded ? $document : null;
    }

    private function pageUrl(string $file): string
    {
        $path = substr($file, strlen($this->root));
        $path = (string) preg_replace('#/index\.html$#', '/', $path);

        return $path === '' ? '/' : $path;
    }
}
