import { t, type FieldProps } from '@letterncms/sdk'
import { cn } from '@letterncms/ui'
import { useState } from 'react'
import { sides, splitValue } from '../css'
import { Control, SubField } from './controls'

/* `blocks-clip` — clip-path by shape: inset (with rounded corners), circle, ellipse, a polygon
   from a set of common shapes, or any value typed by hand. */

type Kind = 'none' | 'inset' | 'circle' | 'ellipse' | 'polygon' | 'custom'

const SHAPES: { id: string; label: () => string; value: string }[] = [
  { id: 'triangle', label: () => t('Triangle'), value: 'polygon(50% 0%, 100% 100%, 0% 100%)' },
  { id: 'rhombus', label: () => t('Rhombus'), value: 'polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%)' },
  { id: 'pentagon', label: () => t('Pentagon'), value: 'polygon(50% 0%, 100% 38%, 82% 100%, 18% 100%, 0% 38%)' },
  { id: 'hexagon', label: () => t('Hexagon'), value: 'polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%)' },
  { id: 'arrow', label: () => t('Arrow'), value: 'polygon(0% 20%, 60% 20%, 60% 0%, 100% 50%, 60% 100%, 60% 80%, 0% 80%)' },
  { id: 'chevron', label: () => t('Chevron'), value: 'polygon(75% 0%, 100% 50%, 75% 100%, 0% 100%, 25% 50%, 0% 0%)' },
  { id: 'star', label: () => t('Star'), value: 'polygon(50% 0%, 61% 35%, 98% 35%, 68% 57%, 79% 91%, 50% 70%, 21% 91%, 32% 57%, 2% 35%, 39% 35%)' },
  { id: 'message', label: () => t('Message'), value: 'polygon(0% 0%, 100% 0%, 100% 75%, 75% 75%, 75% 100%, 50% 75%, 0% 75%)' },
  { id: 'slant', label: () => t('Slanted Edge'), value: 'polygon(0 0, 100% 0, 100% 85%, 0 100%)' },
  { id: 'wedge', label: () => t('Wedge'), value: 'polygon(0 0, 100% 0, 100% 100%, 0 85%)' },
]

const norm = (v: string) => v.replace(/\s+/g, ' ').replace(/\b0%/g, '0').trim()

