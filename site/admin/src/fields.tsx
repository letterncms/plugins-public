import { registry, t, type FieldDef, type FieldProps } from '@letterncms/sdk'
import { fieldControlId, Fields, Icon, IconButton, Input, Popover, Select, Tooltip, cn } from '@letterncms/ui'
import { forwardRef, useEffect, useRef, useState, type ButtonHTMLAttributes, type ReactNode } from 'react'

/* Field components of the design system editor — the controls core fields can't
   express: fluid sizes, font stacks, CSS declarations, easing curves, shadows and
   font files. Compact (xs) like the canvas inspector, whose value control (a number
   with its unit, scrubbed or typed) they use when Blocks has drawn it. */

/** The canvas inspector's value control (`blocks-value`), or a plain input without it. */
export function ValueInput({ field, value, onChange, disabled }: { field: FieldDef; value: string | number | null; onChange: (value: string | null) => void; disabled?: boolean }) {
  const Value = registry.fields.get('blocks-value')
  if (Value) return <Value field={{ ...field, component: 'blocks-value' }} value={value} onChange={(v: string | number | null) => onChange(v === null || v === undefined ? null : String(v))} disabled={disabled} context={{}} />
  return <Input id={fieldControlId(field)} size="sm" className="min-w-0 flex-1 font-mono" aria-label={field.label} placeholder={field.placeholder as string | undefined} value={String(value ?? '')} disabled={disabled} onChange={(e) => onChange(e.target.value)} />
}

type Declarations = Record<string, string | number>
type Size = string | { min: string; max: string }

/** A size token: one value, or fluid `{min, max}` between phone and desktop. */
export function SizeField({ field, value, onChange, disabled }: FieldProps<Size | null>) {
  const fluid = !!value && typeof value === 'object' && 'min' in value
  const unitOf = (v: string) => v.match(/[a-z%]+$/i)?.[0] ?? ''
  // Phone and desktop in one unit name it once, after the second: the numbers get the room.
  const shared = fluid && unitOf(String(value.min)) === unitOf(String(value.max))
  const sub = (handle: string, label: string): FieldDef => ({ ...field, handle: `${field.handle}-${handle}`, label, unit: 'rem', units: handle === 'min' && shared ? [] : ['rem', 'px', 'em', '%', 'vw'], step: 0.125 })
  return (
    <div className="flex min-w-0 flex-1 items-center gap-1">
      {fluid ? (
        <>
          <div className="min-w-0 flex-1" title={t('Phone')}><ValueInput field={sub('min', t('Phone'))} value={value.min} disabled={disabled} onChange={(v) => onChange({ ...value, min: v ?? '0' })} /></div>
          <Icon name="arrow-right" size={10} className="shrink-0 text-faint" />
          <div className="min-w-0 flex-1" title={t('Desktop')}><ValueInput field={sub('max', t('Desktop'))} value={value.max} disabled={disabled} onChange={(v) => onChange({ ...value, max: v ?? '0' })} /></div>
        </>
      ) : (
        <div className="min-w-0 flex-1"><ValueInput field={{ ...field, unit: 'rem', units: ['rem', 'px', 'em', '%', 'vw'], step: 0.125 }} value={value ?? null} disabled={disabled} onChange={(v) => onChange(v ?? '0')} /></div>
      )}
      <Tooltip content={fluid ? t('Use One Size') : t('Scale from Phone to Desktop')}>
        <IconButton icon="width" label={t('Fluid')} size="sm" active={fluid} disabled={disabled} onClick={() => onChange(fluid ? value.max : { min: String(value ?? ''), max: String(value ?? '') })} />
      </Tooltip>
    </div>
  )
}

/** What a font family falls back to: the system's own fonts of its kind. */
const FALLBACKS = { sans: ['ui-sans-serif', 'system-ui', 'sans-serif'], serif: ['ui-serif', 'Georgia', 'serif'], mono: ['ui-monospace', 'SF Mono', 'Menlo', 'monospace'] } as const
type Fallback = keyof typeof FALLBACKS
const SYSTEM_FONTS = new Set<string>([...Object.values(FALLBACKS).flat(), '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Helvetica', 'Arial', 'Times New Roman', 'Times', 'Courier New', 'Consolas', 'Monaco', 'cursive', 'fantasy'])

