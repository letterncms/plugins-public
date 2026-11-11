<?php

declare(strict_types=1);

namespace Cms\Forms;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Cms\Admin\Translations;
use Cms\Modules\Email\Message;
use Cms\Modules\Email\Mailer;
use Cms\Modules\Email\Body;
use Cms\Cms;
use Cms\Support\DailySalt;
use Cms\Support\Files;
use Cms\Support\SafeFetcher;
use RuntimeException;
use Throwable;

/**
 * Validates and processes a submission: spam checks, server-side validation of
 * every visible field, storage, notifications, confirmation and webhook.
 */
final class SubmissionHandler
{
    private const array FILE_TYPES = [
        'documents' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.oasis.opendocument.text', 'text/plain'],
        'images' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
    ];

    public function __construct(private readonly Cms $cms, private readonly FormRepository $forms, private readonly Captcha\Captchas $captchas) {}

    /**
     * @return array{status: 'ok'|'spam'|'invalid', errors: array<string, string>, submission: ?Submission, captcha?: 'failed'|'unavailable'}
     */
    public function handle(array $form, Request $request): array
    {
        $s = $form['settings'];
        $input = $request->all();

        // Bots: honeypot filled, or submitted faster than a person could.
        $tooFast = isset($input['_cms_t']) && is_numeric($input['_cms_t']) && (int) $input['_cms_t'] > 0 && (time() * 1000 - (int) $input['_cms_t']) < $s['min_seconds'] * 1000;

        if (($s['honeypot'] && ! empty($input['_cms_website'])) || $tooFast) {
            return ['status' => 'spam', 'errors' => [], 'submission' => null];
        }

        [$data, $files, $errors] = $this->validate($form, $request);

        if ($errors !== []) {
            return ['status' => 'invalid', 'errors' => $errors, 'submission' => null];
        }

        // After the fields, so a token isn't spent on a submission that comes back anyway. The visitor is told:
        // unlike a bot in the trap, a person who failed the check can do it again.
        $captcha = $this->captchas->current();

        if ($captcha !== null) {
            $token = $request->input($captcha->field());

            if (! is_string($token) || $token === '' || strlen($token) > $captcha->maxToken()) {
                return ['status' => 'invalid', 'errors' => [], 'submission' => null, 'captcha' => 'failed'];
            }

            $vouched = $captcha->verify($token);

            // The provider is down or answered nonsense. Nothing gets through, the visitor hears it isn't them, and the site's log says so.
            if ($vouched === null) {
                report(new RuntimeException('Forms: '.$captcha->global().' could not be asked about a token, so the submission to “'.$form['handle'].'” was refused.'));

                return ['status' => 'invalid', 'errors' => [], 'submission' => null, 'captcha' => 'unavailable'];
            }

            if (! $vouched) {
                return ['status' => 'invalid', 'errors' => [], 'submission' => null, 'captcha' => 'failed'];
            }
        }

        $data = $this->cms->hooks()->filter('form.data', $data, $form, $request);
        $submission = null;

        if ($s['store']) {
            $submission = Submission::query()->create([
                'form' => $form['handle'],
                'data' => $data,
                'files' => [],
                'meta' => [
                    'page' => mb_substr((string) $request->headers->get('referer', ''), 0, 500),
                    'locale' => $request->getPreferredLanguage(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
                    // Tells apart submissions of one day, for spotting a flood. A new day can't be linked to the last.
                    'ip_hash' => hash_hmac('sha256', (string) $request->ip(), DailySalt::today('forms')),
                ],
                'status' => 'new',
            ]);

            $submission->files = $this->storeFiles($submission->id, $files);
            $submission->save();
        }

        $this->notify($form, $data, $files);
        $this->confirm($form, $data);
        $this->webhook($form, $data, $submission);
        $this->cms->hooks()->dispatch('form.submitted', $form, $data, $submission);

        return ['status' => 'ok', 'errors' => [], 'submission' => $submission];
    }

    /** @return array{0: array, 1: array<string, UploadedFile>, 2: array<string, string>} */
    private function validate(array $form, Request $request): array
    {
        $data = [];
        $files = [];
        $errors = [];
        $raw = $request->all();

        foreach (FormFields::extract($form['fields']) as $field) {
            if (! FormFields::visible($field, $raw)) {
                continue;
            }

            $name = $field['name'];
            $p = $field['props'];
            $label = strip_tags((string) ($p['label'] ?? $name));
            $required = ! empty($p['required']) || $field['type'] === 'consent';

            if ($field['type'] === 'file') {
                $file = $request->file($name);

                if (! $file instanceof UploadedFile) {
                    $required && $errors[$name] = __(':field is required.', ['field' => $label]);
                    continue;
                }

                $allowed = match ($p['accept'] ?? 'documents') {
                    'images' => self::FILE_TYPES['images'],
                    'any' => [...self::FILE_TYPES['documents'], ...self::FILE_TYPES['images']],
                    default => self::FILE_TYPES['documents'],
                };
                $mime = (string) $file->getMimeType();

                if (! $file->isValid() || ! in_array($mime, $allowed, true)) {
                    $errors[$name] = __('This file type is not accepted.');
                } elseif ($file->getSize() > (int) ($p['max_mb'] ?? 10) * 1024 * 1024) {
                    $errors[$name] = __('The file is too large (:size MB at most).', ['size' => (int) ($p['max_mb'] ?? 10)]);
                } else {
                    $files[$name] = $file;
                    $data[$name] = $file->getClientOriginalName();
                }

                continue;
            }

            $value = $raw[$name] ?? null;

            if ($field['type'] === 'checkboxes') {
                $value = array_values(array_filter((array) $value, 'is_string'));
                $options = array_keys(FormFields::options($p));
                $value = array_values(array_intersect($value, array_map('strval', $options)));
            } elseif ($field['type'] === 'tags') {
                // Tags arrive one by one (name[]), or comma-separated when the page ran no script.
                $words = is_array($value) ? array_filter($value, 'is_string') : explode(',', is_string($value) ? $value : '');
                $value = [];

                foreach ($words as $word) {
                    $word = mb_substr(trim($word), 0, 40);

                    if ($word !== '' && ! in_array(mb_strtolower($word), array_map('mb_strtolower', $value), true)) {
                        $value[] = $word;
                    }
                }

                if (count($value) > max(1, (int) ($p['max'] ?? 10))) {
                    $errors[$name] = __('Choose :count at most.', ['count' => max(1, (int) ($p['max'] ?? 10))]);
                    continue;
                }
            } elseif (is_array($value)) {
                $value = null;
            } elseif (is_string($value)) {
                $value = trim($value);
            }

            if ($field['type'] === 'hidden') {
                $data[$name] = (string) ($p['value'] ?? '');
                continue;
            }

            $empty = $value === null || $value === '' || $value === [];

            if ($empty) {
                if ($required) {
                    $errors[$name] = $field['type'] === 'consent' ? __('Please confirm to continue.') : __(':field is required.', ['field' => $label]);
                }

                continue;
            }

            $error = match ($field['type']) {
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : __('Enter a valid email address.'),
                'url' => preg_match('#^https?://[^\s]+\.[^\s]+$#i', (string) $value) ? null : __('Enter a full web address (https://…).'),
                'tel' => preg_match('/^[+()0-9\s.\-]{5,30}$/', (string) $value) ? null : __('Enter a valid phone number.'),
                'range' => ! is_numeric($value) || (float) $value < (float) ($p['min'] ?? 0) || (float) $value > (float) ($p['max'] ?? 100) ? __('This number is out of range.') : null,
                'number' => ! is_numeric($value) ? __('Enter a number.') : ((isset($p['min']) && (float) $value < (float) $p['min']) || (isset($p['max']) && (float) $value > (float) $p['max']) ? __('This number is out of range.') : null),
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) && strtotime((string) $value) ? null : __('Enter a valid date.'),
                'select', 'radio' => array_key_exists((string) $value, FormFields::options($p)) ? null : __('Choose one of the options.'),
                default => null,
            };

            $max = (int) ($p['max'] ?? ($field['type'] === 'textarea' ? 5000 : 500));

            if ($error === null && is_string($value) && mb_strlen($value) > $max) {
                $error = __('Keep it under :count characters.', ['count' => $max]);
            }

            if ($error !== null) {
                $errors[$name] = $error;
                continue;
            }

            $data[$name] = in_array($field['type'], ['checkbox', 'consent'], true) ? 'yes' : $value;
        }

        return [$data, $files, $errors];
    }

