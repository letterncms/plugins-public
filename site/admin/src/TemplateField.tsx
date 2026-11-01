import { t, type FieldProps } from '@letterncms/sdk'
import { fieldControlId, Select } from '@letterncms/ui'

/** A template is a real entry. Unresolved handles use the renderer's fallback. */
export function TemplateField({ field, value, onChange, disabled, error }: FieldProps<string | null>) {
  const templates = Object.entries((field.options ?? {}) as Record<string, string>).filter(([handle]) => handle !== '').map(([value, label]) => ({ value, label }))
  const selected = value ?? ''
  const fallback = String(field.placeholder ?? t('Content Only'))
  const missing = selected !== '' && !templates.some((option) => option.value === selected)
  const options = [
    { value: '', label: fallback, description: t('Inherited') },
    ...templates,
    ...(missing ? [{ value: selected, label: t('Missing: {template}', { template: selected }) }] : []),
  ]
  return (
    <div className="space-y-1.5">
      <Select
        id={fieldControlId(field)}
        aria-label={field.label}
        aria-describedby={field.describedBy}
        valueHint={selected === '' ? t('Inherited') : missing ? t('Using {template}', { template: fallback }) : undefined}
        value={selected}
        onChange={(v) => onChange(v ?? '')}
        options={options}
        disabled={disabled}
        invalid={!!error}
      />
    </div>
  )
}