function fallbackOf(stack: string[]): Fallback {
  if (stack.includes('monospace')) return 'mono'
  return stack.includes('serif') ? 'serif' : 'sans'
}

/**
 * A font family token (`["Inter", "ui-sans-serif", "system-ui", "sans-serif"]`): the
 * site's font, or the system's, and what it falls back to. `families` are the fonts the
 * site serves.
 */
export function FontStackField({ field, value, onChange, disabled }: FieldProps<string[] | string | null>) {
  const stack = Array.isArray(value) ? value : String(value ?? '').split(',').map((s) => s.trim().replace(/^["']|["']$/g, '')).filter(Boolean)
  const first = stack[0] && !SYSTEM_FONTS.has(stack[0]) ? stack[0] : ''
  const fallback = fallbackOf(stack)
  const families = [...new Set([...((field.families as string[] | undefined) ?? []), ...(first ? [first] : [])])]
  const set = (family: string, kind: Fallback) => onChange([...(family ? [family] : []), ...FALLBACKS[kind]])
  return (
    <div className="flex min-w-0 flex-1 items-center gap-1">
      <Select id={fieldControlId(field)} size="sm" className="min-w-0 flex-[3]" aria-label={t('Font')} disabled={disabled} value={first || 'system'} onChange={(v) => set(v === 'system' || !v ? '' : v, fallback)} options={[{ value: 'system', label: t('System') }, ...families.map((family) => ({ value: family, label: family }))]} />
      <Select size="sm" className="min-w-0 flex-[2]" aria-label={t('Fallback')} disabled={disabled} value={fallback} onChange={(v) => set(first, (v as Fallback) ?? 'sans')} options={[{ value: 'sans', label: t('Sans') }, { value: 'serif', label: t('Serif') }, { value: 'mono', label: t('Mono') }]} />
    </div>
  )
}

/** Common properties offered while typing one (any camelCase CSS property works). */
const PROPERTIES = [
  'color', 'backgroundColor', 'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight', 'letterSpacing', 'textTransform', 'textDecoration', 'textUnderlineOffset', 'textWrap',
  'marginBlock', 'marginBlockStart', 'marginBlockEnd', 'padding', 'paddingInline', 'paddingBlock', 'border', 'borderColor', 'borderWidth', 'borderRadius', 'boxShadow', 'opacity', 'transition',
]

/**
 * CSS declarations of one element or state: property/value rows. `tokens` (token
 * references) are offered for values; `removable` adds a Remove action that clears it (null).
 */
export function DeclarationsField({ field, value, onChange, disabled }: FieldProps<Declarations | null>) {
  const decl = value ?? {}
  const [adding, setAdding] = useState(false)
  const list = `site-decl-${field.handle.replace(/[^a-z0-9-]/gi, '-')}`
  const tokens = (field.tokens as string[] | undefined) ?? []
  const rename = (prop: string, next: string) => {
    if (!next || next === prop || next in decl) return
    onChange(Object.fromEntries(Object.entries(decl).map(([k, v]) => (k === prop ? [next, v] : [k, v]))))
  }
  return (
    <div className="cms-declarations group/decl py-2">
      <div className="flex h-6 items-center gap-1">
        <span className={cn('min-w-0 flex-1 truncate text-xs font-medium text-ink', field.mono !== false && 'font-mono')}>{field.label}</span>
        <IconButton icon="plus" label={t('Add Property')} size="xs" disabled={disabled} onClick={() => setAdding(true)} />
        {field.removable === true && <IconButton icon="trash" label={t('Remove')} size="xs" disabled={disabled} className="opacity-0 group-hover/decl:opacity-100 focus-visible:opacity-100" onClick={() => onChange(null)} />}
      </div>
      <datalist id={`${list}-props`}>{PROPERTIES.filter((p) => !(p in decl)).map((p) => <option key={p} value={p} />)}</datalist>
      <datalist id={`${list}-values`}>{tokens.map((v) => <option key={v} value={v} />)}</datalist>
      <div className="mt-1 space-y-1">
        {Object.entries(decl).map(([prop, v]) => (
          <div key={prop} className="group/row grid grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_20px] items-center gap-1">
            <PropertyInput value={prop} list={`${list}-props`} disabled={disabled} onCommit={(next) => rename(prop, next)} />
            <Input size="xs" className="font-mono" spellCheck={false} list={`${list}-values`} aria-label={t('{property} value', { property: prop })} value={String(v)} disabled={disabled} onChange={(e) => onChange({ ...decl, [prop]: e.target.value })} />
            <button type="button" disabled={disabled} onClick={() => onChange(Object.fromEntries(Object.entries(decl).filter(([k]) => k !== prop)))} className="grid size-5 place-items-center rounded-xs text-faint opacity-0 hover:bg-hover hover:text-text group-hover/row:opacity-100 focus-visible:opacity-100" aria-label={t('Remove {property}', { property: prop })}>
              <Icon name="close" size={10} />
            </button>
          </div>
        ))}
        {adding && (
          <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_20px] items-center gap-1">
            <PropertyInput value="" autoFocus list={`${list}-props`} disabled={disabled} onCommit={(next) => { setAdding(false); if (next && !(next in decl)) onChange({ ...decl, [next]: '' }) }} />
          </div>
        )}
      </div>
    </div>
  )
}

/** A property name, committed on blur or Enter (renaming a key on every keystroke would reorder rows). */
function PropertyInput({ value, list, disabled, autoFocus, onCommit }: { value: string; list: string; disabled?: boolean; autoFocus?: boolean; onCommit: (next: string) => void }) {
  const [draft, setDraft] = useState(value)
  useEffect(() => setDraft(value), [value])
  return (
    <Input
      size="xs"
      className="font-mono"
      spellCheck={false}
      autoFocus={autoFocus}
      list={list}
      placeholder={t('property')}
      aria-label={t('Property')}
      value={draft}
      disabled={disabled}
      onChange={(e) => setDraft(e.target.value)}
      onBlur={() => onCommit(draft.trim())}
      onKeyDown={(e) => {
        if (e.key === 'Enter') (e.target as HTMLInputElement).blur()
        if (e.key === 'Escape') {
          setDraft(value)
          if (!value) onCommit('')
        }
      }}
    />
  )
}

/** Picks a local file (`accept`, `placeholder`) and hands it to onChange; nothing is stored. Files can be dropped on it. */
export function FileField({ field, onChange, disabled }: FieldProps<File | null>) {
  const [over, setOver] = useState(false)
  return (
    <label
      onDragOver={(e) => { e.preventDefault(); setOver(true) }}
      onDragLeave={() => setOver(false)}
      onDrop={(e) => { e.preventDefault(); setOver(false); const file = e.dataTransfer.files?.[0]; if (file && !disabled) onChange(file) }}
      className={cn('flex h-14 cursor-pointer items-center justify-center gap-2 rounded-sm border border-dashed text-xs text-muted transition-colors hover:bg-hover hover:text-text', over ? 'border-focus bg-hover' : 'border-border-strong')}
    >
      <Icon name="upload" size={14} />
      {(field.placeholder as string | undefined) ?? t('Choose a File')}
      <input id={fieldControlId(field)} type="file" accept={field.accept as string | undefined} disabled={disabled} className="hidden" onChange={(e) => { if (e.target.files?.[0]) onChange(e.target.files[0]); e.target.value = '' }} />
    </label>
  )
}

type Bezier = [number, number, number, number]

const KEYWORDS: Record<string, Bezier> = { linear: [0, 0, 1, 1], ease: [0.25, 0.1, 0.25, 1], 'ease-in': [0.42, 0, 1, 1], 'ease-out': [0, 0, 0.58, 1], 'ease-in-out': [0.42, 0, 0.58, 1] }

const presets = (): { label: string; value: string }[] => [
  { label: t('Ease Out'), value: 'cubic-bezier(0.2, 0.8, 0.2, 1)' },
  { label: t('Ease In-Out'), value: 'cubic-bezier(0.65, 0, 0.35, 1)' },
  { label: t('Ease In'), value: 'cubic-bezier(0.55, 0, 1, 0.45)' },
  { label: t('Overshoot'), value: 'cubic-bezier(0.34, 1.56, 0.64, 1)' },
  { label: t('Linear'), value: 'linear' },
]

export function bezier(value: string | null | undefined): Bezier | null {
  const v = String(value ?? '').trim()
  if (v in KEYWORDS) return KEYWORDS[v]
  const m = v.match(/^cubic-bezier\(([^)]+)\)$/)
  const n = m?.[1].split(',').map(Number)
  return n && n.length === 4 && n.every((x) => !Number.isNaN(x)) ? (n as Bezier) : null
}

const round = (n: number) => Math.round(n * 100) / 100
const format = ([x1, y1, x2, y2]: Bezier) => `cubic-bezier(${round(x1)}, ${round(y1)}, ${round(x2)}, ${round(y2)})`

/** The curve of an easing value, drawn in a box `size` wide (overshoot stays visible). */
export function EaseCurve({ value, size = 24, className }: { value: string | null | undefined; size?: number; className?: string }) {
  const [x1, y1, x2, y2] = bezier(value) ?? KEYWORDS.ease
  const pad = size / 6
  const p = (x: number, y: number) => `${pad + x * (size - 2 * pad)},${size - pad - y * (size - 2 * pad)}`
  return (
    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} className={cn('shrink-0 overflow-visible', className)} aria-hidden>
      <path d={`M${p(0, 0)} C${p(x1, y1)} ${p(x2, y2)} ${p(1, 1)}`} fill="none" stroke="currentColor" strokeWidth={size > 40 ? 2 : 1.5} strokeLinecap="round" />
    </svg>
  )
}

