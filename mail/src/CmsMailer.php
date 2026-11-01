<?php

declare(strict_types=1);

namespace Cms\Mail;

use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Cms\Contracts\MailMessage;
use Cms\Admin\Translations;
use Cms\Contracts\Mailer;
use Cms\Cms;
use Cms\Support\MailBody;
use Cms\Users\User;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/** The Mailer contract, delivered through the configured transport and logged. */
final class CmsMailer implements Mailer
{
    public function __construct(private readonly Cms $cms, private readonly Transports $transports, private readonly Factory $views) {}

    public function configured(): bool
    {
        $transport = (string) $this->cms->settings('mail', 'transport', 'log');

        return $transport !== 'log' && $this->transports->has($transport);
    }

    /** Never throws: failures are logged so a form or invite never breaks on mail problems. */
    public function send(MailMessage $message): void
    {
        try {
            $this->deliver($message);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Send and throw on failure (test mails, CLI). */
    public function deliver(MailMessage $message): void
    {
        $settings = (array) $this->cms->settings('mail');
        $key = (string) ($settings['transport'] ?? 'log');
        $email = $this->build($message, $settings);

        try {
            $this->transports->make($key, $settings)->send($email);
            $this->log($message, $key, 'sent');
        } catch (Throwable $e) {
            $this->log($message, $key, 'failed', $e->getMessage());
            throw $e;
        }
    }

    private function build(MailMessage $message, array $settings): Email
    {
        $from = new Address(
            $message->fromAddress ?? (string) ($settings['from_address'] ?? 'no-reply@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'example.com')),
            $message->fromName ?? (string) ($settings['from_name'] ?? config('app.name'))
        );

        $html = $message->html !== '' ? $this->layout($message, $settings) : '';

        $email = (new Email)
            ->from($from)
            ->to(...$message->recipients())
            ->subject($message->subject)
            ->text($message->text !== '' ? $message->text : trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>'], "\n", $message->html)))));

        if ($html !== '') {
            $email->html($html);
        }

        if ($message->replyTo) {
            $email->replyTo($message->replyTo);
        }

        foreach ($message->headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/', $name)) {
                $email->getHeaders()->addTextHeader($name, $value);
            }
        }

        if ($message->tag) {
            $email->getHeaders()->addTextHeader('X-Cms-Tag', $message->tag);
        }

        foreach ($message->attachments as $file) {
            $email->attach($file['content'], $file['name'], $file['mime']);
        }

        return $email;
    }

    /**
     * The message in the CMS's email layout, in the recipient's language: the account's
     * own when the address belongs to one, else the language the message was written in.
     */
    private function layout(MailMessage $message, array $settings): string
    {
        $translations = $this->cms->app()->make(Translations::class);
        $previous = $translations->current();
        $user = ($to = $message->recipients()[0] ?? null) ? User::query()->where('email', strtolower($to))->first() : null;
        $locale = $user ? $translations->locale($user) : $previous;

        $translations->apply($locale);

        try {
            return $this->views->make('mail::layout', ['body' => $message->html, 'site' => config('app.name'), 'url' => config('app.url'), 'footer' => (string) ($settings['footer'] ?? '') ?: __('Sent by :site', ['site' => config('app.name')]), 'locale' => $locale, 'colors' => MailBody::colors()])->render();
        } finally {
            $translations->apply($previous);
        }
    }

    private function log(MailMessage $message, string $transport, string $status, ?string $error = null): void
    {
        try {
            DB::table('mail_log')->insert([
                'id' => strtolower((string) Str::ulid()),
                'to' => $message->ephemeral ? 'erased' : mb_substr(implode(', ', $message->recipients()), 0, 1000),
                'subject' => mb_substr($message->subject, 0, 500),
                'tag' => $message->tag,
                'transport' => $transport,
                'status' => $status,
                'error' => $error ? mb_substr($error, 0, 2000) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Logging is best effort.
        }
    }
}
