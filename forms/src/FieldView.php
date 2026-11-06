<?php

declare(strict_types=1);

namespace Cms\Forms;

use Cms\Modules\Blocks\Elements;

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

        // A field outside a form (or before one is chosen) still looks like a field.
        app(FormRenderer::class)->styles();

        $options = static fn () => FormFields::options($b);

        $control = match ($kind) {
            'textarea' => '<label class="cms-label" for="'.$e($id).'">'.$label.'</label><textarea class="cms-input" id="'.$e($id).'" name="'.$e($name).'" rows="'.(int) ($b['rows'] ?? 5).'" maxlength="'.(int) ($b['max'] ?? 5000).'" placeholder="'.$e($b['placeholder'] ?? '').'"'.$req.$describe.'></textarea>',
            'select' => '<label class="cms-label" for="'.$e($id).'">'.$label.'</label><select class="cms-input" id="'.$e($id).'" name="'.$e($name).'"'.$req.$describe.'><option value="">'.$e($b['placeholder'] ?? 'Choose…').'</option>'
                .implode('', array_map(static fn ($v, $t) => '<option value="'.e((string) $v).'">'.e((string) $t).'</option>', array_keys($options()), $options())).'</select>',
            'radio', 'checkboxes' => self::choices($kind, $b, $name, $label, $required, $describe, $options()),
            'checkbox' => '<label class="cms-choice"><input type="checkbox"'.(($b['look'] ?? 'checkbox') === 'switch' ? ' class="cms-switch" role="switch"' : ' class="cms-check"').' name="'.$e($name).'" value="yes"'.$req.$describe.'><span>'.$label.'</span></label>',
            'consent' => '<label class="cms-choice"><input type="checkbox" class="cms-check" name="'.$e($name).'" value="yes" required aria-required="true"><span>'.($b['text'] ?? 'I agree').'</span></label>',
            'tags' => self::tags($d, $b, $id, $name, $label, $req.$describe),
            'range' => self::range($d, $b, $id, $name, $label, $describe),
            'file' => '<label class="cms-label" for="'.$e($id).'">'.$label.'</label><input class="cms-input" id="'.$e($id).'" type="file" name="'.$e($name).'" accept="'.(['documents' => '.pdf,.doc,.docx,.odt,.txt', 'images' => 'image/*', 'any' => '.pdf,.doc,.docx,.odt,.txt,image/*'][$b['accept'] ?? 'documents'] ?? '.pdf').'"'.$req.$describe.'>',
            default => self::input($kind, $b, $id, $name, $label, $req.$describe),
        };

        $helpHtml = $help ? '<p class="cms-help" id="'.$e($id).'-help">'.$e($help).'</p>' : '';

        // A field, also outside a form: the design system lays out its label, control and help.
        return '<div '.preg_replace('/\bclass="/', 'class="cms-field ', (string) $d['attrs'], 1).' data-field="'.$e($name).'">'.$control.$helpHtml.'</div>';
    }

    /** Options all in view: a list of radios or checkboxes, or chips. */
    private static function choices(string $kind, array $b, string $name, string $label, bool $required, string $describe, array $options): string
    {
        $chips = ($b['look'] ?? 'list') === 'chips';
        $type = $kind === 'radio' ? 'radio' : 'checkbox';
        $items = implode('', array_map(static function ($v, $t) use ($chips, $type, $name, $required): string {
            $input = '<input type="'.$type.'"'.($chips ? '' : ' class="'.($type === 'radio' ? 'cms-radio' : 'cms-check').'"').' name="'.e($type === 'radio' ? $name : $name.'[]').'" value="'.e((string) $v).'"'.($type === 'radio' && $required ? ' required' : '').'>';

            return $chips ? '<label class="cms-chip">'.$input.e((string) $t).'</label>' : '<label class="cms-choice">'.$input.'<span>'.e((string) $t).'</span></label>';
        }, array_keys($options), $options));

        return '<fieldset class="cms-choices"'.($chips ? ' data-look="chips"' : '').$describe.'><legend class="cms-label">'.$label.'</legend><div class="cms-choices cms-choices__list">'.$items.'</div></fieldset>';
    }

    /** Words added one by one, each a tag. Without a script they're typed comma-separated. */
    private static function tags(array $d, array $b, string $id, string $name, string $label, string $extra): string
    {
        $d['renderer']->uses('fields');
        $suggestions = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($b['suggestions'] ?? '')) ?: [])));
        $list = $suggestions !== [] ? '<datalist id="'.e($id).'-list">'.implode('', array_map(static fn ($s) => '<option value="'.e($s).'">', $suggestions)).'</datalist>' : '';

        return '<label class="cms-label" for="'.e($id).'">'.$label.'</label>'
            .'<div class="cms-input cms-tags" data-cms-tags data-name="'.e($name).'[]" data-max="'.max(1, (int) ($b['max'] ?? 10)).'" data-remove="'.e(__('Remove')).'">'
            .'<input class="cms-tags__input" id="'.e($id).'" type="text" name="'.e($name).'" autocomplete="off" placeholder="'.e((string) ($b['placeholder'] ?? '')).'"'.($list !== '' ? ' list="'.e($id).'-list"' : '').$extra.'></div>'.$list;
    }

    /** A number on a scale, with the value beside its label. */
    private static function range(array $d, array $b, string $id, string $name, string $label, string $extra): string
    {
        $d['renderer']->uses('fields');
        [$min, $max] = [(float) ($b['min'] ?? 0), (float) ($b['max'] ?? 100)];
        $max = $max > $min ? $max : $min + 1;
        $step = (float) ($b['step'] ?? 1) > 0 ? (float) $b['step'] : 1.0;
        $value = is_numeric($b['value'] ?? null) ? max($min, min($max, (float) $b['value'])) : $min + round(($max - $min) / 2 / $step) * $step;
        $n = static fn (float $x) => rtrim(rtrim(number_format($x, 6, '.', ''), '0'), '.') ?: '0';
        $shown = static fn (float $x) => e((string) ($b['prefix'] ?? '')).$n($x).e((string) ($b['suffix'] ?? ''));

        return '<label class="cms-label" for="'.e($id).'">'.$label.'<output for="'.e($id).'" data-prefix="'.e((string) ($b['prefix'] ?? '')).'" data-suffix="'.e((string) ($b['suffix'] ?? '')).'">'.$shown($value).'</output></label>'
            .'<input class="cms-range" type="range" id="'.e($id).'" name="'.e($name).'" min="'.$n($min).'" max="'.$n($max).'" step="'.$n($step).'" value="'.$n($value).'" style="--cms-range:'.Elements::fraction($value, $min, $max).'"'.$extra.'>'
            .(($b['scale'] ?? true) ? '<div class="cms-range__scale" aria-hidden="true"><span>'.$shown($min).'</span><span>'.$shown($max).'</span></div>' : '');
    }

    private static function input(string $kind, array $b, string $id, string $name, string $label, string $extra): string
    {
        $type = ['email' => 'email', 'tel' => 'tel', 'url' => 'url', 'number' => 'number', 'date' => 'date'][$kind] ?? 'text';
        $auto = ['email' => 'email', 'tel' => 'tel', 'url' => 'url'][$kind] ?? (($b['autocomplete'] ?? 'off') !== 'off' ? $b['autocomplete'] : null);
        $attrs = $auto ? ' autocomplete="'.e((string) $auto).'"' : '';
        $attrs .= $kind === 'number' && isset($b['min']) ? ' min="'.e((string) $b['min']).'"' : '';
        $attrs .= $kind === 'number' && isset($b['max']) ? ' max="'.e((string) $b['max']).'"' : '';
        $attrs .= $kind === 'text' ? ' maxlength="'.(int) ($b['max'] ?? 200).'"' : '';

        return '<label class="cms-label" for="'.e($id).'">'.$label.'</label><input class="cms-input" id="'.e($id).'" type="'.$type.'" name="'.e($name).'" placeholder="'.e((string) ($b['placeholder'] ?? '')).'"'.$attrs.$extra.'>';
    }
}
