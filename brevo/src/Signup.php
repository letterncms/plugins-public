<?php

declare(strict_types=1);

namespace Cms\Brevo;

use Illuminate\Support\Facades\RateLimiter;
use Cms\Admin\Translations;
use Cms\Cms;
use Cms\Modules\Email\Body;
use Cms\Modules\Email\Mailer;
use Cms\Modules\Email\Message;

/**
 * Newsletter signups. With double opt-in (the default) nothing reaches Brevo until the person
 * follows the link mailed to them: the link carries the address and the list, signed with the
 * site's key and good for two days, so no table holds people who never confirmed.
 */
final class Signup
{
    public const int HOURS = 48;

    /** Confirmation emails one address is sent in an hour, whoever asks for them. */
    public const int MAILS = 3;

    public function __construct(private readonly Cms $cms, private readonly Client $client) {}

    public function doubleOptIn(): bool
    {
        return (bool) $this->cms->setting('brevo', 'double_opt_in', true);
    }

    /** The list a signup goes to: the block's own, or the default from the settings. Null when neither is set. */
    public function list(mixed $asked = null): ?int
    {
        // A select's value is "#<id>" (see the `fields.options.brevo-lists` filter); an agent may give the number.
        $list = (int) ltrim((string) ($asked ?: $this->cms->setting('brevo', 'list', '')), '#');

        return $list > 0 ? $list : null;
    }

    /**
     * Where the CMS answers when the pages are hosted elsewhere: the address Forms posts to (its Submission
     * Address setting), read as a setting so there is one place to say it. Empty for the site's own address.
     */
    public function base(): string
    {
        return rtrim((string) $this->cms->setting('forms', 'endpoint', ''), '/');
    }

    /** What a page carries beside the list, so a visitor can't pick another list of the account. */
    public function seal(int $list): string
    {
        return substr($this->sign('list|'.$list), 0, 32);
    }

    public function sealed(int $list, string $seal): bool
    {
        return hash_equals($this->seal($list), $seal);
    }

    /** Take a signup: mail the confirmation link, or with double opt-in off, add the address right away. */
    public function request(string $email, int $list, string $locale): void
    {
        if (! $this->doubleOptIn()) {
            $this->client->subscribe($email, $list);
            $this->cms->hooks()->dispatch('brevo.subscribed', $email, $list);

            return;
        }

        // Nobody's inbox is filled from here: past a few an hour the signup is answered as ever, and no mail goes out.
        $limit = 'brevo-confirm|'.hash('sha256', $email);

        if (RateLimiter::tooManyAttempts($limit, self::MAILS)) {
            return;
        }

        RateLimiter::hit($limit, 3600);

        // The link leads to where the CMS answers: its own address when the pages are hosted elsewhere.
        $base = $this->base();
        $url = ($base !== '' ? $base.'/api/brevo/confirm' : $this->cms->url('/api/brevo/confirm')).'?token='.rawurlencode($this->token($email, $list, $locale));
        $site = (string) config('app.name');
        $subject = trim((string) $this->cms->setting('brevo', 'confirmation_subject', '')) ?: __('Confirm your subscription', [], $locale);
        $message = trim((string) $this->cms->setting('brevo', 'confirmation_message', '')) ?: __('Please confirm that you want to receive email from :site.', ['site' => $site], $locale);
        $body = Body::render(
            preg_split('/\R{2,}/', $message) ?: [$message],
            [__('Confirm Subscription', [], $locale), $url],
            __('If you didn’t ask for this, ignore this email: nothing is sent to you.', [], $locale),
        );

        $this->cms->app()->make(Mailer::class)->send(new Message(to: $email, subject: mb_substr($subject, 0, 200), html: $body['html'], text: $body['text'], tag: 'brevo-confirm'));
    }

    /**
     * Confirm with the token of a confirmation link: the address goes on its list.
     *
     * @return array{email: string, list: int, locale: string}|null Null for a link that was changed or is too old.
     */
    public function confirm(string $token): ?array
    {
        $claim = $this->read($token);

        if ($claim === null) {
            return null;
        }

        $this->client->subscribe($claim['email'], $claim['list']);
        $this->cms->hooks()->dispatch('brevo.subscribed', $claim['email'], $claim['list']);

        return $claim;
    }

    public function token(string $email, int $list, string $locale, ?int $expires = null): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode(['e' => $email, 'l' => $list, 'o' => $locale, 'x' => $expires ?? now()->addHours(self::HOURS)->getTimestamp()])), '+/', '-_'), '=');

        return $payload.'.'.$this->sign('token|'.$payload);
    }

    /** @return array{email: string, list: int, locale: string}|null */
    public function read(string $token): ?array
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');

        if ($payload === '' || ! hash_equals($this->sign('token|'.$payload), $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        if (! is_array($data) || (int) ($data['x'] ?? 0) < now()->getTimestamp() || ! filter_var($data['e'] ?? null, FILTER_VALIDATE_EMAIL) || (int) ($data['l'] ?? 0) < 1) {
            return null;
        }

        return ['email' => (string) $data['e'], 'list' => (int) $data['l'], 'locale' => (string) ($data['o'] ?? '')];
    }

    /** The language a visitor reads in: the page's, when the site or the CMS speaks it. */
    public function speak(string $asked): string
    {
        $translations = $this->cms->app()->make(Translations::class);
        $locale = in_array($asked, $this->cms->locales(), true) || isset($translations->languages()[$asked]) ? $asked : $translations->locale();
        $translations->apply($locale);

        return $locale;
    }

    private function sign(string $value): string
    {
        return hash_hmac('sha256', $value, 'brevo-signup|'.(string) config('app.key'));
    }
}
