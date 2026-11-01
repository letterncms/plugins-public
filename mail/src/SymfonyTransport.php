<?php

declare(strict_types=1);

namespace Cms\Mail;

use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

final class SymfonyTransport implements Transport
{
    public function __construct(private readonly TransportInterface $transport) {}

    public function send(Email $email): void
    {
        $this->transport->send($email);
    }
}
