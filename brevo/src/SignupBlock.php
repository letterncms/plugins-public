<?php

declare(strict_types=1);

namespace Cms\Brevo;

use Cms\Admin\Translations;

/**
 * The signup block: an email field and a button, plain HTML that posts and comes back without
 * JavaScript, and stays on the page with it. It looks as the site's design system draws fields and buttons.
 */
final class SignupBlock
{
    public static function definition(): array
    {
        return [
            'name' => 'brevo/signup',
            'title' => __('Newsletter Signup'),
            'icon' => 'brevo',
            'category' => 'form',
            'description' => __('Adds visitors to a Brevo list'),
            'keywords' => ['newsletter', 'subscribe', 'email', 'signup', 'brevo'],
            'styles' => ['spacing', 'size', 'background', 'border'],
            'fields' => [
                ['handle' => 'list', 'type' => 'select', 'label' => 'List', 'placeholder' => 'Default List', 'options' => [], 'dynamic' => 'brevo-lists'],
                ['handle' => 'label', 'type' => 'text', 'label' => 'Label', 'placeholder' => 'Email'],
                ['handle' => 'button', 'type' => 'text', 'label' => 'Button Label', 'placeholder' => 'Subscribe'],
                ['handle' => 'consent', 'type' => 'text', 'label' => 'Consent Sentence'],
                ['handle' => 'success', 'type' => 'textarea', 'label' => 'Success Message', 'rows' => 2],
            ],
            'render' => self::render(...),
        ];
    }

    public static function render(array $d): string
    {
        $signup = app(Signup::class);
        $b = $d['block'];
        $list = $signup->list($b['list'] ?? null);

        if ($list === null) {
            return $d['editor'] ? '<div '.$d['attrs'].'><div class="cms-placeholder">'.e(__('Choose a list to add people to.')).'</div></div>' : '';
        }

        $locale = app(Translations::class)->current();
        $say = static fn (string $key, string $default) => e(trim((string) ($b[$key] ?? '')) ?: __($default, [], $locale));
        $id = 'brevo-signup-'.substr((string) ($d['node']['id'] ?? $list), -6);
        $success = trim((string) ($b['success'] ?? '')) ?: ($signup->doubleOptIn() ? __('Almost there: follow the link in the email we just sent you.', [], $locale) : __('Thanks, you’re subscribed.', [], $locale));
        $consent = trim((string) ($b['consent'] ?? '')) !== '' ? '<p class="cms-help">'.e(trim((string) $b['consent'])).'</p>' : '';
        $base = $signup->base();

        cms()->hooks()->dispatch('render.head', 'brevo-signup-css', '<style>@layer theme,base,elements,components,utilities;@layer components{'
            .'.brevo-signup{max-width:28rem;margin-inline:auto}.brevo-signup__ok{max-width:28rem;margin-inline:auto}'
            .'.brevo-signup__row{display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-start}.brevo-signup__row .cms-input{flex:1 1 14rem;min-width:0}'
            .'.brevo-signup__hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}'
            .'.brevo-signup.is-busy .cms-button{opacity:.6;pointer-events:none}'
            .'.brevo-signup__error,.brevo-signup__ok{display:none}.brevo-signup__error:target,.brevo-signup__error.is-shown{display:block;color:var(--field-invalid-color,#d92d20)}'
            .'.brevo-signup__ok:target,.brevo-signup__ok.is-shown{display:block;padding:1rem 1.25rem;border-radius:var(--field-radius,.5rem);background:color-mix(in srgb,var(--color-success,#16a34a) 10%,transparent)}'
            .'}</style>');
        cms()->hooks()->dispatch('render.foot', 'brevo-signup', '<script type="module">'.self::script().'</script>');

        return '<div '.$d['attrs'].'>'
            .'<form id="'.e($id).'" class="brevo-signup" method="post" action="'.e($base.'/api/brevo/subscribe').'" data-brevo-signup>'
            .'<div class="cms-field"><label class="cms-label" for="'.e($id).'-email">'.$say('label', 'Email').'</label>'
            .'<div class="brevo-signup__row"><input class="cms-input" id="'.e($id).'-email" type="email" name="email" autocomplete="email" required aria-required="true" maxlength="254">'
            .'<button type="submit" class="cms-button" data-variant="primary"><span>'.$say('button', 'Subscribe').'</span></button></div>'.$consent.'</div>'
            .'<div class="brevo-signup__hp" aria-hidden="true"><label>'.e(__('Leave this empty', [], $locale)).'<input type="text" name="_cms_website" tabindex="-1" autocomplete="off"></label></div>'
            .'<input type="hidden" name="list" value="'.$list.'"><input type="hidden" name="seal" value="'.e($signup->seal($list)).'">'
            .'<input type="hidden" name="_cms_locale" value="'.e($locale).'"><input type="hidden" name="_cms_id" value="'.e($id).'">'
            .'<div class="brevo-signup__error" id="'.e($id).'-error" role="alert" tabindex="-1"><p>'.e(__('That didn’t work. Check the address and try again.', [], $locale)).'</p></div>'
            .'</form>'
            .'<div class="brevo-signup__ok" id="'.e($id).'-ok" role="status" tabindex="-1"><p>'.e($success).'</p></div>'
            .'</div>';
    }

    private static function script(): string
    {
        return <<<'JS'
for (const form of document.querySelectorAll('form[data-brevo-signup]')) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const error = document.getElementById(form.id + '-error');
    error?.classList.remove('is-shown');
    form.classList.add('is-busy');
    try {
      const res = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, credentials: 'omit' });
      const json = await res.json().catch(() => ({}));
      if (res.ok) {
        form.hidden = true;
        const ok = document.getElementById(form.id + '-ok');
        if (ok) { ok.classList.add('is-shown'); ok.focus() }
        return;
      }
      if (error) { if (json.message) error.querySelector('p').textContent = json.message; error.classList.add('is-shown'); error.focus() }
    } catch {
      error?.classList.add('is-shown');
    } finally {
      form.classList.remove('is-busy');
    }
  });
}
JS;
    }
}
