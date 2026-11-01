import { registry, t, type FieldDef, type FieldProps } from '@letterncms/sdk'
import { fieldControlId, Icon, Menu, cn } from '@letterncms/ui'
import { useRef, useState, type ReactNode } from 'react'
import { inputClass } from '../controls'
import { EaseCurve } from '../motion/EaseCurve'
import { TokenButton, TokenChip, useTokenOf, useTypedToken } from './tokens'

/* The inspector's own field components — only what core fields can't express: CSS values
   with units, tokens and scrubbing, and easing curves. Text, selects, segmented controls and
   toggles are core types (size: 'xs'); a row's status and reset live on its inline label. */

/** Conditional sub-fields (`if`), as in Fields. */
export function shown(field: FieldDef, values: Record<string, any>): boolean {
  const rule = field.if as Record<string, unknown> | undefined
  return !rule || Object.entries(rule).every(([h, expected]) => (Array.isArray(expected) ? expected.includes(values[h]) : values[h] === expected))
}

/** One field definition drawn with its registered component — for composite fields. */
export function SubField({ field, ...props }: Omit<FieldProps, 'context'> & { context?: FieldProps['context'] }) {
  const Component = registry.fields.get(field.component ?? field.type) ?? registry.fields.get('json')
  if (!Component) return null
  return (
    <div className={cn('min-w-0 flex-1', field.className as string | undefined)}>
      <Component field={field} {...props} context={props.context ?? {}} />
    </div>
  )
}

/** A field's control area: a row that takes the space it is given. */
export function Control({ className, children }: { className?: string; children: ReactNode }) {
  return <div className={cn('relative flex min-w-0 flex-1 items-center gap-1', className)}>{children}</div>
}

const NUMBER = /^(-?\d*\.?\d+)([a-z%]*)$/i

interface Slider {
  min: number
  max: number
  step: number
  /** The value that means "not set" (1 for opacity). */
  neutral: number
}

const UNITS: Record<string, string[]> = {
  px: ['px', '%', 'em', 'rem', 'vw', 'vh'],
  '%': ['%', 'px', 'em', 'rem', 'vw', 'vh'],
  em: ['em', 'rem', 'px', '%'],
  deg: ['deg', 'turn'],
  ms: ['ms', 's'],
}

const clamp = (n: number, min?: number, max?: number) => Math.min(max ?? Infinity, Math.max(min ?? -Infinity, n))
const round = (n: number) => Math.round(n * 1000) / 1000

/**
 * `blocks-value` — a CSS value: type anything (12, 2rem, 50%, auto, clamp(…)), drag the field
 * (or its prefix) sideways to scrub (Shift ×10, Alt ×0.1), ↑/↓ to nudge, pick a unit from the
 * suffix, or a design token (`group`). Options: unit (px; '' for plain numbers), units, keywords
 * (offered with the units, e.g. auto), step, min, max, prefix, prefixIcon, title, slider,
 * a `menu` of presets and an `action` button.
 */
