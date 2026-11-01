import type { FieldProps } from '@letterncms/sdk'
import { cn } from '@letterncms/ui'

/** A menu item's label, edited in place in its row. `display: fallback` shows the placeholder as the label it falls back to. */
export function LabelField({ field, value, onChange, disabled }: FieldProps<string | null>) {
  return (
    <input
      value={value ?? ''}
      disabled={disabled}
      onChange={(e) => onChange(e.target.value)}
      placeholder={field.placeholder}
      aria-label={field.label}
      className={cn('w-full min-w-0 bg-transparent text-sm text-text outline-none', field.display === 'fallback' ? 'placeholder:text-text' : 'placeholder:text-faint')}
    />
  )
}
