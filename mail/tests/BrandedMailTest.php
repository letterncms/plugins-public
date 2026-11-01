<?php

declare(strict_types=1);

namespace Cms\Mail\Tests;

use Cms\Tests\TestCase;

final class BrandedMailTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/mail'];
    }

    public function test_an_erased_account_leaves_no_address_in_the_log(): void
    {
        $mailer = app(\Cms\Mail\CmsMailer::class);
        $user = cms()->accounts()->ensure('gone@example.com');
        $mailer->send(new \Cms\Contracts\MailMessage(to: 'gone@example.com', subject: 'Hello', text: 'Hi'));
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('mail_log')->where('to', 'gone@example.com')->count());

        cms()->accounts()->erase($user);
        $mailer->send(new \Cms\Contracts\MailMessage(to: 'gone@example.com', subject: 'Goodbye', text: 'Bye', ephemeral: true));

        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('mail_log')->where('to', 'gone@example.com')->count());
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('mail_log')->where('to', 'erased')->count());
    }
}
