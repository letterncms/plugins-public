import { registry, t, type FieldDef, type FieldProps } from '@letterncms/sdk'
import { fieldControlId, Icon, Menu, cn } from '@letterncms/ui'
import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { inputClass } from '../controls'
import { EaseCurve } from '../motion/EaseCurve'
import { TokenButton, TokenChip, useTokenOf, useTypedToken } from './tokens'

/* The inspector's own field components — only what core fields can't express: CSS values
   with units, tokens and scrubbing, and easing curves. Text, selects, segmented controls and
   toggles are core types (size: 'sm'); a row's status and reset live on its inline label. */

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
 * a `menu` of presets and an `action` button. `percent` shows a fraction (0–1) as a percentage.
 * A typed value CSS can't use isn't kept: it shows as invalid and goes back on blur. It is
 * checked against the CSS property `property` (or the handle, when it names one), else its unit's.
 */
export function ValueField(props: FieldProps<string | number | null>) {
  return props.field.percent ? <PercentValue {...props} /> : <Value {...props} />
}

/** A fraction (opacity: 0–1) shown and typed as a percentage. */
function PercentValue({ field, value, onChange, ...rest }: FieldProps<string | number | null>) {
  const n = value === null || value === undefined || value === '' ? null : Number(value)
  const scaled = (x: unknown) => (typeof x === 'number' ? Math.round(x * 100) : undefined)
  const slider = field.slider as Slider | undefined
  return (
    <Value
      {...rest}
      field={{ ...field, percent: undefined, unit: '%', units: ['%'], min: scaled(field.min), max: scaled(field.max), step: scaled(field.step) || 1, placeholder: field.placeholder ?? '100', slider: slider && { min: slider.min * 100, max: slider.max * 100, step: Math.max(1, slider.step * 100), neutral: slider.neutral * 100 } }}
      value={n === null || Number.isNaN(n) ? null : `${Math.round(n * 1000) / 10}%`}
      onChange={(v) => {
        const m = v === null || v === undefined ? null : String(v).match(/^(-?\d*\.?\d+)%?$/)
        onChange(m ? String(Math.round(parseFloat(m[1]) * 10) / 1000) : v === null || v === undefined ? null : v)
      }}
    />
  )
}

/**
 * Whether a typed value shows as invalid: after a short pause in typing, or at once
 * when `show()` is called (Enter on a value that can't be kept). Valid text clears it.
 */
export function useInvalid(text: string, valid: (text: string) => boolean) {
  const [shown, setShown] = useState(false)
  const ok = valid(text)
  useEffect(() => {
    if (ok) return setShown(false)
    const timer = window.setTimeout(() => setShown(true), 700)
    return () => window.clearTimeout(timer)
  }, [text, ok])
  return { shown: shown && !ok, show: () => setShown(true) }
}

/** Properties a typed value is checked against when the field's handle isn't one: by its unit. */
const CHECKS: Record<string, string[]> = {
  ms: ['transition-duration'],
  s: ['transition-duration'],
  deg: ['rotate'],
  turn: ['rotate'],
  '': ['line-height', 'opacity', 'z-index', 'aspect-ratio', 'flex', 'order', 'scale', 'font-weight'],
  length: ['width', 'margin', 'inset', 'flex-basis', 'font-size', 'letter-spacing', 'border-width', 'border-radius', 'gap', 'translate', 'grid-template-columns'],
}