/** A trigger that looks like the inspector's inputs: a preview, what is chosen, a chevron. (A popover's trigger: it takes the props and ref it is given.) */
const PickerButton = forwardRef<HTMLButtonElement, ButtonHTMLAttributes<HTMLButtonElement> & { label: string; preview?: ReactNode }>(function PickerButton({ label, preview, children, className, ...props }, ref) {
  return (
    <button ref={ref} type="button" aria-label={label} {...props} className={cn('cms-input flex h-7 w-full min-w-0 items-center gap-1.5 rounded-sm pl-2 pr-1.5 text-left text-sm text-text transition-[border-color,box-shadow] duration-120 hover:bg-hover data-[popup-open]:border-focus disabled:opacity-50', className)}>
      {preview}
      <span className="min-w-0 flex-1 truncate">{children}</span>
      <Icon name="chevrons-up-down" size={11} className="shrink-0 text-faint" />
    </button>
  )
})

/** An easing curve: a named preset, or a custom curve drawn with two handles. */
export function EaseField({ field, value, onChange, disabled }: FieldProps<string | null>) {
  const preset = presets().find((p) => p.value === value?.replace(/\s+/g, ' ').trim())
  return (
    <Popover
      align="start"
      className="w-56 !p-0"
      trigger={
        <PickerButton id={fieldControlId(field)} disabled={disabled} label={field.label} preview={<EaseCurve value={value} size={14} className="text-muted" />}>
          {preset?.label ?? t('Custom')}
        </PickerButton>
      }
    >
      <CurveEditor value={value} onChange={onChange} />
    </Popover>
  )
}

