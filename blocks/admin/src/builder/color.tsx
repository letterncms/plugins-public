import { t, type FieldProps } from '@letterncms/sdk'
import { fieldControlId, Icon, Popover, Segmented, cn } from '@letterncms/ui'
import { useEffect, useRef, useState } from 'react'
import { useTokens } from '../core/context'
import { Swatch, inputClass } from './controls'
import { Control, useInvalid } from './fields/controls'
import { TokenChip, useTokenOf } from './fields/tokens'

/* Color values: a token (token:color.primary, optionally /alpha), hex, rgb() or any CSS color. */

interface Rgba {
  r: number
  g: number
  b: number
  a: number
}

export function parseColor(input: string | undefined): Rgba | null {
  if (!input) return null
  const v = input.trim()
  let m = v.match(/^#([0-9a-f]{3,8})$/i)
  if (m) {
    let h = m[1]
    if (h.length === 3 || h.length === 4) h = h.split('').map((c) => c + c).join('')
    return { r: parseInt(h.slice(0, 2), 16), g: parseInt(h.slice(2, 4), 16), b: parseInt(h.slice(4, 6), 16), a: h.length === 8 ? parseInt(h.slice(6, 8), 16) / 255 : 1 }
  }
  m = v.match(/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)$/i)
  if (m) return { r: +m[1], g: +m[2], b: +m[3], a: m[4] ? (m[4].endsWith('%') ? parseFloat(m[4]) / 100 : +m[4]) : 1 }
  if (v === 'transparent') return { r: 0, g: 0, b: 0, a: 0 }
  if (v === 'white') return { r: 255, g: 255, b: 255, a: 1 }
  if (v === 'black') return { r: 0, g: 0, b: 0, a: 1 }
  return null
}

export function formatColor({ r, g, b, a }: Rgba): string {
  const hex = (n: number) => Math.round(n).toString(16).padStart(2, '0')
  return a >= 1 ? `#${hex(r)}${hex(g)}${hex(b)}` : `rgb(${Math.round(r)} ${Math.round(g)} ${Math.round(b)} / ${Math.round(a * 100)}%)`
}

function toHsv({ r, g, b }: Rgba) {
  r /= 255
  g /= 255
  b /= 255
  const max = Math.max(r, g, b)
  const d = max - Math.min(r, g, b)
  const h = d === 0 ? 0 : max === r ? ((g - b) / d) % 6 : max === g ? (b - r) / d + 2 : (r - g) / d + 4
  return { h: (h * 60 + 360) % 360, s: max === 0 ? 0 : d / max, v: max }
}

function fromHsv(h: number, s: number, v: number, a: number): Rgba {
  const f = (n: number) => {
    const k = (n + h / 60) % 6
    return (v - v * s * Math.max(0, Math.min(k, 4 - k, 1))) * 255
  }
  return { r: f(5), g: f(3), b: f(1), a }
}

/** Whether the browser can draw this color (tokens count: the design system defines them). */
export function validColor(value: string): boolean {
  if (/^token:[a-z]+\.[a-z0-9-]+(\/\d{1,3})?$/.test(value)) return true
  return typeof CSS !== 'undefined' && CSS.supports('color', value)
}

/** What a color value looks like (tokens resolved to their light value). */
export function useColorPreview() {
  const { byPath } = useTokens()
  return (value: string | undefined): string => {
    if (!value) return 'transparent'
    return value.replace(/token:([a-z]+\.[a-z0-9-]+)(?:\/(\d+))?/g, (_, path, alpha) => {
      const token = byPath.get(path)
      const base = token ? String(token.value) : 'transparent'
      return alpha ? `color-mix(in srgb, ${base} ${alpha}%, transparent)` : base
    })
  }
}

/**
 * `blocks-color` — a color value with a picker (HSV, alpha) and the theme's color tokens.
 * Core color has neither tokens nor alpha.
 */
