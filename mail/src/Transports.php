<?php

declare(strict_types=1);

namespace Cms\Mail;

use Closure;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * Available delivery methods. Plugins add providers:
 *   app(Transports::class)->register('brevo', 'Brevo', [...settings fields], fn (array $settings) => new BrevoTransport(...));
 */
final class Transports
{
    /** @var array<string, array{label: string, fields: list<array>, factory: Closure}> */
    private array $transports = [];

    public function __construct()
    {
        $this->register('smtp', 'SMTP', [
            ['handle' => 'smtp_host', 'type' => 'text', 'label' => 'SMTP Host', 'width' => 'half'],
            ['handle' => 'smtp_port', 'type' => 'number', 'label' => 'Port', 'default' => 587, 'width' => 'half'],
            ['handle' => 'smtp_encryption', 'type' => 'select', 'label' => 'Encryption', 'options' => ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => __('None')], 'default' => 'tls', 'width' => 'half'],
            ['handle' => 'smtp_username', 'type' => 'text', 'label' => 'Username', 'width' => 'half'],
            ['handle' => 'smtp_password', 'type' => 'text', 'label' => 'Password', 'secret' => true],
        ], static function (array $s): Transport {
            $smtp = new EsmtpTransport((string) $s['smtp_host'], (int) ($s['smtp_port'] ?? 587), ($s['smtp_encryption'] ?? 'tls') === 'ssl');

            if (($s['smtp_encryption'] ?? 'tls') === 'none') {
                $smtp->setAutoTls(false);
            }

            if (! empty($s['smtp_username'])) {
                $smtp->setUsername((string) $s['smtp_username']);
                $smtp->setPassword((string) ($s['smtp_password'] ?? ''));
            }

            return new SymfonyTransport($smtp);
        });

        $this->register('sendmail', __('Server mail (sendmail)'), [], static fn () => new SymfonyTransport(new SendmailTransport));

        $this->register('log', __('Log only (development)'), [], static fn () => new class implements Transport
        {
            public function send(Email $email): void
            {
                Log::info('Mail (log transport)', ['to' => array_map(static fn ($a) => $a->getAddress(), $email->getTo()), 'subject' => $email->getSubject(), 'text' => $email->getTextBody()]);
            }
        });
    }

    public function register(string $key, string $label, array $fields, Closure $factory): void
    {
        $this->transports[$key] = compact('label', 'fields', 'factory');
    }

    public function has(string $key): bool
    {
        return isset($this->transports[$key]);
    }

    /** @return array<string, array{label: string, fields: list<array>, factory: Closure}> */
    public function all(): array
    {
        return $this->transports;
    }

    public function make(string $key, array $settings): Transport
    {
        $transport = $this->transports[$key] ?? $this->transports['log'];

        return ($transport['factory'])($settings);
    }
}
