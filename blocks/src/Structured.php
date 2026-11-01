<?php

declare(strict_types=1);

namespace Cms\Blocks;

/**
 * Blocks can describe themselves as schema.org data (FAQ, how-to…). The SEO
 * plugin merges these into the page graph; without it they are emitted directly.
 */
final class Structured
{
    public static function add(string $key, array $data): void
    {
        $hooks = cms()->hooks();

        if ($hooks->has('render.structured')) {
            $hooks->dispatch('render.structured', $key, $data);

            return;
        }

        $json = json_encode(['@context' => 'https://schema.org', ...$data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        $hooks->dispatch('render.head', 'ld-'.$key, '<script type="application/ld+json">'.$json.'</script>');
    }
}