function CurveEditor({ value, onChange }: { value: string | null; onChange: (v: string) => void }) {
  const box = useRef<SVGSVGElement>(null)
  const curve = bezier(value) ?? KEYWORDS.ease
  const size = 200
  const pad = 28
  const inner = size - 2 * pad
  const at = (x: number, y: number) => [pad + x * inner, size - pad - y * inner] as const

  const drag = (handle: 0 | 1) => (e: React.PointerEvent) => {
    const el = e.currentTarget as Element
    el.setPointerCapture(e.pointerId)
    const move = (ev: PointerEvent) => {
      const r = box.current!.getBoundingClientRect()
      const x = Math.max(0, Math.min(1, ((ev.clientX - r.left) / r.width * size - pad) / inner))
      const y = Math.max(-0.6, Math.min(1.6, (size - pad - (ev.clientY - r.top) / r.height * size) / inner))
      const next = [...curve] as Bezier
      next[handle * 2] = x
      next[handle * 2 + 1] = y
      onChange(format(next))
    }
    const up = () => {
      el.removeEventListener('pointermove', move as EventListener)
      el.removeEventListener('pointerup', up)
    }
    el.addEventListener('pointermove', move as EventListener)
    el.addEventListener('pointerup', up)
  }

  const [s0, s1] = at(0, 0)
  const [e0, e1] = at(1, 1)
  const [a0, a1] = at(curve[0], curve[1])
  const [b0, b1] = at(curve[2], curve[3])
  return (
    <div>
      <svg ref={box} viewBox={`0 0 ${size} ${size}`} className="block w-full touch-none select-none border-b border-border bg-sunken/60">
        <rect x={pad} y={pad} width={inner} height={inner} fill="none" stroke="var(--cms-border)" />
        <line x1={s0} y1={s1} x2={a0} y2={a1} stroke="var(--cms-faint)" />
        <line x1={e0} y1={e1} x2={b0} y2={b1} stroke="var(--cms-faint)" />
        <path d={`M${s0},${s1} C${a0},${a1} ${b0},${b1} ${e0},${e1}`} fill="none" stroke="var(--cms-ink)" strokeWidth={2} strokeLinecap="round" />
        {[[a0, a1], [b0, b1]].map(([x, y], i) => (
          <circle key={i} cx={x} cy={y} r={6} fill="var(--cms-bg)" stroke="var(--cms-ink)" strokeWidth={1.5} className="cursor-grab active:cursor-grabbing" onPointerDown={drag(i as 0 | 1)} />
        ))}
      </svg>
      <div className="flex flex-wrap gap-1 p-2">
        {presets().map((preset) => (
          <button key={preset.value} type="button" onClick={() => onChange(preset.value)} className={cn('flex h-6 items-center gap-1 rounded-xs px-1.5 text-2xs transition-colors', value === preset.value ? 'bg-active text-ink' : 'text-muted hover:bg-hover hover:text-text')}>
            <EaseCurve value={preset.value} size={12} />
            {preset.label}
          </button>
        ))}
      </div>
    </div>
  )
}

