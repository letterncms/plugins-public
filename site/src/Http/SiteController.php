<?php

declare(strict_types=1);

namespace Cms\Site\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Cms\Cms;
use Cms\Content\Entries\Entry;
use Cms\Content\Entries\EntryGate;
use Cms\Content\Entries\UriBuilder;
use Illuminate\Support\Str;
use Cms\Blocks\Document;
use Cms\Blocks\Patterns;
use Cms\Blocks\Templates;
use Cms\Site\Rendering\Renderer;
use Cms\Users\User;

/**
 * Dynamic rendering. Built sites are served as static files by the web server;
 * this answers everything that has not been built (yet), and previews.
 */
final class SiteController
{
    public function __construct(private readonly Cms $cms, private readonly Renderer $renderer) {}

    public function show(Request $request): SymfonyResponse
    {
        $path = '/'.trim(rawurldecode($request->path()), '/');
        $page = 1;

        if (preg_match('#^(.*)/page/(\d+)$#', $path, $m) && (int) $m[2] > 1) {
            [$path, $page] = [$m[1] === '' ? '/' : $m[1], (int) $m[2]];
        }

        $entry = $this->cms->entries()->findByUri($path);

        if (! $entry || ! $entry->isLive()) {
            $result = $this->renderer->notFound($path);

            return $this->respond($result['html'], 404);
        }

        $gate = $this->cms->entries()->gate();

        // A gated page reads differently per person, so it is never a file and no cache may keep it.
        switch ($gate->decide($entry)) {
            case EntryGate::MISSING:
                return $this->respond($this->renderer->notFound($path)['html'], 404);
            case EntryGate::SIGN_IN:
                return redirect()->to((string) $this->cms->accounts()->signInUrl($request->getRequestUri(), $entry->locale))->header('Cache-Control', 'no-store, private');
            case EntryGate::DENIED:
                return $this->cms->hooks()->filter('account.denied', null, $entry) ?? $this->respond($this->renderer->notFound($path)['html'], 404);
        }

        $result = $this->renderer->entry($gate->view($entry), $page);

        if ($page > $result['context']->pages) {
            return $this->respond($this->renderer->notFound($path)['html'], 404);
        }

        // A page rendered for somebody signed in may hold their data: no cache may keep it.
        return $this->cms->signedIn() || $gate->isGated($entry)
            ? $this->respond($result['html'], 200)->header('Cache-Control', 'no-store, private')
            : $this->respond($result['html'], 200)->setPublic()->setMaxAge(0)->setSharedMaxAge(60);
    }

    /** Preview a working copy (drafts included). Requires an admin session. */
    public function preview(Request $request, string $id): Response
    {
        $entry = $this->cms->entries()->find($id);
        $user = $request->user();

        abort_if(! $entry || ! $user || ! $user->hasPermission("entries.{$entry->collection}.view"), 404);

        $document = $request->boolean('editor') ? bin2hex(random_bytes(16)) : null;
        $result = $this->renderer->entry($entry->withWorkingCopy(), max(1, (int) $request->query('page', 1)), preview: true, editor: $document !== null, bodyDocument: $document);

        return $this->rendered($result['html'], $document);
    }