function kindOf(value: string | undefined): Kind {
  if (!value || value === 'none') return 'none'
  const m = value.match(/^(inset|circle|ellipse|polygon)\(/)
  return m ? (m[1] as Kind) : 'custom'
}

const inner = (value: string | undefined, fn: string) => value?.match(new RegExp(`^${fn}\\((.*)\\)$`))?.[1] ?? ''

/** inset(t r b l round r) */
function parseInset(value?: string) {
  const [box, round] = inner(value, 'inset').split(/\s+round\s+/)
  const s = sides(box || '0') ?? ['0', '0', '0', '0']
  return { top: s[0], right: s[1], bottom: s[2], left: s[3], round: round ?? '' }
}

/** circle(r at x y) / ellipse(rx ry at x y) */
function parseRound(value: string | undefined, fn: 'circle' | 'ellipse') {
  const [size, at] = inner(value, fn).split(/\s+at\s+/)
  const radii = splitValue(size || '')
  const pos = splitValue(at || '50% 50%')
  return { rx: radii[0] ?? '50%', ry: radii[1] ?? radii[0] ?? '50%', x: pos[0] ?? '50%', y: pos[1] ?? '50%' }
}

export function ClipField({ field, value: raw, onChange, context, disabled }: FieldProps<string | null>) {
  const value = raw ?? undefined
  const [manual, setManual] = useState(false)
  const kind: Kind = manual && value ? 'custom' : kindOf(value)
  const sub = (handle: string, label: string, extra: Record<string, unknown> = {}) => ({ handle: `${field.handle}-${handle}`, type: 'text', component: 'blocks-value', label, ...extra })

  const choose = (k: Kind | null) => {
    setManual(k === 'custom')
    if (k === 'custom') return value ? undefined : onChange('inset(0)')
    if (!k || k === 'none') return onChange(null)
    if (k === 'inset') return onChange('inset(0 round 12px)')
    if (k === 'circle') return onChange('circle(50% at 50% 50%)')
    if (k === 'ellipse') return onChange('ellipse(50% 40% at 50% 50%)')
    onChange(SHAPES[0].value)
  }

  const inset = parseInset(value)
  const setInset = (patch: Partial<typeof inset>) => {
    const next = { ...inset, ...patch }
    const box = [next.top, next.right, next.bottom, next.left].map((v) => v || '0')
    const short = box.every((v) => v === box[0]) ? box[0] : box.join(' ')
    onChange(`inset(${short}${next.round ? ` round ${next.round}` : ''})`)
  }
  const round = kind === 'circle' || kind === 'ellipse' ? parseRound(value, kind) : null
  const setRound = (patch: Partial<NonNullable<typeof round>>) => {
    const next = { ...round!, ...patch }
    onChange(kind === 'circle' ? `circle(${next.rx} at ${next.x} ${next.y})` : `ellipse(${next.rx} ${next.ry} at ${next.x} ${next.y})`)
  }
  const shape = kind === 'polygon' ? SHAPES.find((s) => norm(s.value) === norm(value!)) : undefined

  return (
    <div className="min-w-0 flex-1 space-y-1">
      <SubField
        field={{
          handle: `${field.handle}-kind`,
          type: 'select',
          size: 'sm',
          label: field.label,
          placeholder: t('None'),
          options: [
            { value: 'inset', label: t('Inset') },
            { value: 'circle', label: t('Circle') },
            { value: 'ellipse', label: t('Ellipse') },
            { value: 'polygon', label: t('Shape') },
            { value: 'custom', label: t('Custom') },
          ],
        }}
        value={kind === 'none' ? null : kind}
        onChange={(k) => choose(k as Kind | null)}
        disabled={disabled}
        context={context}
      />
      {kind === 'inset' && (
        <>
          <Control className="grid grid-cols-2 gap-1">
            <SubField field={sub('top', t('Top'), { prefix: 'T', placeholder: '0', units: ['px', '%'] })} value={inset.top} onChange={(v) => setInset({ top: v ?? '0' })} context={context} />
            <SubField field={sub('right', t('Right'), { prefix: 'R', placeholder: '0', units: ['px', '%'] })} value={inset.right} onChange={(v) => setInset({ right: v ?? '0' })} context={context} />
            <SubField field={sub('bottom', t('Bottom'), { prefix: 'B', placeholder: '0', units: ['px', '%'] })} value={inset.bottom} onChange={(v) => setInset({ bottom: v ?? '0' })} context={context} />
            <SubField field={sub('left', t('Left'), { prefix: 'L', placeholder: '0', units: ['px', '%'] })} value={inset.left} onChange={(v) => setInset({ left: v ?? '0' })} context={context} />
          </Control>
          <SubField field={sub('round', t('Corner Radius'), { prefix: '◜', placeholder: t('Corners'), group: 'radius' })} value={inset.round || null} onChange={(v) => setInset({ round: v ?? '' })} context={context} />
        </>
      )}
      {round && (
        <Control className="grid grid-cols-2 gap-1">
          <SubField field={sub('rx', kind === 'circle' ? t('Radius') : t('Width Radius'), { prefix: kind === 'circle' ? 'R' : 'W', unit: '%', units: ['%', 'px'] })} value={round.rx} onChange={(v) => setRound({ rx: v ?? '50%' })} context={context} />
          {kind === 'ellipse' ? <SubField field={sub('ry', t('Height Radius'), { prefix: 'H', unit: '%', units: ['%', 'px'] })} value={round.ry} onChange={(v) => setRound({ ry: v ?? '50%' })} context={context} /> : <span />}
          <SubField field={sub('x', t('Center X'), { prefix: 'X', unit: '%', units: ['%', 'px'] })} value={round.x} onChange={(v) => setRound({ x: v ?? '50%' })} context={context} />
          <SubField field={sub('y', t('Center Y'), { prefix: 'Y', unit: '%', units: ['%', 'px'] })} value={round.y} onChange={(v) => setRound({ y: v ?? '50%' })} context={context} />
        </Control>
      )}
      {kind === 'polygon' && (
        <div className="grid grid-cols-5 gap-1">
          {SHAPES.map((s) => (
            <button
              key={s.id}
              type="button"
              title={s.label()}
              aria-label={s.label()}
              aria-pressed={shape?.id === s.id}
              onClick={() => onChange(s.value)}
              className={cn('grid aspect-square place-items-center rounded-xs border', shape?.id === s.id ? 'border-focus bg-focus/8' : 'border-border hover:bg-hover')}
            >
              <span className={cn('size-4', shape?.id === s.id ? 'bg-focus' : 'bg-muted')} style={{ clipPath: s.value }} />
            </button>
          ))}
        </div>
      )}
      {(kind === 'custom' || (kind === 'polygon' && !shape)) && (
        <SubField field={sub('raw', field.label as string, { unit: '', placeholder: 'polygon(…)' })} value={value ?? null} onChange={(v) => onChange((v as string | null) || null)} context={context} />
      )}
    </div>
  )
}
