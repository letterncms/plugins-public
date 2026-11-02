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

    public function test_sendmail_is_offered_only_where_the_host_lets_php_run_it(): void
    {
        $this->assertSame(function_exists('proc_open'), app(\Cms\Mail\Transports::class)->has('sendmail'));
    }

    public function test_a_site_set_to_a_transport_this_host_lacks_reads_as_not_sending(): void
    {
        // Such as sendmail where proc_open is off, or a provider whose plugin was removed.
        // Set the way a server's .env sets it, which wins over the saved setting.
        $before = [getenv('CMS_MAIL_TRANSPORT'), $_ENV['CMS_MAIL_TRANSPORT'] ?? null, $_SERVER['CMS_MAIL_TRANSPORT'] ?? null];
        putenv('CMS_MAIL_TRANSPORT=gone');
        $_ENV['CMS_MAIL_TRANSPORT'] = $_SERVER['CMS_MAIL_TRANSPORT'] = 'gone';
        (fn () => $this->cache = [])->call($this->cms()->settingsRegistry());

        try {
            $this->assertFalse(app(\Cms\Mail\CmsMailer::class)->configured());
        } finally {
            putenv($before[0] === false ? 'CMS_MAIL_TRANSPORT' : "CMS_MAIL_TRANSPORT={$before[0]}");
            unset($_ENV['CMS_MAIL_TRANSPORT'], $_SERVER['CMS_MAIL_TRANSPORT']);
            $before[1] === null || $_ENV['CMS_MAIL_TRANSPORT'] = $before[1];
            $before[2] === null || $_SERVER['CMS_MAIL_TRANSPORT'] = $before[2];
        }
    }
}