    /** @param array<string, UploadedFile> $files */
    private function storeFiles(string $id, array $files): array
    {
        $out = [];

        foreach ($files as $field => $file) {
            $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $file->getClientOriginalName()) ?: 'file';
            $relative = "forms/{$id}/".bin2hex(random_bytes(6)).'-'.mb_substr($name, -100);
            $target = Files::within(storage_path('app'), $relative);
            @mkdir(dirname($target), 0o770, true);
            $file->move(dirname($target), basename($target));
            $out[] = ['field' => $field, 'name' => $file->getClientOriginalName(), 'path' => $relative, 'size' => filesize($target), 'mime' => $file->getClientMimeType()];
        }

        return $out;
    }

    private function notify(array $form, array $data, array $files): void
    {
        $to = (array) $form['settings']['notify'];

        if ($to === []) {
            return;
        }

        // In the site's mail colours, like every other message it sends.
        $c = Body::colors();
        $rows = '';
        $text = [];

        foreach (FormFields::extract($form['fields']) as $field) {
            if (array_key_exists($field['name'], $data)) {
                $label = strip_tags((string) ($field['props']['label'] ?? $field['name']));
                $value = is_array($data[$field['name']]) ? implode(', ', $data[$field['name']]) : (string) $data[$field['name']];
                $rows .= '<tr><td style="padding:6px 12px 6px 0;color:'.e($c['muted']).';vertical-align:top;white-space:nowrap">'.e($label).'</td><td style="padding:6px 0;color:'.e($c['text']).';white-space:pre-wrap">'.e($value).'</td></tr>';
                $text[] = $label.': '.$value;
            }
        }

        $replyField = (string) $form['settings']['reply_to_field'];
        $replyTo = isset($data[$replyField]) && filter_var($data[$replyField], FILTER_VALIDATE_EMAIL) ? $data[$replyField] : null;
        $subject = strtr((string) $form['settings']['subject'], ['{form}' => $form['title']] + array_map(static fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v, array_combine(array_map(static fn ($k) => '{'.$k.'}', array_keys($data)), $data) ?: []));

        // Written for the people who run the site: in the admin's default language.
        $locale = $this->cms->app()->make(Translations::class)->locale();

        $note = $files ? (count($files) === 1 ? __('1 file is in the admin.', [], $locale) : __(':count files are in the admin.', ['count' => count($files)], $locale)) : null;
        app(Mailer::class)->send(new Message(
            to: $to,
            subject: mb_substr($subject, 0, 200),
            html: '<p style="margin:0 0 16px;color:'.e($c['text']).'"><strong>'.e($form['title']).'</strong></p><table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">'.$rows.'</table>'.($note !== null ? '<p style="margin:16px 0 0;color:'.e($c['muted']).'">'.e($note).'</p>' : ''),
            text: implode("\n\n", array_filter([$form['title'], implode("\n", $text), $note])),
            replyTo: $replyTo,
            tag: 'form:'.$form['handle'],
        ));
    }

    private function confirm(array $form, array $data): void
    {
        $s = $form['settings'];
        $email = $data[$s['reply_to_field']] ?? null;

        if (! $s['confirmation'] || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        app(Mailer::class)->send(new Message(
            to: $email,
            subject: (string) $s['confirmation_subject'],
            html: \Cms\Support\Html\Sanitizer::clean((string) $s['confirmation_message']),
            tag: 'form-confirmation:'.$form['handle'],
        ));
    }

    private function webhook(array $form, array $data, ?Submission $submission): void
    {
        $url = (string) ($form['settings']['webhook'] ?? '');

        if ($url === '') {
            return;
        }

        try {
            // Public addresses only, checked after DNS and pinned for the request, and no redirects.
            SafeFetcher::postJson($url, ['form' => $form['handle'], 'id' => $submission?->id, 'data' => $data, 'submittedAt' => now()->toIso8601String()], 8);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
