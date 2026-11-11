<?php

declare(strict_types=1);

namespace Cms\Forms\Captcha;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Asks over HTTPS, briefly: a visitor is waiting for the answer. */
final class HttpClient implements Client
{
    public function post(string $url, array $fields): ?array
    {
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(3)->timeout(6)->withoutRedirecting()->post($url, $fields);
        } catch (Throwable) {
            return null;
        }

        $json = $response->successful() ? $response->json() : null;

        return is_array($json) ? $json : null;
    }
}
