import { t, type FieldProps } from '@letterncms/sdk'
import { fieldControlId, Fields, Icon, IconButton, Input, Popover, Tooltip, cn } from '@letterncms/ui'
import { useEffect, useRef, useState } from 'react'

/* Field components of the design system editor — the controls core fields can't
   express: fluid sizes, font stacks, CSS declarations, easing curves, shadows and
   font files. Compact (xs) like the canvas inspector. */

type Declarations = Record<string, string | number>
type Size = string | { min: string; max: string }

/** A size token: one value, or fluid `{min, max}` between phone and desktop. */
export function SizeField({ field, value, onChange, disabled }: FieldProps<Size | null>) {
  const fluid = !!value && typeof value === 'object' && 'min' in value
  return (
    <div className="flex min-w-0 items-center gap-1">
      {fluid ? (
        <>
          <Input id={fieldControlId(field)} size="xs" className="min-w-0 flex-1 font-mono" aria-label={t('Phone')} title={t('Phone')} value={value.min} disabled={disabled} onChange={(e) => onChange({ ...value, min: e.target.value })} />
          <Icon name="arrow-right" size={10} className="shrink-0 text-faint" />
          <Input size="xs" className="min-w-0 flex-1 font-mono" aria-label={t('Desktop')} title={t('Desktop')} value={value.max} disabled={disabled} onChange={(e) => onChange({ ...value, max: e.target.value })} />
        </>
      ) : (
        <Input id={fieldControlId(field)} size="xs" className="min-w-0 flex-1 font-mono" aria-label={field.label} placeholder={field.placeholder as string | undefined} value={String(value ?? '')} disabled={disabled} onChange={(e) => onChange(e.target.value)} />
      )}
      <Tooltip content={fluid ? t('Use One Size') : t('Scale from phone to desktop')}>
        <IconButton icon="width" label={t('Fluid')} size="xs" active={fluid} disabled={disabled} onClick={() => onChange(fluid ? value.max : { min: String(value ?? ''), max: String(value ?? '') })} />
      </Tooltip>
    </div>
  )
}

const families = (text: string) => text.split(',').map((s) => s.trim()).filter(Boolean)

/** A font stack (`["Inter", "system-ui"]`), typed comma-separated. */
export function FontStackField({ field, value, onChange, disabled }: FieldProps<string[] | string | null>) {
  const stack = Array.isArray(value) ? value.join(', ') : String(value ?? '')
  const [text, setText] = useState(stack)
  // Keep what is being typed ("Inter, ") unless the value changed elsewhere (discard, save).
  useEffect(() => setText((current) => (families(current).join(', ') === stack ? current : stack)), [stack])
  return <Input id={fieldControlId(field)} size="xs" className="font-mono" aria-label={field.label} spellCheck={false} value={text} disabled={disabled} onChange={(e) => { setText(e.target.value); onChange(families(e.target.value)) }} />
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
        {!adding && Object.keys(decl).length === 0 && <p className="m-0 text-2xs text-faint">{t('No properties')}</p>}
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
  { label: t('Out'), value: 'cubic-bezier(0.2, 0.8, 0.2, 1)' },
  { label: t('In-out'), value: 'cubic-bezier(0.65, 0, 0.35, 1)' },
  { label: t('In'), value: 'cubic-bezier(0.55, 0, 1, 0.45)' },
  { label: t('Back'), value: 'cubic-bezier(0.34, 1.56, 0.64, 1)' },
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

/** An easing curve: the CSS value, and a curve editor with two handles and presets. */
export function EaseField({ field, value, onChange, disabled }: FieldProps<string | null>) {
  return (
    <div className="flex min-w-0 items-center gap-1">
      <Popover
        align="start"
        className="w-56 !p-0"
        trigger={
          <button type="button" disabled={disabled} aria-label={t('Edit Curve')} className="cms-focus-ring grid size-6 shrink-0 place-items-center rounded-sm border border-border-strong bg-bg text-focus hover:bg-hover">
            <EaseCurve value={value} size={16} />
          </button>
        }
      >
        <CurveEditor value={value} onChange={onChange} />
      </Popover>
      <Input id={fieldControlId(field)} size="xs" className="min-w-0 flex-1 font-mono" spellCheck={false} aria-label={field.label} placeholder={field.placeholder as string | undefined} value={value ?? ''} disabled={disabled} onChange={(e) => onChange(e.target.value)} />
    </div>
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
          <circle key={i} cx={x} cy={y} r={6} fill="var(--cms-bg)" stroke="var(--cms-focus)" strokeWidth={2} className="cursor-grab active:cursor-grabbing" onPointerDown={drag(i as 0 | 1)} />
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

/** A box-shadow: the CSS value, and the canvas's shadow editor (offsets, blur, spread, color) in a popover. */
export function ShadowField({ field, value, onChange, disabled }: FieldProps<string | null>) {
  return (
    <div className="flex min-w-0 items-center gap-1">
      <Popover
        align="start"
        className="w-72 p-2"
        trigger={
          <button type="button" disabled={disabled} aria-label={t('Edit Shadow')} className="cms-focus-ring grid size-6 shrink-0 place-items-center rounded-sm border border-border-strong bg-bg text-muted hover:bg-hover hover:text-text">
            <Icon name="shadow" size={12} />
          </button>
        }
      >
        <Fields bare fields={[SHADOW]} values={{ shadow: value || '0 4px 16px 0 rgb(0 0 0 / 12%)' }} onChange={(_, v) => onChange(v ?? 'none')} />
        <button type="button" onClick={() => onChange(`${value && value !== 'none' ? `${value}, ` : ''}0 2px 8px 0 rgb(0 0 0 / 10%)`)} className="mt-1.5 flex h-6 w-full items-center justify-center gap-1 rounded-xs text-2xs text-muted hover:bg-hover hover:text-text">
          <Icon name="plus" size={11} />
          {t('Add Layer')}
        </button>
      </Popover>
      <Input id={fieldControlId(field)} size="xs" className="min-w-0 flex-1 font-mono" spellCheck={false} aria-label={field.label} placeholder={field.placeholder as string | undefined} value={value ?? ''} disabled={disabled} onChange={(e) => onChange(e.target.value)} />
    </div>
  )
}
