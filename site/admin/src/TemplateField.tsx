import { t, type FieldProps } from '@letterncms/sdk'
import { fieldControlId, Select } from '@letterncms/ui'

/** A template is a real entry. Unresolved handles use the renderer's fallback, offered once, as the default. */
export function TemplateField({ field, value, onChange, disabled, error }: FieldProps<string | null>) {
  const selected = value ?? ''
  const fallbackHandle = typeof field.fallbackHandle === 'string' ? field.fallbackHandle : null
  // The default is already in the list as the first option; picked by name, it stays there too.
  const templates = Object.entries((field.options ?? {}) as Record<string, string>)
    .filter(([handle]) => handle !== '' && (handle !== fallbackHandle || handle === selected))
    .map(([value, label]) => ({ value, label }))
  const fallback = String(field.placeholder ?? t('Content Only'))
  const missing = selected !== '' && !templates.some((option) => option.value === selected)
  const options = [
    { value: '', label: fallback.toLowerCase() === t('Default').toLowerCase() ? t('Default') : t('Default ({template})', { template: fallback }) },
    ...templates,
    ...(missing ? [{ value: selected, label: t('Missing: {template}', { template: selected }) }] : []),
  ]
  return (
    <Select
      id={fieldControlId(field)}
      aria-label={field.label}
      aria-describedby={field.describedBy}
      valueHint={missing ? t('Using {template}', { template: fallback }) : undefined}
      value={selected}
      onChange={(v) => onChange(v ?? '')}
      options={options}
      disabled={disabled}
      invalid={!!error}
    />
  )
}
