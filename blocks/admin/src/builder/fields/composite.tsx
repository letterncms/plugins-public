import { t, type FieldDef, type FieldProps } from '@letterncms/sdk'
import { Icon, cn } from '@letterncms/ui'
import { useState } from 'react'
import { RemoveButton } from '../controls'
import { Control, SubField, shown } from './controls'

const COLUMNS: Record<number, string> = { 2: 'grid grid-cols-2', 3: 'grid grid-cols-3', 4: 'grid grid-cols-4' }

/**
 * `blocks-group` — the inline twin of core `group`: sub-`fields` side by side in one row
 * (or a grid of `columns`), their values one record. `link` is a single field standing in
 * for all of them until split (one radius for every corner).
 */
export function GroupField({ field, value, onChange, disabled, context }: FieldProps<Record<string, any> | null>) {
  const values = value ?? {}
  const link = field.link as FieldDef | undefined
  const [split, setSplit] = useState(!link || field.split === true)
  const fields = (split ? ((field.fields as FieldDef[]) ?? []) : [link!]).filter((f) => shown(f, values))
  const columns = split ? COLUMNS[field.columns as number] : undefined
  return (
    <Control>
      <div className={cn('min-w-0 flex-1 gap-1', columns ?? 'flex items-center')}>
        {fields.map((f) => (
          <SubField key={f.handle} field={f} value={values[f.handle] ?? f.default ?? null} onChange={(v) => onChange({ ...values, [f.handle]: v })} disabled={disabled} context={context} />
        ))}
      </div>
      {link && (
        <button type="button" onClick={() => setSplit(!split)} className="grid size-7 shrink-0 place-items-center rounded-sm text-muted hover:bg-hover hover:text-ink" title={split ? (field.joinLabel as string) ?? t('The same for all') : (field.splitLabel as string) ?? t('Each One')}>
          <Icon name={split ? 'b-square' : 'b-corners-out'} size={14} />
        </button>
      )}
    </Control>
  )
}

interface PairKey {
  /** Shown before the name (is-). */
  prefix?: string
  /** Wrapper classes for the name, e.g. a width. */
  className?: string
  placeholder?: string
  /** A datalist id of suggested names. */
  list?: string
  /** The only names allowed. */
  allowed?: string[]
  /** Pick the name from these instead of typing it. */
  options?: string[]
  lower?: boolean
  /** Characters removed from a typed name, as a regex class: `[^a-z0-9-]`. */
  strip?: string
}

/**
 * `blocks-pairs` — name → value pairs (CSS properties, variables, attributes, states), each
 * renamed, edited or removed in place; the value is the record, in order. `key` configures
 * the name, `join` the word between, `item` the value's field (`items` per name; `property:
 * true` checks a value against the CSS property its name is), and
 * `labels` shows fixed names instead of editing them.
 */
export function PairsField({ field, value, onChange, disabled, context }: FieldProps<Record<string, unknown> | null>) {
  const record = value ?? {}
  const key = (field.key ?? {}) as PairKey
  const labels = field.labels as Record<string, string> | undefined
  const items = field.items as Record<string, FieldDef> | undefined

  const rename = (from: string, raw: string | null) => {
    let to = (raw ?? '').trim()
    if (key.lower) to = to.toLowerCase()
    if (key.strip) to = to.replace(new RegExp(key.strip, 'g'), '')
    if (!to || to === from || to in record || (key.allowed && !key.allowed.includes(to))) return
    onChange(Object.fromEntries(Object.entries(record).map(([k, v]) => (k === from ? [to, v] : [k, v]))))
  }

  return (
    <div className="min-w-0 flex-1 space-y-1">
      {Object.entries(record).map(([name, v]) => (
        <div key={name} className="flex items-center gap-1">
          {key.prefix && <span className="text-xs text-faint">{key.prefix}</span>}
          {labels ? (
            <span className="mr-1 w-[88px] shrink-0 truncate text-xs text-text">{labels[name] ?? name}</span>
          ) : (
            <SubField
              field={{ handle: `${field.handle}-${name}`, type: key.options ? 'select' : 'text', size: 'sm', commit: 'blur', label: t('Name'), options: key.options, list: key.list, placeholder: key.placeholder, mono: true, className: key.className ?? '!w-[42%] !flex-none' }}
              value={name}
              onChange={(next) => rename(name, next)}
              disabled={disabled}
              context={context}
            />
          )}
          {typeof field.join === 'string' && <span className="text-xs text-faint">{field.join}</span>}
          <SubField field={{ ...(items?.[name] ?? (field.item as FieldDef)), handle: `${field.handle}-${name}-value`, ...((items?.[name] ?? (field.item as FieldDef)).property === true ? { property: name } : {}) }} value={v ?? null} onChange={(next) => onChange({ ...record, [name]: next })} disabled={disabled} context={context} />
          <RemoveButton onClick={() => onChange(Object.fromEntries(Object.entries(record).filter(([k]) => k !== name)))} />
        </div>
      ))}
    </div>
  )
}

export type PairOp = { op: 'set'; key: string; value: unknown } | { op: 'rename'; from: string; to: string } | { op: 'remove'; key: string }

/** What one `blocks-pairs` change did, for owners that store pairs in more than one place. */
export function pairOp(prev: Record<string, unknown>, next: Record<string, unknown>): PairOp | null {
  const before = Object.keys(prev)
  const after = Object.keys(next)
  if (after.length < before.length) {
    const key = before.find((k) => !(k in next))
    return key === undefined ? null : { op: 'remove', key }
  }
  const i = before.findIndex((k, j) => after[j] !== k)
  if (i >= 0) return { op: 'rename', from: before[i], to: after[i] }
  const key = after.find((k) => next[k] !== prev[k])
  return key === undefined ? null : { op: 'set', key, value: next[key] }
}
