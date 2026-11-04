import { t, type FieldProps } from '@letterncms/sdk'
import { Popover, cn } from '@letterncms/ui'
import { useEffect, useRef, useState } from 'react'
import { useTokens } from '../../core/context'
import { SubField } from './controls'
import { useTokenOf } from './tokens'

/* `blocks-spacing` — the box model, like Webflow: margin around padding around the content.
   Each side shows its value (blue when set here, orange when inherited, a token as its name);
   drag a side to scrub, click it to type or pick a token. Alt changes the opposite side too,
   Shift all four. */

type Box = 'margin' | 'padding'
type Sides = (string | undefined)[]
export type SideStatus = 'own' | 'inherited' | 'none'

const NUMBER = /^(-?\d*\.?\d+)([a-z%]*)$/i

/** Which sides a change reaches: the one, it and its opposite (Alt), or all four (Shift). */
function reach(i: number, e: { altKey: boolean; shiftKey: boolean }): number[] {
  if (e.shiftKey) return [0, 1, 2, 3]
  if (e.altKey) return [i, (i + 2) % 4]
  return [i]
}

function title(box: Box, sides: number[]): string {
  if (sides.length === 4) return box === 'margin' ? t('Margin, All Sides') : t('Padding, All Sides')
  if (sides.length === 2) return sides.includes(0) ? (box === 'margin' ? t('Margin, Top and Bottom') : t('Padding, Top and Bottom')) : box === 'margin' ? t('Margin, Left and Right') : t('Padding, Left and Right')
  const side = { 0: t('top'), 1: t('right'), 2: t('bottom'), 3: t('left') }[sides[0]]
  return box === 'margin' ? t('Margin {side}', { side }) : t('Padding {side}', { side })
}

/**
 * The value is `{ margin, padding }`, four sides each (undefined when not set); a change
 * sends the box with its new sides. `sideStatus` (per box, per side) colors the values.
 */
export function SpacingField({ field, value, onChange, context }: FieldProps<Record<Box, Sides | string> | null>) {
  const current = value ?? { margin: [], padding: [] }
  const status = (field.sideStatus ?? {}) as Partial<Record<Box, SideStatus[]>>
  const sidesOf = (box: Box): Sides => (Array.isArray(current[box]) ? (current[box] as Sides) : [])
  const set = (box: Box, which: number[], v: string | null) => onChange({ ...current, [box]: [0, 1, 2, 3].map((j) => (which.includes(j) ? v ?? undefined : sidesOf(box)[j])) })

  const side = (box: Box, i: number) => <SideValue key={`${box}${i}`} box={box} index={i} value={sidesOf(box)[i]} status={status[box]?.[i] ?? 'none'} onChange={(which, v) => set(box, which, v)} context={context} />

  return (
    <div className="min-w-0 flex-1 select-none">
      <div className="relative grid grid-cols-[44px_1fr_44px] grid-rows-[24px_auto_24px] rounded-md bg-active/60">
        <span className="pointer-events-none absolute left-2 top-1 text-2xs text-faint">{t('Margin')}</span>
        <span />
        {side('margin', 0)}
        <span />
        {side('margin', 3)}
        <div className="relative grid grid-cols-[44px_1fr_44px] grid-rows-[24px_20px_24px] rounded-sm bg-bg shadow-[0_0_0_1px_var(--cms-border)]">
          <span className="pointer-events-none absolute left-2 top-1 text-2xs text-faint">{t('Padding')}</span>
          <span />
          {side('padding', 0)}
          <span />
          {side('padding', 3)}
          <span className="my-1 rounded-xs bg-active" />
          {side('padding', 1)}
          <span />
          {side('padding', 2)}
          <span />
        </div>
        {side('margin', 1)}
        <span />
        {side('margin', 2)}
        <span />
      </div>
    </div>
  )
}

