<?php

declare(strict_types=1);

namespace Cms\Forms\Captcha;

use Cms\Cms;

/** The CAPTCHA the Forms settings choose, if any. */
final class Captchas
{
    public const array PROVIDERS = ['turnstile' => Turnstile::class, 'hcaptcha' => HCaptcha::class];

    public function __construct(private readonly Cms $cms) {}

    /** Null without a provider, or while one of its two keys is missing: a half-configured check would lock every form. */
    public function current(): ?Captcha
    {
        $class = self::PROVIDERS[(string) $this->cms->setting('forms', 'captcha', 'none')] ?? null;
        $siteKey = trim((string) $this->cms->setting('forms', 'captcha_site_key', ''));
        $secret = trim((string) $this->cms->setting('forms', 'captcha_secret', ''));

        return $class === null || $siteKey === '' || $secret === '' ? null : new $class($this->cms->app()->make(Client::class), $siteKey, $secret);
    }

    /** What a built page carries of it: a change builds the site again. */
    public function signature(): string
    {
        return $this->current() === null ? 'none' : $this->cms->setting('forms', 'captcha').':'.trim((string) $this->cms->setting('forms', 'captcha_site_key', ''));
    }
}
