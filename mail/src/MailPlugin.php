<?php

declare(strict_types=1);

namespace Cms\Mail;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Cms\Abilities\AbilityException;
use Cms\Contracts\MailMessage;
use Cms\Contracts\Mailer;
use Cms\Packages\Plugin;
use Throwable;

final class MailPlugin extends Plugin
{
    public function register(): void
    {
        $this->app->singleton(Transports::class);
        $this->app->singleton(CmsMailer::class);
        $this->app->singleton(Mailer::class, CmsMailer::class);
    }

    public function boot(): void
    {
        // Registered once every plugin had the chance to add a transport.
        $this->app->booted(fn () => $this->settingsSchema());
        $this->abilities();
        // Someone's erased account takes their row in the log with it.
        $this->cms()->hooks()->on('account.deleting', static fn ($user) => DB::table('mail_log')->where('to', $user->email)->delete());
        $this->cms()->admin()->widget('mail-status', ['label' => 'Email', 'description' => __('How email is sent, whether it arrives, and the latest messages.'), 'icon' => 'mail', 'column' => 'side', 'order' => 60, 'permission' => 'settings.manage']);
        $this->adminModule();
    }

    private function settingsSchema(): void
    {
        $transports = $this->app->make(Transports::class)->all();
        $fields = [
            ['handle' => 'transport', 'type' => 'select', 'label' => 'Send With', 'options' => array_map(static fn ($t) => $t['label'], $transports), 'default' => 'log', 'display' => 'dropdown'],
            ['handle' => 'from_address', 'type' => 'email', 'label' => 'From Address', 'width' => 'half'],
            ['handle' => 'from_name', 'type' => 'text', 'label' => 'From Name', 'width' => 'half'],
        ];

        foreach ($transports as $key => $transport) {
            array_push($fields, ...array_map(static fn ($f) => [...$f, 'if' => ['transport' => $key]], $transport['fields']));
        }

        $fields[] = ['handle' => 'footer', 'type' => 'text', 'label' => 'Email Footer', 'instructions' => 'Shown under every email, like your address.'];

        $this->cms()->settingsRegistry()->register('mail', fields: $fields, icon: 'mail', description: __('How :brand sends email.'), package: $this->manifest->name, order: 40);
    }

    private function abilities(): void
    {
        $a = $this->cms()->abilities();

        $a->register('mail/test', [
            'label' => 'Send Test Email',
            'description' => 'Send a test message with the current email settings.',
            'permission' => 'settings.manage',
            'expose' => ['mcp' => false],
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['to' => ['type' => 'string', 'format' => 'email']], 'required' => ['to']],
            'handler' => function (array $in) {
                try {
                    $this->app->make(CmsMailer::class)->deliver(new MailMessage(
                        to: $in['to'],
                        subject: __('Test email from :site', ['site' => config('app.name')]),
                        html: '<p>'.e(__('This is a test from :site. If you can read it, email works.', ['site' => config('app.name')])).'</p>',
                        tag: 'test',
                    ));
                } catch (Throwable $e) {
                    throw new AbilityException(__('Sending failed: :error', ['error' => $e->getMessage()]), 'failed');
                }

                return ['sent' => true, 'transport' => $this->cms()->settings('mail', 'transport', 'log')];
            },
        ]);

        $a->register('mail/status', [
            'label' => 'Email Status',
            'description' => 'The transport email is sent with, whether it really delivers (log only does not), sent and failed counts for the last 7 days and the latest emails.',
            'permission' => 'settings.manage',
            'annotations' => ['readOnly' => true],
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 5]]],
            'handler' => function (array $in) {
                $key = (string) $this->cms()->settings('mail', 'transport', 'log');
                $transports = $this->app->make(Transports::class);
                $counts = DB::table('mail_log')->where('created_at', '>=', now()->subDays(7))->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

                return [
                    'transport' => ['key' => $key, 'label' => $transports->all()[$key]['label'] ?? $key, 'delivers' => $this->app->make(CmsMailer::class)->configured()],
                    'from' => (string) ($this->cms()->settings('mail', 'from_address') ?: 'no-reply@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'example.com')),
                    'sent' => (int) ($counts['sent'] ?? 0),
                    'failed' => (int) ($counts['failed'] ?? 0),
                    'latest' => DB::table('mail_log')->orderByDesc('created_at')->limit($in['limit'])->get(['id', 'to', 'subject', 'tag', 'transport', 'status', 'error', 'created_at'])
                        ->map(static fn ($r) => ['id' => $r->id, 'to' => $r->to, 'subject' => $r->subject, 'tag' => $r->tag, 'transport' => $r->transport, 'status' => $r->status, 'error' => $r->error, 'createdAt' => $r->created_at ? Carbon::parse($r->created_at)->toIso8601String() : null])->all(),
                ];
            },
        ]);

        $a->register('mail/log', [
            'label' => 'Email Log',
            'description' => 'Recently sent and failed emails.',
            'permission' => 'settings.manage',
            'annotations' => ['readOnly' => true],
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50]]],
            'handler' => fn (array $in) => DB::table('mail_log')->orderByDesc('created_at')->limit($in['limit'])->get()->all(),
        ]);
    }
}
