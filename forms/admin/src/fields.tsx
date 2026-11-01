import type { FieldProps } from '@letterncms/sdk'
import { cn } from '@letterncms/ui'

/** A form field's label, edited in place where the visitor will read it. `display: heading` for labels above a control. */
export function LabelField({ field, value, onChange, disabled }: FieldProps<string | null>) {
  return (
    <input
      value={value ?? ''}
      disabled={disabled}
      onChange={(e) => onChange(e.target.value)}
      placeholder={field.placeholder}
      aria-label={field.label}
      className={cn('w-full min-w-0 bg-transparent outline-none', field.display === 'heading' && 'text-sm font-medium text-text placeholder:text-faint')}
    />
  )
}