/** Whether CSS can use a typed value: a property named by the field's handle, or one its unit belongs to. */
export function validValue(value: string, handle: string, unit: string): boolean {
  if (typeof CSS === 'undefined') return true
  // Token references compile to custom properties.
  const css = value.replace(/token:[a-z]+\.[a-z0-9-]+(\/\d{1,3})?/g, 'var(--token)')
  const property = handle.replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`)
  if (/^-?[a-z]+(-[a-z]+)*$/.test(property) && CSS.supports(property, 'inherit')) return CSS.supports(property, css)
  return (CHECKS[unit] ?? CHECKS.length).some((p) => CSS.supports(p, css))
}

function Value({ field, value: raw, onChange, disabled, context }: FieldProps<string | number | null>) {
  const tokenOf = useTokenOf()
  const typedToken = useTypedToken(field.group as string | undefined)
  const [draft, setDraft] = useState<string | null>(null)
  const [preferred, setPreferred] = useState<string | null>(null)
  const input = useRef<HTMLInputElement>(null)
  const end = useRef<HTMLDivElement>(null)
  const [suffix, setSuffix] = useState(0)
  useLayoutEffect(() => {
    const width = end.current ? end.current.offsetWidth + 4 : 0
    if (width !== suffix) setSuffix(width)
  })
  const value = raw === null || raw === undefined || raw === '' ? undefined : String(raw)
  const unit = (field.unit as string | undefined) ?? 'px'
  const step = (field.step as number | undefined) ?? 1
  const min = field.min as number | undefined
  const max = field.max as number | undefined
  const group = field.group as string | undefined
  const prefix: ReactNode = field.prefixIcon ? <Icon name={field.prefixIcon as string} size={13} /> : (field.prefix as string | undefined)
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

  /** What a draft commits as: null to clear, a token, a number with the shown unit, or CSS; undefined when CSS can't use it. */
  const resolve = (v: string): string | null | undefined => {
    if (v === '') return null
    const token = typedToken(v)
    if (token) return token
    // A bare number takes the shown unit (unitless fields stay numbers).
    if (/^-?\d*\.?\d+$/.test(v)) return shownUnit ? `${v}${shownUnit}` : v
    return keywords.includes(v) || validValue(v, (field.property as string | undefined) ?? field.handle, unit) ? v : undefined
  }
  const invalid = useInvalid(draft ?? '', (v) => draft === null || resolve(v.trim()) !== undefined)

  const commit = () => {
    if (draft === null) return
    const v = draft.trim()
    setDraft(null)
    if (v === (parts ? parts[1] : value ?? '')) return
    // Anything CSS can't use goes back to the value it had.
    const next = resolve(v)
    if (next !== undefined) onChange(next)
  }

  const setUnit = (u: string) => {
    if (parts) onChange(`${parts[1]}${u}`)
    else if (!value) setPreferred(u)
  }

  return (
    <Control>
      {slider && (
        <SubField
          field={{ handle: `${field.handle}-slider`, type: 'number', display: 'slider', input: false, size: 'sm', label: field.label, min: slider.min, max: slider.max, step: slider.step, neutral: slider.neutral }}
          value={parts ? parseFloat(parts[1]) : null}
          onChange={(n) => onChange(n)}
          disabled={disabled}
          context={context}
        />
      )}
      <div className={cn('group/value relative flex h-7 min-w-0 flex-1 items-center', slider && '!w-16 !flex-none')} title={field.title as string | undefined}>
        {prefix !== undefined && (
          <span onPointerDown={(e) => scrub(e, true)} className="absolute left-0 z-10 grid h-7 w-6 cursor-ew-resize select-none place-items-center text-xs text-faint hover:text-ink">
            {prefix}
          </span>
        )}
        {chosen && draft === null ? (
          <TokenButton
            group={group ?? chosen.token.group}
            value={value}
            onPick={onChange}
            trigger={
              <button type="button" onDoubleClick={() => setDraft(value ?? '')} className={cn(inputClass, 'flex items-center pr-1 text-left', prefix !== undefined && '!pl-6')}>
                <TokenChip token={chosen.token} alpha={chosen.alpha} className="text-xs" />
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
            aria-invalid={invalid.shown || undefined}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                if (draft !== null && resolve(draft.trim()) === undefined) invalid.show()
                else (e.target as HTMLInputElement).blur()
              }
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
            className={cn(inputClass, 'tabular-nums', numeric && !disabled && 'cursor-ew-resize focus:cursor-text', prefix !== undefined && '!pl-6')}
            // Room for what sits at its end (the unit, the token button), measured: "rem" is wider than "px".
            style={{ paddingRight: (value ? suffix : 0) + 6 }}
          />
        )}
        {(!chosen || draft !== null) && (
          <div ref={end} className="pointer-events-none absolute inset-y-0 right-1 flex items-center">
            {group && <TokenButton group={group} value={value} onPick={onChange} className="pointer-events-auto" />}
            {shownUnit && units.length > 0 && (
              <Menu
                align="end"
                trigger={
                  <button type="button" disabled={disabled} className={cn('pointer-events-auto h-5 min-w-5 rounded-xs px-1 text-2xs text-faint hover:bg-hover hover:text-ink data-[popup-open]:opacity-100', !value && 'opacity-0 group-hover/value:opacity-100 group-focus-within/value:opacity-100')} aria-label={t('Unit')}>
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
                  <button type="button" className="pointer-events-auto grid size-5 place-items-center rounded-xs text-faint hover:bg-hover hover:text-ink" aria-label={t('Unit')}>
                    <Icon name="b-caret-down" size={10} />
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
            <button type="button" className="flex h-7 shrink-0 items-center gap-0.5 rounded-sm px-1.5 text-xs text-muted hover:bg-hover hover:text-ink">
              {menu.label}
              <Icon name="b-caret-down" size={10} />
            </button>
          }
          items={menu.items.map((item) => ({ label: item.label, checked: item.checked, onSelect: () => onChange(item.value) }))}
        />
      )}
      {action && (
        <button type="button" onClick={() => onChange(action.value)} className="grid size-7 shrink-0 place-items-center rounded-sm text-muted hover:bg-hover hover:text-ink" title={action.label} aria-label={action.label}>
          <Icon name={action.icon} size={14} />
        </button>
      )}
    </Control>
  )
}

/** `blocks-ease` — an easing curve: core select with a preview of the curve's shape beside it. */
export function EaseField({ field, ...props }: FieldProps<string | null>) {
  return (
    <Control>
      <SubField {...props} field={{ ...field, type: 'select', component: undefined, size: 'sm' }} />
      <EaseCurve value={props.value ?? undefined} size={28} />
    </Control>
  )
}

/** The label of a row a field draws itself (lists that span the panel: shadows, filters). */
export { RowLabel } from '../controls'
