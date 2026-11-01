<?php

declare(strict_types=1);

use Cms\Navigation\MenuResolver;
use Cms\Site\Rendering\PageContext;

if (! function_exists('cms_menu')) {
    /**
     * Links of a menu for templates: [{label, url, newTab, current, active, children}].
     * Only live entries appear; `current` marks the page being rendered and
     * `active` its ancestors. Null when the menu does not exist, so themes can
     * fall back: `cms_menu('main') ?? [...]`.
     */
    function cms_menu(string $handle): ?array
    {
        $context = class_exists(PageContext::class) ? PageContext::current() : null;

        return app(MenuResolver::class)->resolve($handle, $context?->uri, $context?->locale());
    }
}
