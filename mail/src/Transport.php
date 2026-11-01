<?php

declare(strict_types=1);

namespace Cms\Mail;

use Symfony\Component\Mime\Email;

/** A way to deliver email (SMTP, an API…). Provider plugins implement this. */
interface Transport
{
    public function send(Email $email): void;
}