    /**
     * Render an editorial snapshot without persistence or publication side effects.
     * Body: editorial snapshot {title, slug, data, authorId}, play, optional sample. Nothing is stored.
     *
     * Entries render with their template; a template renders around a sample
     * entry; a component renders on its own.
     */
    public function render(Request $request, ?string $id = null): Response
    {
        $user = $request->user();
        if ($id === null && ! $request->json()->has('collection') && $request->boolean('standalone')) {
            abort_unless($user?->hasPermission('admin.access'), 403);

            return $this->standalone($request);
        }
        if ($id !== null) {
            $entry = $this->cms->entries()->find($id);
            abort_if(! $entry || ! $user || ! $user->hasPermission("entries.{$entry->collection}.view"), 404);
            $working = $entry->withWorkingCopy();
        } else {
            $handle = $request->json('collection');
            $collection = is_string($handle) ? $this->cms->collections()->find($handle) : null;
            abort_if(! $collection || ! $user || (! $user->hasPermission("entries.{$handle}.create") && ! $user->hasPermission("entries.{$handle}.edit")), 404);
            $locale = $request->json('locale', $this->cms->defaultLocale());
            abort_if(! is_string($locale) || ! in_array($locale, $this->cms->locales(), true), 422, __('Choose an existing locale.'));
            $working = new Entry(id: strtolower((string) Str::ulid()), collection: $handle, locale: $locale, slug: '', title: '', data: $collection->blueprint()->defaults(), authorId: $user->id);
            $parentId = $request->json('parentId');
            if (is_string($parentId) && $parentId !== '') {
                $parent = $this->cms->entries()->find($parentId);
                abort_if(! $parent || $parent->collection !== $handle || $parent->locale !== $locale, 422, __('Choose a parent in this collection and language.'));
                $working->parentId = $parentId;
            }
        }
        $title = $request->json('title');
        $data = $request->json('data');
        $editor = ! $request->boolean('play');

        if (is_string($title)) {
            $working->title = mb_substr(strip_tags($title), 0, 500);
        }

        if ($request->json()->has('authorId')) {
            $authorId = $request->json('authorId');
            if ($authorId !== null && (! is_string($authorId) || ($authorId !== $working->authorId && ! User::query()->whereKey($authorId)->exists()))) {
                return $this->respond('<!doctype html><p>'.e(__('Choose an existing author.')).'</p>', 422);
            }
            $working->authorId = $authorId;
        }

        if (is_string($slug = $request->json('slug'))) {
            $working->slug = mb_substr(strip_tags($slug), 0, 500);
        }

        if (is_array($data)) {
            try {
                $working->data = $working->collection()->blueprint()->process(array_replace($working->data, $data), partial: true);
            } catch (\Cms\Support\Schema\SchemaException $e) {
                return $this->respond('<!doctype html><p>'.e($e->getMessage()).'</p>', 422);
            }
        }

        $collection = $working->collection();
        $working->uri = app(UriBuilder::class)->build($working, $collection, fn (string $parentId) => $this->cms->entries()->find($parentId));
        $field = $request->json('field');
        if ($field !== null && (! is_string($field) || $collection->blueprint()->field($field)?->type !== 'blocks')) {
            return $this->respond('<!doctype html><p>'.e(__('Choose a blocks field.')).'</p>', 422);
        }
        $document = $editor ? bin2hex(random_bytes(16)) : null;
        $blocks = (array) ($working->data[$field ?? $collection->body ?? 'blocks'] ?? []);

        if ($field !== null && $field !== $collection->body) {
            $html = $this->renderer->blocks($blocks, $working, $working->title, $editor, $document);
        } else {
            $html = match ($working->collection) {
                Templates::COLLECTION => ($sample = $this->sample((string) $request->json('sample'), (array) ($working->data['collections'] ?? []), $user))
                    ? $this->renderer->entry($sample->withWorkingCopy(), 1, preview: true, editor: $editor, template: Templates::shape($working), templateDocument: $document)['html']
                    : $this->renderer->blocks($blocks, null, $working->title, $editor, $document),
                Patterns::COLLECTION => $this->renderer->blocks($blocks, null, $working->title, $editor, $document),
                default => $this->renderer->entry($working, 1, preview: true, editor: $editor, bodyDocument: $document)['html'],
            };
        }

        return $this->rendered($html, $document);
    }

    /** Standalone schema fields have no synthetic entry or collection identity. */
    private function standalone(Request $request): Response
    {
        $field = $request->json('field', 'blocks');
        $data = $request->json('data', []);
        if (! is_string($field) || $field === '' || ! is_array($data) || ! is_array($nodes = $data[$field] ?? [])) {
            return $this->respond('<!doctype html><p>'.e(__('Choose a blocks field.')).'</p>', 422);
        }
        try {
            $nodes = app(Document::class)->normalize($nodes);
        } catch (\Cms\Support\Schema\SchemaException $e) {
            return $this->respond('<!doctype html><p>'.e($e->getMessage()).'</p>', 422);
        }
        $title = $request->json('title');
        $title = is_string($title) ? mb_substr(strip_tags($title), 0, 500) : '';
        $document = $request->boolean('play') ? null : bin2hex(random_bytes(16));

        return $this->rendered($this->renderer->blocks($nodes, null, $title, $document !== null, $document), $document);
    }

    private function rendered(string $html, ?string $document): Response
    {
        $response = $this->respond($html, 200)->header('Cache-Control', 'no-store, private');
        if ($document !== null) $response->header('X-Cms-Editor-Document', $document);

        return $response;
    }

    /** An entry to show a template with: the one asked for, else the newest live entry of a fitting collection. */
    private function sample(string $id, array $collections, User $user): ?Entry
    {
        if ($id !== '' && ($entry = $this->cms->entries()->find($id)) && $user->hasPermission("entries.{$entry->collection}.view")) {
            return $entry;
        }

        $handles = $collections ?: array_keys(array_filter($this->cms->collections()->all(), static fn ($c) => $c->routable()));

        foreach ($handles as $handle) {
            if (is_string($handle) && $user->hasPermission("entries.{$handle}.view") && $this->cms->collections()->find($handle) && ($entry = $this->cms->entries()->query()->collection($handle)->live()->orderBy('-published_at')->first())) {
                return $entry;
            }
        }

        return null;
    }

    private function respond(string $html, int $status): Response
    {
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