const SHADOW: FieldProps['field'] = { handle: 'shadow', type: 'text', component: 'blocks-shadows', label: 'Shadow' }

const shadows = (): { label: string; value: string }[] => [
  { label: t('None'), value: 'none' },
  { label: t('Small'), value: '0 1px 2px rgb(0 0 0 / 0.06)' },
  { label: t('Medium'), value: '0 4px 16px -4px rgb(0 0 0 / 0.12)' },
  { label: t('Large'), value: '0 24px 48px -16px rgb(0 0 0 / 0.2)' },
]

const ShadowSample = ({ value }: { value: string | null }) => <span className="size-3.5 shrink-0 rounded-[3px] border border-border bg-bg" style={{ boxShadow: value ?? undefined }} />

/** A box-shadow: a named preset, or a custom one made in the canvas's shadow editor (offsets, blur, spread, color). */
export function ShadowField({ field, value, onChange, disabled }: FieldProps<string | null>) {
  const preset = shadows().find((p) => p.value === value?.replace(/\s+/g, ' ').trim())
  return (
    <Popover
      align="start"
      className="w-72 p-1"
      trigger={
        <PickerButton id={fieldControlId(field)} disabled={disabled} label={field.label}>
          {preset?.label ?? t('Custom')}
        </PickerButton>
      }
    >
      <div className="space-y-px">
        {shadows().map((option) => (
          <button key={option.value} type="button" onClick={() => onChange(option.value)} className={cn('flex h-7 w-full items-center gap-2 rounded-xs px-2 text-left text-xs transition-colors', option.value === preset?.value ? 'bg-active text-ink' : 'text-text hover:bg-hover')}>
            <ShadowSample value={option.value} />
            <span className="flex-1">{option.label}</span>
            {option.value === preset?.value && <Icon name="check" size={12} className="text-muted" />}
          </button>
        ))}
      </div>
      <div className="mt-1 border-t border-border px-1 pb-1 pt-2">
        <p className="m-0 mb-1 text-2xs font-medium text-muted">{t('Custom')}</p>
        <Fields bare fields={[SHADOW]} values={{ shadow: value && value !== 'none' ? value : '0 4px 16px 0 rgb(0 0 0 / 12%)' }} onChange={(_, v) => onChange(v ?? 'none')} />
        <button type="button" onClick={() => onChange(`${value && value !== 'none' ? `${value}, ` : ''}0 2px 8px 0 rgb(0 0 0 / 10%)`)} className="mt-1.5 flex h-6 w-full items-center justify-center gap-1 rounded-xs text-2xs text-muted hover:bg-hover hover:text-text">
          <Icon name="plus" size={11} />
          {t('Add Layer')}
        </button>
      </div>
    </Popover>
  )
}
