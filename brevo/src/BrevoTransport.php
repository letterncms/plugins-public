<?php

declare(strict_types=1);

namespace Cms\Brevo;

use Illuminate\Support\Facades\Http;
use Cms\Modules\Email\Transport;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Delivers through POST https://api.brevo.com/v3/smtp/email. */
final class BrevoTransport implements Transport
{
    public function __construct(private readonly string $apiKey) {}

    public function send(Email $email): void
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Brevo API key is missing.');
        }

        $address = static fn (Address $a) => array_filter(['email' => $a->getAddress(), 'name' => $a->getName() ?: null]);
        $from = $email->getFrom()[0];

        $payload = array_filter([
            'sender' => $address($from),
            'to' => array_map($address, $email->getTo()),
            'replyTo' => ($reply = $email->getReplyTo()[0] ?? null) ? $address($reply) : null,
            'subject' => $email->getSubject(),
            'htmlContent' => $email->getHtmlBody(),
            'textContent' => $email->getTextBody(),
            'tags' => ($tag = $email->getHeaders()->get('X-Cms-Tag')?->getBodyAsString()) ? [$tag] : null,
            'attachment' => array_map(static fn ($part) => ['name' => $part->getFilename() ?? 'attachment', 'content' => base64_encode($part->getBody())], $email->getAttachments()) ?: null,
        ]);

        $response = Http::withHeaders(['api-key' => $this->apiKey, 'accept' => 'application/json'])
            ->timeout(15)
            ->post('https://api.brevo.com/v3/smtp/email', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Brevo: '.($response->json('message') ?? $response->status()));
        }
    }
}
