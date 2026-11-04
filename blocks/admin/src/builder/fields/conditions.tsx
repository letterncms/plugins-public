import { t, type FieldProps } from '@letterncms/sdk'
import { Icon } from '@letterncms/ui'
import { RemoveButton } from '../controls'
import { SubField } from './controls'

/* `blocks-conditions` — which entries a list keeps: rows of a field, a comparison and a
   value ("category is news"). The value may be data in curly braces: {entry.category}. */

interface Condition {
  field?: string
  op?: string
  value?: unknown
}

const OPERATORS = () => [
  { value: '=', label: t('is') },
  { value: '!=', label: t('is not') },
  { value: 'contains', label: t('contains') },
  { value: '>', label: t('is more than') },
  { value: '<', label: t('is less than') },
  { value: 'not null', label: t('is set') },
  { value: 'null', label: t('is empty') },
]

export function ConditionsField({ field, value, onChange, disabled, context }: FieldProps<Condition[] | null>) {
  const rows = Array.isArray(value) ? value : []
  const set = (next: Condition[]) => onChange(next.length ? next : null)
  const patch = (i: number, change: Condition) => set(rows.map((row, j) => (j === i ? { ...row, ...change } : row)))

  return (
    <div className="min-w-0 flex-1 space-y-1">
      {rows.map((row, i) => (
        <div key={i} className="flex items-center gap-1">
          <SubField field={{ handle: `${field.handle}-${i}-field`, type: 'text', size: 'sm', commit: 'blur', label: t('Field'), placeholder: t('Field'), mono: true, className: '!w-[34%] !flex-none' }} value={row.field ?? null} onChange={(v) => patch(i, { field: String(v ?? '').trim() })} disabled={disabled} context={context} />
          <SubField field={{ handle: `${field.handle}-${i}-op`, type: 'select', size: 'sm', required: true, label: t('Comparison'), options: OPERATORS(), className: '!flex-none w-[92px]' }} value={row.op ?? '='} onChange={(v) => patch(i, { op: String(v ?? '=') })} disabled={disabled} context={context} />
          {!['null', 'not null'].includes(row.op ?? '') && (
            <SubField field={{ handle: `${field.handle}-${i}-value`, type: 'text', size: 'sm', commit: 'blur', label: t('Value'), placeholder: t('Value') }} value={row.value === undefined || row.value === null ? null : String(row.value)} onChange={(v) => patch(i, { value: v ?? '' })} disabled={disabled} context={context} />
          )}
          <RemoveButton label={t('Remove Condition')} onClick={() => set(rows.filter((_, j) => j !== i))} />
        </div>
      ))}
      <button type="button" disabled={disabled} onClick={() => set([...rows, { field: '', op: '=', value: '' }])} className="-ml-2 inline-flex h-7 items-center gap-1.5 rounded-sm px-2 text-xs text-muted hover:bg-hover hover:text-ink">
        <Icon name="b-plus" size={13} />
        {t('Add Condition')}
      </button>
    </div>
  )
}