function SideValue({ box, index, value, status, onChange, context }: { box: Box; index: number; value?: string; status: SideStatus; onChange: (sides: number[], v: string | null) => void; context: FieldProps['context'] }) {
  const tokenOf = useTokenOf()
  const [open, setOpen] = useState(false)
  const [sides, setSides] = useState<number[]>([index])
  const [dragging, setDragging] = useState(false)
  const chosen = tokenOf(value)
  const parts = value?.match(NUMBER)
  const shown = chosen ? chosen.token.name : parts ? (parts[2] === 'px' || !parts[2] ? parts[1] : `${parts[1]}${parts[2]}`) : value ?? '0'

  const down = (e: React.PointerEvent) => {
    if (e.button !== 0) return
    e.preventDefault()
    const start = { x: e.clientX, y: e.clientY }
    const base = parts ? parseFloat(parts[1]) : 0
    const unit = parts?.[2] || 'px'
    const numeric = !value || !!parts
    let moved = false
    const move = (ev: PointerEvent) => {
      const delta = ev.clientX - start.x - (ev.clientY - start.y)
      if (!moved && Math.abs(delta) < 3) return
      if (!numeric) return
      if (!moved) setDragging(true)
      moved = true
      document.body.style.cursor = index % 2 ? 'ew-resize' : 'ns-resize'
      const n = Math.round(base + Math.round(delta / 2))
      onChange(reach(index, ev), `${box === 'padding' ? Math.max(0, n) : n}${unit}`)
    }
    const up = () => {
      window.removeEventListener('pointermove', move)
      window.removeEventListener('pointerup', up)
      document.body.style.cursor = ''
      setDragging(false)
      if (!moved) return
      // A drag is not a click: swallow the click that follows, so the editor stays shut.
      const swallow = (ev: MouseEvent) => {
        ev.stopPropagation()
        ev.preventDefault()
      }
      window.addEventListener('click', swallow, { capture: true, once: true })
      setTimeout(() => window.removeEventListener('click', swallow, { capture: true }), 0)
    }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', up)
  }

  return (
    <Popover
      open={open}
      onOpenChange={setOpen}
      align="center"
      className="w-60 !p-2"
      trigger={
        <button
          type="button"
          onPointerDown={down}
          onClick={(e) => setSides(reach(index, e))}
          aria-label={title(box, [index])}
          title={`${title(box, [index])}${chosen ? ` · ${chosen.token.path}` : ''}\n${t('Drag to change. Alt: opposite side too, Shift: all sides.')}`}
          className={cn(
            'mx-auto flex h-full min-w-0 max-w-full items-center justify-center rounded-xs px-1 text-xs tabular-nums outline-none transition-colors focus-visible:ring-2 focus-visible:ring-focus',
            index % 2 ? 'cursor-ew-resize' : 'cursor-ns-resize',
            status === 'own' ? 'font-medium text-ink' : status === 'inherited' ? 'text-text' : 'text-faint',
            (open || dragging) ? 'bg-active' : 'hover:bg-hover',
          )}
        >
          <span className={cn('truncate', chosen && 'rounded-[3px] px-1 leading-4', chosen && 'bg-active')}>{shown}</span>
        </button>
      }
    >
      <SideEditor box={box} sides={sides} value={value} onChange={(v) => onChange(sides, v)} close={() => setOpen(false)} context={context} />
    </Popover>
  )
}

const PRESETS = ['0', '4px', '8px', '12px', '16px', '24px', '32px', '48px', '64px', '96px']

/** Type a value, pick a space token, or a common size. */
function SideEditor({ box, sides, value, onChange, close, context }: { box: Box; sides: number[]; value?: string; onChange: (v: string | null) => void; close: () => void; context: FieldProps['context'] }) {
  const { byGroup } = useTokens()
  const tokens = byGroup.get('space') ?? []
  const ref = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const input = ref.current?.querySelector('input')
    input?.focus()
  }, [])
  const pick = (v: string | null) => {
    onChange(v)
    close()
  }

  return (
    <div
      ref={ref}
      onKeyDown={(e) => {
        if (e.key === 'Enter') requestAnimationFrame(close)
      }}
    >
      <p className="m-0 mb-1.5 flex items-center justify-between text-xs font-semibold text-ink">
        {title(box, sides)}
        {value !== undefined && (
          <button type="button" onClick={() => pick(null)} className="rounded-xs px-1 text-2xs font-normal text-muted hover:bg-hover hover:text-text">
            {t('Reset')}
          </button>
        )}
      </p>
      <SubField
        field={{ handle: `${box}-${sides.join('')}`, type: 'text', component: 'blocks-value', label: title(box, sides), placeholder: '0', keywords: box === 'margin' ? ['auto'] : [], min: box === 'padding' ? 0 : undefined }}
        value={value ?? null}
        onChange={(v) => onChange(v as string | null)}
        context={context}
      />
      {tokens.length > 0 ? (
        <>
          <p className="cms-label m-0 mb-1 mt-2.5 !text-2xs">{t('Space Tokens')}</p>
          <div className="flex flex-wrap gap-1">
            {tokens.map((token) => (
              <button
                key={token.path}
                type="button"
                title={`${token.path} · ${typeof token.value === 'object' && token.value ? Object.values(token.value).join('–') : String(token.value)}`}
                onClick={() => pick(`token:${token.path}`)}
                className={cn('h-6 min-w-7 rounded-xs px-1.5 text-2xs tabular-nums', value === `token:${token.path}` ? 'bg-focus text-white' : 'bg-sunken text-text hover:bg-hover')}
              >
                {token.name}
              </button>
            ))}
          </div>
        </>
      ) : (
        <div className="mt-2 flex flex-wrap gap-1">
          {PRESETS.map((p) => (
            <button key={p} type="button" onClick={() => pick(p)} className={cn('h-6 min-w-7 rounded-xs px-1.5 text-2xs tabular-nums', value === p ? 'bg-focus text-white' : 'bg-sunken text-text hover:bg-hover')}>
              {p.replace('px', '')}
            </button>
          ))}
        </div>
      )}
      {box === 'margin' && (
        <button type="button" onClick={() => pick('auto')} className={cn('mt-1 h-6 rounded-xs px-1.5 text-2xs', value === 'auto' ? 'bg-focus text-white' : 'bg-sunken text-text hover:bg-hover')}>
          {t('Auto')}
        </button>
      )}
    </div>
  )
}
