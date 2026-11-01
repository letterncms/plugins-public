<?php

declare(strict_types=1);

namespace Cms\I18n;

use Closure;
use Illuminate\Support\Str;
use Cms\Abilities\AbilityException;
use Cms\Content\Entries\Entry;
use Cms\Extend\Hooks;
use Cms\Cms;
use Cms\Users\User;

/**
 * Translations are entries in another locale that point at their original
 * through `originId`. Every version of a page shares the original's id as
 * its origin, so any one of them finds all the others.
 */
final class Translations
{
    public function __construct(private readonly Cms $cms, private readonly Languages $languages, private readonly Hooks $hooks) {}

    /** @return array<string, Entry> locale => entry, every version including $entry */
    public function versions(Entry $entry): array
    {
        $origin = $entry->originId ?? $entry->id;
        $versions = [];

        if ($original = $entry->originId ? $this->cms->entries()->find($origin) : $entry) {
            $versions[$original->locale] = $original;
        }

        foreach ($this->cms->entries()->query()->collection($entry->collection)->where('origin_id', $origin)->get() as $translation) {
            $versions[$translation->locale] ??= $translation;
        }

        $versions[$entry->locale] = $entry;

        return $versions;
    }

    public function in(Entry $entry, string $locale): ?Entry
    {
        return $entry->locale === $locale ? $entry : ($this->versions($entry)[$locale] ?? null);
    }

    /** A translator turns a list of strings into the target language (see `i18n.translator`). */
    public function translator(): ?Closure
    {
        $translator = $this->hooks->filter('i18n.translator', null);

        return $translator instanceof Closure ? $translator : null;
    }

    /**
     * Start a translation of $source in $locale as a draft: content copied (or
     * machine-translated), placed under the parent's translation.
     */
    public function create(Entry $source, string $locale, ?User $user, bool $translate = false): Entry
    {
        if (! $this->languages->enabled($locale)) {
            throw AbilityException::invalid(__(':locale is not one of this site’s languages.', ['locale' => $locale]));
        }

        if ($locale === $source->locale) {
            throw AbilityException::invalid(__('The entry is already in that language.'));
        }

        if ($existing = $this->in($source, $locale)) {
            throw AbilityException::conflict(__('There is already a :locale version: :id.', ['locale' => $locale, 'id' => $existing->id]));
        }

        $working = $source->working();
        $content = ['title' => (string) $working['title'], 'data' => (array) $working['data']];
        $parentId = null;

        if ($source->parentId && ($parent = $this->cms->entries()->find($source->parentId))) {
            $parentId = $this->in($parent, $locale)?->id
                ?? throw AbilityException::invalid(__('Translate the parent page “:title” into :locale first, so this one has a place in the tree.', ['title' => $parent->title, 'locale' => $locale]));
        }

        if ($translate) {
            $translator = $this->translator() ?? throw AbilityException::invalid(__('No translator is set up. Enable the assistant (with an API key) to translate automatically.'));
            $content = $this->translate($content, $source->locale, $locale, $translator);
        }

        // A translation stands on its own in search: it never points its canonical at, or inherits a noindex from, the original.
        if (is_array($content['data']['seo'] ?? null)) {
            unset($content['data']['seo']['canonical'], $content['data']['seo']['noindex']);
        }

        return $this->cms->content()->copyFrom($source, [
            'title' => $content['title'],
            // A translated title gets its own slug (when it has Latin letters); otherwise keep the original's.
            'slug' => ($translate ? Str::slug($content['title'], '-', strtolower(strtok($locale, '-_'))) : '') ?: $working['slug'],
            'data' => $content['data'],
            'locale' => $locale,
            'parentId' => $parentId,
            'position' => $source->position, // Lists read in the same order in every language.
            'originId' => $source->originId ?? $source->id,
            'status' => 'draft',
        ], $user);
    }

    /** @param Closure(list<string>, string, string): list<string> $translator */
    private function translate(array $content, string $from, string $to, Closure $translator): array
    {
        $map = StringMap::extract($content);

        if ($map['strings'] === []) {
            return $content;
        }

        $translated = $translator($map['strings'], $from, $to);

        if (count($translated) !== count($map['strings'])) {
            throw new AbilityException(__('The translation came back incomplete. Try again.'), 'failed');
        }

        return StringMap::apply($content, $map['paths'], $translated);
    }
}
