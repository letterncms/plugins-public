<?php

declare(strict_types=1);

namespace Cms\Forms;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Cms\Abilities\AbilityException;
use Cms\Modules\Blocks\BlockRegistry;
use Cms\Forms\Http\FileController;
use Cms\Forms\Http\SubmitController;
use Cms\Modules\Site\Build\SiteBuilder;
use Cms\Modules\Workspace\Answers;
use Cms\Packages\Plugin;
use Throwable;

final class FormsPlugin extends Plugin
{
    public function register(): void
    {
        foreach ([FormRepository::class, FormRenderer::class, SubmissionHandler::class, Captcha\Captchas::class] as $service) {
            $this->app->singleton($service);
        }

        $this->app->bindIf(Captcha\Client::class, Captcha\HttpClient::class);
    }

    public function boot(): void
    {
        $p = $this->cms()->permissions();
        $p->register('forms.view', 'View Form Submissions', 'Forms', roles: ['editor']);
        $p->register('forms.manage', 'Manage Forms', 'Forms', roles: ['editor']);

        $this->blocks();
        $this->routes();
        $this->settingsSchema();
        $this->abilities();

        // The pages showing a form recorded it while they were built: only those are built again, when the site builds on its own.
        $this->cms()->hooks()->on('form.saved', function (string $handle) {
            if ($this->cms()->isActive('letterncms/site') && $this->cms()->setting('site', 'auto_build', true)) {
                $this->app->make(SiteBuilder::class)->queueForDependency('form:'.$handle, __('A form changed'));
            }
        });

        // The widget and its site key are in the built pages: another provider or key builds them again.
        $this->cms()->hooks()->addFilter('site.build.signature', fn (array $parts) => [...$parts, 'forms-captcha' => $this->app->make(Captcha\Captchas::class)->signature()]);

        $this->cms()->admin()->nav('forms', ['path' => '/forms', 'icon' => 'form', 'section' => 'content', 'order' => 75, 'pinned' => true, 'permission' => 'forms.view', 'badge' => fn () => Submission::query()->where('status', 'new')->count() ?: null]);
        $this->cms()->admin()->widget('forms-submissions', ['label' => 'Form Submissions', 'description' => __('New submissions over the last two weeks and the latest ones.'), 'icon' => 'form', 'column' => 'side', 'order' => 40, 'permission' => 'forms.view']);
        $this->app->make(FormsViews::class)->register();
        $this->adminModule(import: '@letterncms/forms');

        $this->cms()->context()->register('cms://forms', 'Forms', 'Forms on this site and their fields. Add one to a page with the forms/form block ({"form": "<handle>"}).', fn () => array_values(array_map(static fn ($f) => ['handle' => $f['handle'], 'title' => $f['title'], 'fields' => array_map(static fn ($x) => ['name' => $x['name'], 'type' => $x['type'], 'label' => $x['props']['label'] ?? null], FormFields::extract($f['fields']))], $this->app->make(FormRepository::class)->all())));
    }

    /** A form as saved over what it was: what the input leaves out stays, settings given are merged over its settings. */
    private static function merged(?array $form, array $in): array
    {
        if ($form === null) {
            return $in;
        }

        return [
            'title' => $in['title'] ?? $form['title'],
            'fields' => $in['fields'] ?? $form['fields'],
            'settings' => [...$form['settings'], ...(array) ($in['settings'] ?? [])],
            'locales' => $in['locales'] ?? $form['locales'],
        ];
    }