export function ColorField({ field, value: raw, onChange }: FieldProps<string | null>) {
  const [open, setOpen] = useState(false)
  const preview = useColorPreview()
  const tokenOf = useTokenOf()
  const value = raw ?? undefined
  const chosen = tokenOf(value)

  return (
    <Control>
      <Popover
        open={open}
        onOpenChange={setOpen}
        align="end"
        className="w-64 !p-0"
        trigger={
          <button type="button" id={fieldControlId(field)} className={cn(inputClass, 'flex items-center gap-1.5 pl-1 text-left')}>
            <Swatch color={preview(value)} className="!size-4" />
            {chosen ? (
              <TokenChip token={chosen.token} alpha={chosen.alpha} />
            ) : (
              <span className={cn('truncate font-mono', !value && 'font-sans text-faint')}>{value ?? field.placeholder ?? t('None')}</span>
            )}
          </button>
        }
      >
        <ColorPicker label={field.label || t('Color')} value={value} onChange={onChange} />
      </Popover>
    </Control>
  )
}

/**
 * The theme's color tokens, listed by name, or a custom color (HSV area, hue, alpha, hex).
 * It opens on the tokens unless the value is a custom color.
 */
function ColorPicker({ label, value, onChange }: { label: string; value?: string; onChange: (v: string | null) => void }) {
  const { byGroup } = useTokens()
  const tokens = byGroup.get('color') ?? []
  const preview = useColorPreview()
  const current = value?.startsWith('token:') ? value.slice(6).split('/')[0] : null
  const parsed = parseColor(value?.startsWith('token:') ? preview(value.split('/')[0]) : value) ?? { r: 0, g: 0, b: 0, a: 1 }
  const [hsv, setHsv] = useState(() => ({ ...toHsv(parsed), a: value?.includes('/') && value.startsWith('token:') ? Number(value.split('/')[1]) / 100 : parsed.a }))
  const [text, setText] = useState(value ?? '')
  const invalid = useInvalid(text, (v) => !v.trim() || validColor(v.trim()))
  const [tab, setTab] = useState<'tokens' | 'custom'>(() => (tokens.length > 0 && (!value || value.startsWith('token:')) ? 'tokens' : 'custom'))
  const area = useRef<HTMLDivElement>(null)

  useEffect(() => setText(value ?? ''), [value])

  const emit = (h: number, s: number, v: number, a: number) => {
    setHsv({ h, s, v, a })
    onChange(formatColor(fromHsv(h, s, v, a)))
  }

  const drag = (e: React.PointerEvent, kind: 'area' | 'hue' | 'alpha') => {
    const el = e.currentTarget as HTMLElement
    el.setPointerCapture(e.pointerId)
    const apply = (ev: { clientX: number; clientY: number }) => {
      const r = el.getBoundingClientRect()
      const x = Math.max(0, Math.min(1, (ev.clientX - r.left) / r.width))
      const y = Math.max(0, Math.min(1, (ev.clientY - r.top) / r.height))
      if (kind === 'area') emit(hsv.h, x, 1 - y, hsv.a)
      else if (kind === 'hue') emit(x * 360, hsv.s, hsv.v, hsv.a)
      else if (value?.startsWith('token:')) onChange(`${value.split('/')[0]}${x < 0.995 ? `/${Math.round(x * 100)}` : ''}`)
      else emit(hsv.h, hsv.s, hsv.v, Math.round(x * 100) / 100)
    }
    apply(e)
    const move = (ev: PointerEvent) => apply(ev)
    const up = () => {
      el.removeEventListener('pointermove', move)
      el.removeEventListener('pointerup', up)
    }
    el.addEventListener('pointermove', move)
    el.addEventListener('pointerup', up)
  }

  // A token's opacity lives in its value (token:color.primary/60).
  const alpha = current ? (value?.includes('/') ? Number(value.split('/')[1]) / 100 : 1) : hsv.a
  const solid = current ? preview(`token:${current}`) : formatColor({ ...fromHsv(hsv.h, hsv.s, hsv.v, 1) })
  const alphaBar = (
    <div onPointerDown={(e) => drag(e, 'alpha')} className="relative h-2.5 cursor-ew-resize rounded-full [background:repeating-conic-gradient(#ddd_0_25%,#fff_0_50%)_0_0/8px_8px]">
      <span className="absolute inset-0 rounded-full" style={{ background: `linear-gradient(to right, transparent, ${solid})` }} />
      <span className="pointer-events-none absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow-[0_0_0_1px_rgb(0_0_0/0.3)]" style={{ left: `${alpha * 100}%` }} />
    </div>
  )
  const clear = <button type="button" onClick={() => onChange(null)} className="h-7 shrink-0 rounded-sm px-2 text-xs text-muted hover:bg-hover hover:text-ink">{t('Clear')}</button>

  return (
    <div>
      <div className="flex h-9 items-center gap-2 border-b border-border pl-2.5 pr-1.5">
        <span className="min-w-0 flex-1 truncate text-xs font-medium text-text">{label}</span>
        {tokens.length > 0 && <Segmented size="xs" value={tab} onChange={setTab} options={[{ value: 'tokens', label: t('Tokens') }, { value: 'custom', label: t('Custom') }]} />}
      </div>
      {tab === 'tokens' ? (
        <div>
          <div role="listbox" aria-label={t('Color Tokens')} className="max-h-60 overflow-y-auto p-1">
            {tokens.map((token) => (
              <button
                key={token.path}
                type="button"
                role="option"
                aria-selected={current === token.path}
                title={`${token.path} · ${String(token.value)}${token.dark ? ` / ${String(token.dark)}` : ''}`}
                onClick={() => onChange(`token:${token.path}`)}
                className={cn('flex h-7 w-full items-center gap-2 rounded-xs px-1.5 text-left text-xs hover:bg-hover', current === token.path && 'bg-active')}
              >
                <Swatch color={String(token.value)} dark={token.dark ? String(token.dark) : undefined} className="!size-4" />
                <span className="min-w-0 flex-1 truncate text-text">{token.name}</span>
                <span className="max-w-[45%] shrink-0 truncate font-mono text-2xs text-faint">{String(token.value)}</span>
                {current === token.path ? <Icon name="b-check" size={12} className="shrink-0 text-focus" /> : <span className="w-3 shrink-0" />}
              </button>
            ))}
          </div>
          <div className="flex items-center gap-2 border-t border-border px-2.5 py-2">
            {current ? (
              <>
                <span className="shrink-0 text-xs text-muted">{t('Opacity')}</span>
                <div className="min-w-0 flex-1">{alphaBar}</div>
                <span className="w-8 shrink-0 text-right text-2xs tabular-nums text-muted">{Math.round(alpha * 100)}%</span>
              </>
            ) : (
              <span className="flex-1" />
            )}
            {clear}
          </div>
        </div>
      ) : (
        <div className="space-y-2 p-2.5">
          <div ref={area} onPointerDown={(e) => drag(e, 'area')} className="relative h-32 cursor-crosshair rounded-sm" style={{ background: `linear-gradient(to top, #000, transparent), linear-gradient(to right, #fff, hsl(${hsv.h} 100% 50%))` }}>
            <span className="pointer-events-none absolute size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow-[0_0_0_1px_rgb(0_0_0/0.3)]" style={{ left: `${hsv.s * 100}%`, top: `${(1 - hsv.v) * 100}%` }} />
          </div>
          <div onPointerDown={(e) => drag(e, 'hue')} className="relative h-2.5 cursor-ew-resize rounded-full" style={{ background: 'linear-gradient(to right, red, yellow, lime, cyan, blue, magenta, red)' }}>
            <span className="pointer-events-none absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow-[0_0_0_1px_rgb(0_0_0/0.3)]" style={{ left: `${(hsv.h / 360) * 100}%` }} />
          </div>
          {alphaBar}
          <div className="flex gap-1.5">
            <input
              value={text}
              spellCheck={false}
              aria-label={t('Color Value')}
              aria-invalid={invalid.shown || undefined}
              onChange={(e) => setText(e.target.value)}
              // Only a color the browser can draw is kept; anything else goes back to the last one.
              onBlur={() => {
                const next = text.trim()
                if (next && !validColor(next)) return setText(value ?? '')
                if (next !== (value ?? '')) onChange(next || null)
              }}
              onKeyDown={(e) => {
                if (e.key === 'Escape') setText(value ?? '')
                if (e.key !== 'Enter') return
                if (text.trim() && !validColor(text.trim())) invalid.show()
                else (e.target as HTMLInputElement).blur()
              }}
              className={cn(inputClass, 'font-mono')}
              placeholder="#000000"
            />
            {clear}
          </div>
        </div>
      )}
    </div>
  )
}
