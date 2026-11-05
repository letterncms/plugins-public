<?php

declare(strict_types=1);

namespace Cms\Forms;

/** HTML for form blocks (the form itself and each field type). */
final class FieldView
{
    public static function form(array $d): string
    {
        $handle = (string) ($d['block']['form'] ?? '');

        if ($handle === '') {
            return $d['editor'] ? '<div '.$d['attrs'].'><div class="cms-placeholder">'.e(__('Choose a form to show here.')).'</div></div>' : '';
        }

        return '<div '.$d['attrs'].'>'.app(FormRenderer::class)->render($handle).'</div>';
    }

    public static function field(array $d): string
    {
        $b = $d['block'];
        $kind = substr($d['type']->name, 5);
        $name = FormFields::name($b, $d['type']->name);
        $id = 'f-'.$name.'-'.substr((string) ($d['node']['id'] ?? ''), -4);
        $required = ! empty($b['required']);
        $help = $b['help'] ?? null;
        $req = $required ? ' required aria-required="true"' : '';
        $describe = $help ? ' aria-describedby="'.e($id).'-help"' : '';
        $label = e((string) ($b['label'] ?? '')).($required ? ' <span class="cms-req" aria-hidden="true">*</span>' : '');
        $e = static fn ($v) => e((string) $v);

        if ($kind === 'hidden') {
            // Invisible to visitors; the canvas shows where it sits.
            return $d['editor']
                ? '<div '.$d['attrs'].'><div class="cms-placeholder">'.e(__('Hidden Value')).' · '.$e($name).'</div></div>'
                : '<input type="hidden" name="'.$e($name).'" value="'.$e($b['value'] ?? '').'" '.$d['attrs'].'>';
        }

        // A field outside a form (or before one is chosen) still looks like a field: its control is a `.field`, its label a `.text-label`.
        app(FormRenderer::class)->styles();

        $options = static fn () => FormFields::options($b);

        $control = match ($kind) {
            'textarea' => '<label class="text-label" for="'.$e($id).'">'.$label.'</label><textarea class="field" id="'.$e($id).'" name="'.$e($name).'" rows="'.(int) ($b['rows'] ?? 5).'" maxlength="'.(int) ($b['max'] ?? 5000).'" placeholder="'.$e($b['placeholder'] ?? '').'"'.$req.$describe.'></textarea>',
            'select' => '<label class="text-label" for="'.$e($id).'">'.$label.'</label><select class="field" id="'.$e($id).'" name="'.$e($name).'"'.$req.$describe.'><option value="">'.$e($b['placeholder'] ?? 'Choose…').'</option>'
                .implode('', array_map(static fn ($v, $t) => '<option value="'.e((string) $v).'">'.e((string) $t).'</option>', array_keys($options()), $options())).'</select>',
            'radio', 'checkboxes' => '<fieldset'.$describe.'><legend class="text-label">'.$label.'</legend>'.implode('', array_map(
                static fn ($v, $t) => '<label class="cms-choice"><input type="'.($kind === 'radio' ? 'radio' : 'checkbox').'" name="'.e($kind === 'radio' ? $name : $name.'[]').'" value="'.e((string) $v).'"'.($kind === 'radio' && $required ? ' required' : '').'> '.e((string) $t).'</label>',
                array_keys($options()), $options(),
            )).'</fieldset>',
            'checkbox' => '<label class="cms-choice"><input type="checkbox" name="'.$e($name).'" value="yes"'.$req.$describe.'> '.$label.'</label>',
            'consent' => '<label class="cms-choice"><input type="checkbox" name="'.$e($name).'" value="yes" required aria-required="true"> <span>'.($b['text'] ?? 'I agree').'</span></label>',
            'file' => '<label class="text-label" for="'.$e($id).'">'.$label.'</label><input id="'.$e($id).'" type="file" name="'.$e($name).'" accept="'.(['documents' => '.pdf,.doc,.docx,.odt,.txt', 'images' => 'image/*', 'any' => '.pdf,.doc,.docx,.odt,.txt,image/*'][$b['accept'] ?? 'documents'] ?? '.pdf').'"'.$req.$describe.'>',
            default => self::input($kind, $b, $id, $name, $label, $req.$describe),
        };

        $helpHtml = $help ? '<p class="cms-help" id="'.$e($id).'-help">'.$e($help).'</p>' : '';

        return '<div '.$d['attrs'].' data-field="'.$e($name).'">'.$control.$helpHtml.'</div>';
    }

    private static function input(string $kind, array $b, string $id, string $name, string $label, string $extra): string
    {
        $type = ['email' => 'email', 'tel' => 'tel', 'url' => 'url', 'number' => 'number', 'date' => 'date'][$kind] ?? 'text';
        $auto = ['email' => 'email', 'tel' => 'tel', 'url' => 'url'][$kind] ?? (($b['autocomplete'] ?? 'off') !== 'off' ? $b['autocomplete'] : null);
        $attrs = $auto ? ' autocomplete="'.e((string) $auto).'"' : '';
        $attrs .= $kind === 'number' && isset($b['min']) ? ' min="'.e((string) $b['min']).'"' : '';
        $attrs .= $kind === 'number' && isset($b['max']) ? ' max="'.e((string) $b['max']).'"' : '';
        $attrs .= $kind === 'text' ? ' maxlength="'.(int) ($b['max'] ?? 200).'"' : '';

        return '<label class="text-label" for="'.e($id).'">'.$label.'</label><input class="field" id="'.e($id).'" type="'.$type.'" name="'.e($name).'" placeholder="'.e((string) ($b['placeholder'] ?? '')).'"'.$attrs.$extra.'>';
    }
}