    /** The first answer worth showing for a submission: a text-ish field first, any short value otherwise. */
    private static function summary(array $fields, array $data): string
    {
        $text = ['text', 'email', 'textarea', 'tel', 'url'];
        usort($fields, static fn ($a, $b) => (int) ! in_array($a['type'], $text, true) <=> (int) ! in_array($b['type'], $text, true));

        foreach ([...array_column($fields, 'name'), ...array_keys($data)] as $name) {
            $value = $data[$name] ?? null;
            $value = is_array($value) ? implode(', ', array_filter($value, 'is_scalar')) : $value;

            if (is_scalar($value) && ! is_bool($value) && trim((string) $value) !== '') {
                return mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string) $value)), 0, 90, '…');
            }
        }

        return '';
    }

    private function blocks(): void
    {
        $registry = $this->app->make(BlockRegistry::class);

        foreach (FormFields::blocks() as $block) {
            $registry->register($block);
        }

        $registry->register([
            'name' => 'forms/form',
            'title' => __('Form'),
            'icon' => 'form',
            'category' => 'form',
            'description' => __('Shows one of your forms'),
            'keywords' => ['contact', 'form', 'signup', 'newsletter', 'quote'],
            'styles' => ['spacing', 'size', 'background', 'border'],
            // With no forms yet, its list says so and offers to make one.
            'fields' => [['handle' => 'form', 'type' => 'select', 'label' => 'Form', 'placeholder' => 'Choose a Form', 'options' => [], 'dynamic' => 'forms', 'required' => true,
                'empty' => 'No forms yet', 'action' => ['label' => 'New Form', 'icon' => 'plus', 'to' => '/forms?do=new-form']]],
            'render' => FieldView::form(...),
        ]);

        $this->cms()->hooks()->addFilter('fields.options.forms', fn (array $options) => [...$options, ...array_map(static fn ($f) => $f['title'], $this->app->make(FormRepository::class)->all())]);
    }

    private function routes(): void
    {
        RateLimiter::for('cms-forms', static fn (Request $r) => [Limit::perMinute(6)->by($r->ip().'|'.$r->route('handle')), Limit::perHour(60)->by($r->ip())]);

        Route::middleware(['throttle:cms-forms'])->post('/api/forms/{handle}', [SubmitController::class, 'submit'])->where('handle', '[a-z0-9][a-z0-9_-]{0,63}');
        Route::options('/api/forms/{handle}', [SubmitController::class, 'options'])->where('handle', '[a-z0-9][a-z0-9_-]{0,63}');
        Route::middleware(['web', 'auth:web', 'cms.admin'])->get('/api/admin/forms/files/{id}/{index}', [FileController::class, 'download'])->whereNumber('index');
        Route::middleware(['web', 'auth:web', 'cms.admin'])->get('/api/admin/forms/{handle}/export', [FileController::class, 'export'])->where('handle', '[a-z0-9][a-z0-9_-]{0,63}');
    }

    private function settingsSchema(): void
    {
        $this->cms()->settingsRegistry()->register('forms', fields: [
            ['handle' => 'endpoint', 'type' => 'url', 'label' => 'Submission Address', 'placeholder' => 'https://cms.example.com'],
            ['handle' => 'origins', 'type' => 'tags', 'label' => 'Other Allowed Websites', 'placeholder' => 'https://example.com'],
            ['handle' => 'retention_days', 'type' => 'number', 'label' => 'Keep Submissions For', 'suffix' => 'days', 'min' => 0, 'placeholder' => 'Forever'],
            ['handle' => 'captcha', 'type' => 'select', 'label' => 'CAPTCHA', 'default' => 'none', 'required' => true, 'options' => ['none' => __('None'), 'turnstile' => 'Cloudflare Turnstile', 'hcaptcha' => 'hCaptcha']],
            ['handle' => 'captcha_site_key', 'type' => 'text', 'label' => 'Site Key', 'width' => 'half', 'mono' => true, 'if' => ['captcha' => ['turnstile', 'hcaptcha']]],
            ['handle' => 'captcha_secret', 'type' => 'text', 'label' => 'Secret Key', 'width' => 'half', 'secret' => true, 'if' => ['captcha' => ['turnstile', 'hcaptcha']]],
        ], icon: 'form', description: __('Where submissions go and how long they stay.'), permission: 'forms.manage', order: 50, home: 'forms');
    }

    private function abilities(): void
    {
        $a = $this->cms()->abilities();
        $forms = fn () => $this->app->make(FormRepository::class);
        $guard = static function (callable $fn) {
            try {
                return $fn();
            } catch (\InvalidArgumentException $e) {
                throw AbilityException::invalid($e->getMessage());
            }
        };

        $a->register('forms/list', [
            'label' => 'List Forms',
            'description' => 'Forms with their field counts and new submissions.',
            'permission' => 'forms.view',
            'annotations' => ['readOnly' => true],
            'handler' => function () use ($forms) {
                $counts = Submission::query()->selectRaw('form, status, count(*) as n')->groupBy('form', 'status')->get()->groupBy('form');

                return array_values(array_map(static fn ($f) => [
                    'handle' => $f['handle'],
                    'publishedAt' => $f['publishedAt'], 'updatedAt' => $f['updatedAt'],
                    'title' => $f['title'],
                    // Drawn before its name in the list.
                    'icon' => 'form',
                    'fields' => count(FormFields::extract($f['fields'])),
                    'enabled' => (bool) $f['settings']['enabled'],
                    'new' => $new = (int) ($counts[$f['handle']] ?? collect())->where('status', 'new')->sum('n'),
                    // For a list: nothing unread is no number at all.
                    'unread' => $new ?: null,
                    'total' => (int) ($counts[$f['handle']] ?? collect())->where('status', '!=', 'spam')->sum('n'),
                ], $forms()->all()));
            },
        ]);

        $a->register('forms/activity', [
            'label' => 'Form Activity',
            'description' => 'Number of forms, unread submissions across all forms, submissions per day for the last 14 days (oldest first) and the latest submissions with a one-line summary.',
            'permission' => 'forms.view',
            'annotations' => ['readOnly' => true],
            'input' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 5]]],
            'handler' => function (array $in) use ($forms) {
                $since = now()->startOfDay()->subDays(13);
                $perDay = Submission::query()->where('created_at', '>=', $since)->where('status', '!=', 'spam')
                    ->selectRaw('date(created_at) as day, count(*) as n')->groupBy('day')->pluck('n', 'day');
                $days = [];

                for ($i = 0; $i < 14; $i++) {
                    $days[] = (int) ($perDay[$since->copy()->addDays($i)->toDateString()] ?? 0);
                }

                $all = $forms()->all();
                $latest = Submission::query()->where('status', '!=', 'spam')->latest()->limit($in['limit'])->get();

                return [
                    'forms' => count($all),
                    'new' => Submission::query()->where('status', 'new')->count(),
                    'days' => $days,
                    'latest' => $latest->map(static fn (Submission $s) => [
                        'id' => $s->id,
                        'form' => $s->form,
                        'formTitle' => $all[$s->form]['title'] ?? $s->form,
                        'summary' => self::summary(FormFields::extract($all[$s->form]['fields'] ?? []), (array) $s->data),
                        'status' => $s->status,
                        'createdAt' => $s->created_at?->toIso8601String(),
                    ])->all(),
                ];
            },
        ]);

        $a->register('forms/get', [
            'label' => 'Get Form',
            'description' => 'A form definition: fields (blocks) and settings.',
            'permission' => 'forms.view',
            'annotations' => ['readOnly' => true],
            'input' => ['type' => 'object', 'properties' => ['handle' => ['type' => 'string']], 'required' => ['handle']],
            'handler' => fn (array $in) => $forms()->find($in['handle']) ?? throw new AbilityException(__('Form not found.'), 'not_found'),
        ]);

        $a->register('forms/create', [
            'label' => 'Create Form',
            'description' => 'A new form named title, with name, email and message fields to start from. Its handle is made from the title.',
            'permission' => 'forms.manage',
            'input' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120]], 'required' => ['title']],
            // What it starts with is a form to change: open it to, or take it back.
            'answer' => static fn (array $form) => Answers::done(__('Created :name', ['name' => $form['title']]), null, [
                ['label' => __('Open'), 'icon' => 'arrow-right', 'variant' => 'primary', 'to' => '/forms/'.$form['handle']],
                ['label' => __('Delete'), 'icon' => 'trash', 'variant' => 'ghost', 'ability' => 'forms/delete', 'input' => ['handle' => $form['handle']], 'success' => __('Form deleted'),
                    'confirm' => ['title' => Answers::literal(__('Delete “:title”?', ['title' => $form['title']])), 'confirm' => __('Delete'), 'danger' => true]],
            ]),
            'handler' => function (array $in) use ($forms) {
                $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(\Illuminate\Support\Str::ascii($in['title']))), '-') ?: 'form';
                $base = substr($base, 0, 60);
                $handle = $base;

                for ($i = 2; $forms()->find($handle) !== null; $i++) {
                    $handle = "{$base}-{$i}";
                }

                return $forms()->save($handle, ['title' => trim($in['title']), 'fields' => [
                    ['type' => 'form/text', 'props' => ['label' => __('Name'), 'name' => 'name', 'required' => true, 'autocomplete' => 'name']],
                    ['type' => 'form/email', 'props' => ['label' => __('Email'), 'name' => 'email', 'required' => true]],
                    ['type' => 'form/textarea', 'props' => ['label' => __('Message'), 'name' => 'message', 'required' => true]],
                ]]);
            },
        ]);

        $a->register('forms/save', [
            'label' => 'Save Form',
            'description' => 'Create or update a form. fields is a block tree using form/* blocks (form/text, form/email, form/textarea, form/select, form/radio, form/checkboxes, form/checkbox, form/consent, form/file, form/number, form/date, form/tel, form/url, form/hidden) and layout blocks; each field has props label, name, required. On an existing form, what is left out stays as it is; settings given are merged over its settings.',
            'permission' => 'forms.manage',
            'input' => ['type' => 'object', 'properties' => [
                'handle' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_-]{0,63}$'],
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'fields' => ['type' => 'array'],
                'settings' => ['type' => 'object'],
                'locales' => ['type' => 'object', 'description' => 'The form in other languages, per language code: {settings: {submit, success, confirmation_subject, confirmation_message}, fields: {<field name>: {label, help, placeholder, options, text}}}. Options keep their values: value|Label.'],
            ], 'required' => ['handle']],
            'handler' => fn (array $in) => $guard(fn () => $forms()->update($in['handle'], static fn (?array $form) => self::merged($form, $in))),
        ]);

        $a->register('forms/configure', [
            'label' => 'Configure Form',
            'description' => 'Change some of a form’s settings (submit, success, redirect, notify, subject, reply_to_field, store, confirmation, confirmation_subject, confirmation_message, honeypot, min_seconds, webhook, enabled); the rest stay. Returns its settings.',
            'permission' => 'forms.manage',
            'input' => ['type' => 'object', 'properties' => ['handle' => ['type' => 'string'], 'settings' => ['type' => 'object']], 'required' => ['handle', 'settings']],
            'handler' => function (array $in) use ($forms, $guard) {
                $forms()->find($in['handle']) ?? throw new AbilityException(__('Form not found.'), 'not_found');

                return $guard(fn () => $forms()->update($in['handle'], static fn (?array $form) => self::merged($form, ['settings' => $in['settings']])))['settings'];
            },
        ]);

        $a->register('forms/words', [
            'label' => 'Form Wording',
            'description' => 'What a form says to visitors in each of the site’s other languages: the fields to fill in, and their values, one per "<language>:s:<setting>" and "<language>:f:<field name>:<property>".',
            'permission' => 'forms.view',
            'annotations' => ['readOnly' => true],
            'expose' => ['mcp' => false],
            'input' => ['type' => 'object', 'properties' => ['handle' => ['type' => 'string']], 'required' => ['handle']],
            'handler' => function (array $in) use ($forms) {
                $form = $forms()->find($in['handle']) ?? throw new AbilityException(__('Form not found.'), 'not_found');

                return ['fields' => $this->app->make(FormsViews::class)->wordFields($form), 'values' => (object) FormsViews::words($form['locales'])];
            },
        ]);

        $a->register('forms/translate', [
            'label' => 'Translate Form',
            'description' => 'Store what a form says in other languages, as forms/words returns it.',
            'permission' => 'forms.manage',
            'expose' => ['mcp' => false],
            'input' => ['type' => 'object', 'properties' => ['handle' => ['type' => 'string'], 'words' => ['type' => 'object']], 'required' => ['handle', 'words']],
            'handler' => function (array $in) use ($forms, $guard) {
                $forms()->find($in['handle']) ?? throw new AbilityException(__('Form not found.'), 'not_found');
                $stored = $guard(fn () => $forms()->update($in['handle'], static fn (?array $form) => self::merged($form, ['locales' => FormsViews::locales((array) $in['words'], $form['locales'] ?? [])])));

                return (object) FormsViews::words($stored['locales']);
            },
        ]);

        $a->register('forms/delete', [
            'label' => 'Delete Form',
            'description' => 'Delete a form definition (submissions are kept).',
            'permission' => 'forms.manage',
            'annotations' => ['destructive' => true],
            'input' => ['type' => 'object', 'properties' => ['handle' => ['type' => 'string']], 'required' => ['handle']],
            // Deleting never refuses: a form that is not there is nothing to remove.
            'dryRun' => fn (array $in): array => ($form = $forms()->find($in['handle'])) === null ? [] : [
                ['action' => 'delete', 'type' => 'form', 'handle' => $form['handle'], 'title' => $form['title'], 'keeps' => ['submissions' => Submission::query()->where('form', $form['handle'])->count()]],
            ],
            'handler' => function (array $in) use ($forms) {
                $forms()->delete($in['handle']);

                return ['deleted' => $in['handle']];
            },
        ]);

        $a->register('forms/submissions', [
            'label' => 'Form Submissions',
            'description' => 'Submissions of a form, newest first.',
            'permission' => 'forms.view',
            'annotations' => ['readOnly' => true],
            'input' => ['type' => 'object', 'properties' => [
                'form' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['all', 'new', 'read', 'spam', 'archived'], 'default' => 'all'],
                'search' => ['type' => 'string', 'maxLength' => 120],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'perPage' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
            ], 'required' => ['form']],
            'handler' => function (array $in) {
                $q = Submission::query()->where('form', $in['form'])->latest()->orderByDesc('id');
                $in['status'] === 'all' ? $q->where('status', '!=', 'spam') : $q->where('status', $in['status']);

                if (! empty($in['search'])) {
                    $q->where('data', 'like', '%'.$in['search'].'%');
                }

                $page = $q->paginate($in['perPage'], ['*'], 'page', $in['page']);

                return ['items' => array_map(static fn (Submission $s) => $s->toPublic(), $page->items()), 'total' => $page->total(), 'page' => $page->currentPage(), 'pages' => $page->lastPage()];
            },
        ]);

        $a->register('forms/submission', [
            'label' => 'Open Submission',
            'description' => 'One submission, with its form’s field labels. A new one is marked read.',
            'permission' => 'forms.view',
            'annotations' => ['idempotent' => true],
            'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            'handler' => function (array $in) use ($forms) {
                $s = Submission::query()->find(strtolower($in['id'])) ?? throw new AbilityException(__('Submission not found.'), 'not_found');

                if ($s->status === 'new') {
                    $s->update(['status' => 'read']);
                }

                $labels = array_column(array_map(static fn ($f) => ['name' => $f['name'], 'label' => strip_tags((string) ($f['props']['label'] ?? $f['name']))], FormFields::extract($forms()->find($s->form)['fields'] ?? [])), 'label', 'name');

                return [...$s->toPublic(), 'labels' => (object) $labels];
            },
        ]);

        $a->register('forms/submission-update', [
            'label' => 'Update Submission',
            'description' => 'Mark a submission as read, spam or archived.',
            'permission' => 'forms.view',
            'input' => ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 500], 'status' => ['type' => 'string', 'enum' => ['new', 'read', 'spam', 'archived']]], 'required' => ['ids', 'status']],
            'handler' => fn (array $in) => ['updated' => Submission::query()->whereIn('id', array_map('strtolower', $in['ids']))->update(['status' => $in['status'], 'updated_at' => now()])],
        ]);

        $doomed = static fn (array $in) => Submission::query()->whereIn('id', array_map('strtolower', $in['ids']))->get();

        $a->register('forms/submission-delete', [
            'label' => 'Delete Submissions',
            'description' => 'Permanently delete submissions and their files.',
            'permission' => 'forms.manage',
            'annotations' => ['destructive' => true],
            'input' => ['type' => 'object', 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 500]], 'required' => ['ids']],
            // Ids that match nothing are passed over, in the real run too.
            'dryRun' => fn (array $in): array => $doomed($in)->map(static fn (Submission $s) => ['action' => 'delete', 'type' => 'submission', 'id' => $s->id, 'form' => $s->form, 'removes' => ['files' => is_dir(storage_path('app/forms/'.$s->id))]])->all(),
            'handler' => function (array $in) use ($doomed) {
                $subs = $doomed($in);

                foreach ($subs as $s) {
                    \Cms\Support\Files::deleteDirectory(storage_path('app/forms/'.$s->id));
                    $s->delete();
                }

                return ['deleted' => $subs->count()];
            },
        ]);

        $a->register('forms/export', [
            'label' => 'Export Submissions',
            'description' => 'All submissions of a form as CSV.',
            'permission' => 'forms.view',
            'annotations' => ['readOnly' => true],
            'expose' => ['mcp' => false],
            'input' => ['type' => 'object', 'properties' => ['form' => ['type' => 'string']], 'required' => ['form']],
            'handler' => function (array $in) use ($forms) {
                $form = $forms()->find($in['form']) ?? throw new AbilityException(__('Form not found.'), 'not_found');

                return ['filename' => $form['handle'].'-'.date('Y-m-d').'.csv', 'csv' => Submission::csv($form)];
            },
        ]);

        // Retention: prune old submissions now and then.
        $this->app->terminating(function () {
            $days = (int) $this->cms()->setting('forms', 'retention_days', 0);

            if ($days > 0 && random_int(1, 50) === 1) {
                try {
                    Submission::query()->where('created_at', '<', now()->subDays($days))->delete();
                } catch (Throwable) {
                }
            }
        });
    }
}
