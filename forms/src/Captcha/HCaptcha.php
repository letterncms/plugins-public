<?php

declare(strict_types=1);

namespace Cms\Forms\Captcha;

/** hCaptcha. */
final class HCaptcha implements Captcha
{
    public const string VERIFY = 'https://api.hcaptcha.com/siteverify';

    public function __construct(private readonly Client $client, private readonly string $siteKey, private readonly string $secret) {}

    public function script(): string
    {
        return 'https://js.hcaptcha.com/1/api.js';
    }

    /** As hCaptcha documents it: its scripts, frames, styles and calls come from hcaptcha.com and its subdomains. */
    public function sources(): array
    {
        return array_fill_keys(['script-src', 'frame-src', 'style-src', 'connect-src'], ['https://hcaptcha.com', 'https://*.hcaptcha.com']);
    }

    public function widget(string $locale): string
    {
        return '<div class="cms-form__captcha h-captcha" style="min-height:78px" data-sitekey="'.e($this->siteKey).'" data-hl="'.e($locale).'"></div>';
    }

    public function field(): string
    {
        return 'h-captcha-response';
    }

    public function global(): string
    {
        return 'hcaptcha';
    }

    /** hCaptcha's tokens are signed and run to several thousand characters; its documentation says they can pass 4 KB. */
    public function maxToken(): int
    {
        return 8192;
    }

    public function verify(string $token): ?bool
    {
        $answer = $this->client->post(self::VERIFY, ['secret' => $this->secret, 'response' => $token, 'sitekey' => $this->siteKey]);

        return is_bool($answer['success'] ?? null) ? $answer['success'] : null;
    }
}
