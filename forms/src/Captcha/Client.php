<?php

declare(strict_types=1);

namespace Cms\Forms\Captcha;

/** How a provider is asked. Tests bind a fake, so they need no network and no keys. */
interface Client
{
    /**
     * POST form fields and read the JSON answer.
     *
     * @param  array<string, string>  $fields
     * @return array<string, mixed>|null  null when the provider can't be reached or answers anything but JSON
     */
    public function post(string $url, array $fields): ?array;
}