export function ValueField({ field, value: raw, onChange, disabled, context }: FieldProps<string | number | null>) {
  const tokenOf = useTokenOf()
  const typedToken = useTypedToken(field.group as string | undefined)
  const [draft, setDraft] = useState<string | null>(null)
  const [preferred, setPreferred] = useState<string | null>(null)
  const input = useRef<HTMLInputElement>(null)
  const value = raw === null || raw === undefined || raw === '' ? undefined : String(raw)
  const unit = (field.unit as string | undefined) ?? 'px'
  const step = (field.step as number | undefined) ?? 1
  const min = field.min as number | undefined
  const max = field.max as number | undefined
  const group = field.group as string | undefined
  const prefix: ReactNode = field.prefixIcon ? <Icon name={field.prefixIcon as string} size={11} /> : (field.prefix as string | undefined)
  const slider = field.slider as Slider | undefined
  const menu = field.menu as { label: string; items: { label: string; value: string | null; checked?: boolean }[] } | undefined
  const action = field.action as { icon: string; label: string; value: string } | undefined
  const keywords = (field.keywords as string[] | undefined) ?? []
  const chosen = tokenOf(value)
  const parts = value?.match(NUMBER)
  // A number shows without its unit; the unit is the suffix.
  const shownUnit = parts ? parts[2] || unit : !value ? preferred ?? unit : ''
  const units = unit ? (field.units as string[] | undefined) ?? UNITS[unit] ?? [unit] : []
  const numeric = !value || !!parts

  const commitNumber = (n: number, u = shownUnit) => onChange(`${round(clamp(n, min, max))}${u}`)

  const nudge = (delta: number) => {
    if (!numeric) return
    commitNumber((parts ? parseFloat(parts[1]) : 0) + delta)
  }

  /** Drag sideways to change the number; a click without moving focuses the field. */
  const scrub = (e: React.PointerEvent, focus: boolean) => {
    if (disabled || e.button !== 0 || document.activeElement === e.currentTarget) return
    if (!numeric) return
    e.preventDefault()
    const start = e.clientX
    const base = parts ? parseFloat(parts[1]) : 0
    let moved = false
    const move = (ev: PointerEvent) => {
      const dx = ev.clientX - start
      if (!moved && Math.abs(dx) < 3) return
      moved = true
      document.body.style.cursor = 'ew-resize'
      commitNumber(base + Math.round(dx / 2) * step * (ev.shiftKey ? 10 : ev.altKey ? 0.1 : 1))
    }
    const up = () => {
      window.removeEventListener('pointermove', move)
      window.removeEventListener('pointerup', up)
      document.body.style.cursor = ''
      if (!moved && focus) input.current?.focus()
    }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', up)
  }

  const commit = () => {
    if (draft === null) return
    const v = draft.trim()
    setDraft(null)
    if (v === (parts ? parts[1] : value ?? '')) return
    if (v === '') return onChange(null)
    const token = typedToken(v)
    if (token) return onChange(token)
    // A bare number takes the shown unit (unitless fields stay numbers).
    onChange(/^-?\d*\.?\d+$/.test(v) ? (shownUnit ? `${v}${shownUnit}` : v) : v)
  }

  const setUnit = (u: string) => {
    if (parts) onChange(`${parts[1]}${u}`)
    else if (!value) setPreferred(u)
  }

  return (
    <Control>
      {slider && (
        <SubField
          field={{ handle: `${field.handle}-slider`, type: 'number', display: 'slider', input: false, size: 'xs', label: field.label, min: slider.min, max: slider.max, step: slider.step, neutral: slider.neutral }}
          value={value !== undefined && !Number.isNaN(Number(value)) ? Number(value) : null}
          onChange={(n) => onChange(n)}
          disabled={disabled}
          context={context}
        />
      )}
      <div className={cn('group/value relative flex h-6 min-w-0 flex-1 items-center', slider && '!w-14 !flex-none')} title={field.title as string | undefined}>
        {prefix !== undefined && (
          <span onPointerDown={(e) => scrub(e, true)} className="absolute left-0 z-10 grid h-6 w-5 cursor-ew-resize select-none place-items-center text-2xs text-faint hover:text-text">
            {prefix}
          </span>
        )}
        {chosen && draft === null ? (
          <TokenButton
            group={group ?? chosen.token.group}
            value={value}
            onPick={onChange}
            trigger={
              <button type="button" onDoubleClick={() => setDraft(value ?? '')} className={cn(inputClass, 'flex items-center pr-1 text-left', prefix !== undefined && '!pl-5')}>
                <TokenChip token={chosen.token} alpha={chosen.alpha} className="text-2xs" />
              </button>
            }
          />
        ) : (
          <input
            ref={input}
            id={fieldControlId(field)}
            value={draft ?? (parts ? parts[1] : value ?? '')}
            placeholder={field.placeholder}
            disabled={disabled}
            autoComplete="off"
            spellCheck={false}
            onPointerDown={(e) => scrub(e, true)}
            onChange={(e) => setDraft(e.target.value)}
            onFocus={(e) => e.target.select()}
            onBlur={commit}
            onKeyDown={(e) => {
              if (e.key === 'Enter') (e.target as HTMLInputElement).blur()
              if (e.key === 'Escape') {
                setDraft(null)
                requestAnimationFrame(() => input.current?.blur())
              }
              if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && numeric) {
                e.preventDefault()
                setDraft(null)
                nudge((e.key === 'ArrowUp' ? 1 : -1) * step * (e.shiftKey ? 10 : e.altKey ? 0.1 : 1))
              }
            }}
            className={cn(inputClass, 'tabular-nums', numeric && !disabled && 'cursor-ew-resize focus:cursor-text', prefix !== undefined && '!pl-5')}
            style={{ paddingRight: (shownUnit && units.length ? 20 : 4) + (group ? 18 : 0) }}
          />
        )}
        {(!chosen || draft !== null) && (
          <div className="pointer-events-none absolute inset-y-0 right-0.5 flex items-center">
            {group && <TokenButton group={group} value={value} onPick={onChange} className="pointer-events-auto" />}
            {shownUnit && units.length > 0 && (
              <Menu
                align="end"
                trigger={
                  <button type="button" disabled={disabled} className={cn('pointer-events-auto h-5 min-w-5 rounded-xs px-0.5 text-[10px] font-medium uppercase text-faint hover:bg-hover hover:text-text data-[popup-open]:opacity-100', !value && 'opacity-0 group-hover/value:opacity-100 group-focus-within/value:opacity-100')} aria-label={t('Unit')}>
                    {shownUnit}
                  </button>
                }
                items={[
                  ...units.map((u) => ({ label: u, checked: u === shownUnit, onSelect: () => setUnit(u) })),
                  ...(keywords.length ? [{ separator: true, label: '' }, ...keywords.map((k) => ({ label: k, checked: value === k, onSelect: () => onChange(k) }))] : []),
                ]}
              />
            )}
            {!shownUnit && unit && keywords.length > 0 && value && keywords.includes(value) && (
              <Menu
                align="end"
                trigger={
                  <button type="button" className="pointer-events-auto grid size-5 place-items-center rounded-xs text-faint hover:bg-hover hover:text-text" aria-label={t('Unit')}>
                    <Icon name="b-caret-down" size={9} />
                  </button>
                }
                items={[...units.map((u) => ({ label: u, onSelect: () => onChange(`0${u}`) })), { separator: true, label: '' }, ...keywords.map((k) => ({ label: k, checked: value === k, onSelect: () => onChange(k) }))]}
              />
            )}
          </div>
        )}
      </div>
      {menu && (
        <Menu
          align="end"
          trigger={
            <button type="button" className="flex h-6 shrink-0 items-center gap-0.5 rounded-xs px-1.5 text-2xs text-muted hover:bg-hover hover:text-text">
              {menu.label}
              <Icon name="b-caret-down" size={9} />
            </button>
          }
          items={menu.items.map((item) => ({ label: item.label, checked: item.checked, onSelect: () => onChange(item.value) }))}
        />
      )}
      {action && (
        <button type="button" onClick={() => onChange(action.value)} className="grid size-6 shrink-0 place-items-center rounded-xs text-muted hover:bg-hover" title={action.label} aria-label={action.label}>
          <Icon name={action.icon} size={12} />
        </button>
      )}
    </Control>
  )
}

/** `blocks-ease` — an easing curve: core select with a preview of the curve's shape beside it. */
export function EaseField({ field, ...props }: FieldProps<string | null>) {
  return (
    <Control>
      <SubField {...props} field={{ ...field, type: 'select', component: undefined, size: 'xs' }} />
      <EaseCurve value={props.value ?? undefined} size={24} />
    </Control>
  )
}

/**
 * The label of a row a field draws itself (lists that span the panel: shadows, filters):
 * the same column and colors as an inline row's label — blue when set here (click to
 * reset), orange when inherited from another breakpoint or state.
 */
export function RowLabel({ field, children }: { field: FieldDef; children?: ReactNode }) {
  const status = field.status as 'own' | 'inherited' | undefined
  const reset = field.reset as (() => void) | undefined
  const className = cn('w-20 shrink-0 truncate text-left text-2xs', status === 'own' ? 'text-focus' : status === 'inherited' ? 'text-warning' : 'text-muted')
  const label = children ?? field.label
  if (status === 'own' && reset) {
    return <button type="button" onClick={reset} className={cn(className, 'hover:underline')} title={t('Reset {label}', { label: String(field.label ?? '') })}>{label}</button>
  }
  return <span className={className} title={field.instructions as string | undefined}>{label}</span>
}
