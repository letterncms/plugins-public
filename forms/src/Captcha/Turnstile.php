<?php

declare(strict_types=1);

namespace Cms\Forms\Captcha;

/** Cloudflare Turnstile. */
final class Turnstile implements Captcha
{
    public const string VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private readonly Client $client, private readonly string $siteKey, private readonly string $secret) {}

    public function script(): string
    {
        return 'https://challenges.cloudflare.com/turnstile/v0/api.js';
    }

    public function widget(string $locale): string
    {
        return '<div class="cms-form__captcha cf-turnstile" style="min-height:65px" data-sitekey="'.e($this->siteKey).'" data-language="'.e($locale).'" data-theme="auto"></div>';
    }

    public function field(): string
    {
        return 'cf-turnstile-response';
    }

    public function global(): string
    {
        return 'turnstile';
    }

    /** Cloudflare documents 2048 characters at most. */
    public function maxToken(): int
    {
        return 2048;
    }

    public function verify(string $token): ?bool
    {
        $answer = $this->client->post(self::VERIFY, ['secret' => $this->secret, 'response' => $token]);

        return is_bool($answer['success'] ?? null) ? $answer['success'] : null;
    }
}
