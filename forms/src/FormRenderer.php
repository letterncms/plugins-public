<?php

declare(strict_types=1);

namespace Cms\Forms;

use Cms\Admin\Translations;
use Cms\Modules\Blocks\BlockRenderer;
use Cms\Cms;
use Cms\Modules\Site\Rendering\PageContext;

/**
 * Renders a form as plain HTML that works without JavaScript (POST + redirect)
 * and is enhanced by a tiny inline script (inline errors, no page reload,
 * conditional fields, spam timing).
 */
final class FormRenderer
{
    private static bool $scriptAdded = false;

    public function __construct(private readonly Cms $cms, private readonly FormRepository $forms, private readonly BlockRenderer $blocks, private readonly Captcha\Captchas $captchas) {}

    public function endpoint(string $handle): string
    {
        $base = rtrim((string) $this->cms->setting('forms', 'endpoint', ''), '/');

        return ($base !== '' ? $base : '').'/api/forms/'.$handle;
    }

    public function render(string $handle, array $options = []): string
    {
        // A page showing a form is built again when the form changes, even one that's off or not made yet.
        PageContext::depend('form:'.$handle);

        // Visitors read the form and its messages in the page's language.
        $translations = $this->cms->app()->make(Translations::class);
        $locale = $translations->current();
        $form = $this->forms->find($handle, $locale);

        if (! $form || ! $form['settings']['enabled']) {
            return '';
        }

        $id = 'cms-form-'.$handle;
        $s = $form['settings'];
        $error = $this->e(__('Please check the highlighted fields and try again.', [], $locale));
        $empty = $this->e(__('Leave this empty', [], $locale));
        // The form's fields aren't blocks of the page it's embedded in: the canvas would take them for ones that were removed.
        $fields = $this->blocks->withEditorDocument(null, fn () => $this->blocks->render($form['fields']));
        $honeypot = $s['honeypot'] ? '<div class="cms-form__hp" aria-hidden="true"><label>'.$empty.'<input type="text" name="_cms_website" tabindex="-1" autocomplete="off"></label></div>' : '';
        $rules = json_encode(array_values(array_filter(array_map(static fn ($f) => empty($f['props']['show_if']['field']) ? null : ['name' => $f['name'], 'rule' => $f['props']['show_if']], FormFields::extract($form['fields'])))), JSON_HEX_TAG | JSON_HEX_APOS);

        // The provider's widget, with its script only on pages that show a form. The canvas shows where it sits, and loads nothing.
        $captcha = $this->captchas->current();
        $widget = '';
        $again = '';

        if ($captcha !== null) {
            $editing = (bool) $this->blocks->editor;
            $widget = $editing ? '<div class="cms-placeholder">'.$this->e(__('CAPTCHA')).'</div>' : $captcha->widget($locale);
            $again = '<div class="cms-form__error" id="'.$id.'-captcha" role="alert" tabindex="-1"><p>'.$this->e(__('We could not confirm you are a person. Please try again.', [], $locale)).'</p></div>'
                .'<div class="cms-form__error" id="'.$id.'-captcha-down" role="alert" tabindex="-1"><p>'.$this->e(__('We can’t check that you are a person right now, so nothing was sent. Please try again in a few minutes.', [], $locale)).'</p></div>';
            $editing || $this->cms->hooks()->dispatch('render.foot', 'cms-forms-captcha', '<script src="'.$this->e($captcha->script()).'" async defer></script>');
        }

        $reset = $captcha !== null ? ' data-captcha="'.$this->e($captcha->global()).'"' : '';

        $this->cms->hooks()->dispatch('render.foot', 'cms-forms', '<script type="module">'.$this->script().'</script>');
        $this->styles();

        return <<<HTML
<form id="{$id}" class="cms-form" method="post" action="{$this->e($this->endpoint($handle))}" enctype="multipart/form-data" data-cms-form="{$this->e($handle)}" data-rules='{$rules}'{$reset} novalidate>
  {$fields}
  {$honeypot}
  {$widget}
  <input type="hidden" name="_cms_t" value="">
  <input type="hidden" name="_cms_locale" value="{$this->e($locale)}">
  <div class="cms-form__error" id="{$id}-error" role="alert" tabindex="-1"><p>{$error}</p></div>
  {$again}
  <button type="submit" class="cms-button cms-form__submit" data-variant="primary">{$this->e((string) $s['submit'])}</button>
</form>
<div class="cms-form__success" id="{$id}-ok" role="status" tabindex="-1"><p>{$this->e((string) $s['success'])}</p></div>
HTML;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** The fields' styles, once per page. */
    public function styles(): void
    {
        $this->cms->hooks()->dispatch('render.head', 'cms-forms-css', '<style>'.$this->css().'</style>');
    }

    /**
     * What only these forms have: the busy, error and success states. How fields look and sit
     * is the site's design system (`fields`), as on every form.
     */
    private function css(): string
    {
        return '@layer theme,base,elements,components,utilities;@layer components{'
            .'.cms-form.is-busy .cms-form__submit{opacity:.6;pointer-events:none}'
            .'.cms-form__error,.cms-form__success{display:none}.cms-form__error:target,.cms-form__error.is-shown{display:block;color:var(--field-invalid-color,#d92d20)}'
            .'.cms-form__success:target,.cms-form__success.is-shown{display:block;padding:1rem 1.25rem;border-radius:var(--field-radius,.5rem);background:color-mix(in srgb,var(--color-success,#16a34a) 10%,transparent)}'
            .'}';
    }

    private function script(): string
    {
        return <<<'JS'
for (const form of document.querySelectorAll('form[data-cms-form]')) {
  const t = form.querySelector('[name=_cms_t]'); if (t) t.value = String(Date.now());
  const rules = JSON.parse(form.dataset.rules || '[]');
  const value = (name) => { const els = form.querySelectorAll(`[name="${name}"],[name="${name}[]"]`); const out = []; els.forEach((el) => { if ((el.type === 'checkbox' || el.type === 'radio') ? el.checked : el.value) out.push(el.value) }); return out };
  const apply = () => rules.forEach(({ name, rule }) => { const v = value(rule.field); const ok = rule.op === 'filled' ? v.length > 0 : rule.op === 'empty' ? v.length === 0 : rule.op === 'not' ? !v.includes(rule.value ?? '') : v.includes(rule.value ?? ''); const field = form.querySelector(`[data-field="${name}"]`); if (field) { field.hidden = !ok; field.querySelectorAll('input,select,textarea').forEach((el) => (el.disabled = !ok)) } });
  form.addEventListener('input', apply); apply();
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    form.classList.add('is-busy');
    form.querySelectorAll('.cms-msg').forEach((m) => m.remove());
    form.querySelectorAll('.is-invalid').forEach((f) => f.classList.remove('is-invalid'));
    form.querySelectorAll('[aria-invalid]').forEach((c) => c.removeAttribute('aria-invalid'));
    const error = document.getElementById(form.id + '-error');
    const fallback = error && (error.dataset.text ??= error.querySelector('p').textContent);
    error?.classList.remove('is-shown');
    try {
      const res = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, credentials: 'omit' });
      const json = await res.json().catch(() => ({}));
      if (res.ok) {
        if (json.redirect) { location.href = json.redirect; return }
        form.hidden = true;
        const ok = document.getElementById(form.id + '-ok');
        if (ok) { ok.classList.add('is-shown'); ok.focus() }
        return;
      }
      for (const [name, message] of Object.entries(json.errors || {})) {
        const field = form.querySelector(`[data-field="${name}"]`);
        if (!field) continue;
        field.classList.add('is-invalid');
        field.querySelectorAll('input,select,textarea').forEach((c) => c.setAttribute('aria-invalid', 'true'));
        const p = document.createElement('p'); p.className = 'cms-msg'; p.textContent = message; field.appendChild(p);
      }
      if (json.captcha && form.dataset.captcha) { try { window[form.dataset.captcha]?.reset() } catch {} }
      if (error) { error.querySelector('p').textContent = json.message || fallback; error.classList.add('is-shown'); error.focus() }
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
