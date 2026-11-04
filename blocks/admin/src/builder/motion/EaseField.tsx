import { t, type FieldProps } from '@letterncms/sdk'
import { fieldControlId, Icon, Popover, cn } from '@letterncms/ui'
import { useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'
import { EaseCurve, bezierOf } from './EaseCurve'
import { eases } from './presets'

const round = (n: number) => Math.round(n * 100) / 100
const format = ([x1, y1, x2, y2]: number[]) => `cubic-bezier(${round(x1)}, ${round(y1)}, ${round(x2)}, ${round(y2)})`

/**
 * `blocks-easing` — an animation's ease: named curves to pick from, each drawn, and
 * a curve editor for a custom cubic-bezier (drag the two handles).
 */
export function EaseField({ field, value, onChange, disabled }: FieldProps<string | null>) {
  const [open, setOpen] = useState(false)
  const list = eases()
  const current = value ?? undefined
  const named = list.find((e) => e.value === current)
  const label = named?.label ?? (current ? t('Custom') : (field.placeholder as string) || t('Smooth Out'))

  return (
    <Popover
      open={open}
      onOpenChange={(o) => !disabled && setOpen(o)}
      align="end"
      className="w-[248px] !p-0"
      trigger={
        <button
          type="button"
          id={fieldControlId(field)}
          disabled={disabled}
          aria-label={field.label}
          className="cms-input flex h-7 w-full min-w-0 items-center gap-1.5 rounded-sm pl-0.5 pr-2 text-left text-xs disabled:opacity-50"
        >
          <EaseCurve value={current} size={20} className="shrink-0 rounded-[3px] bg-sunken text-focus" />
          <span className={cn('min-w-0 flex-1 truncate', !value && 'text-muted')}>{label}</span>
          <Icon name="b-caret-down" size={12} className="shrink-0 text-faint" />
        </button>
      }
    >
      <div className="grid grid-cols-3 gap-1 p-1.5">
        {list.map((e) => (
          <button
            key={e.value}
            type="button"
            onClick={() => {
              onChange(e.value)
              setOpen(false)
            }}
            className={cn('flex flex-col items-center gap-1 rounded-xs px-1 pb-1 pt-1.5 text-2xs leading-tight hover:bg-hover', current === e.value ? 'bg-focus/12 text-focus' : 'text-muted')}
          >
            <EaseCurve value={e.value} size={36} className={cn('rounded-[3px]', current === e.value ? 'text-focus' : 'text-text')} />
            <span className="w-full truncate text-center">{e.label}</span>
          </button>
        ))}
      </div>
      <div className="border-t border-border p-2">
        <div className="mb-1.5 flex items-center justify-between">
          <span className="text-xs font-medium text-muted">{t('Custom Curve')}</span>
          <span className="font-mono text-2xs text-faint">{format(bezierOf(current)).replace('cubic-bezier', '')}</span>
        </div>
        <CurveEditor value={current} onChange={onChange} />
      </div>
    </Popover>
  )
}

/** A cubic-bezier with two draggable handles; overshoot (y outside 0–1) is allowed. */
function CurveEditor({ value, onChange }: { value?: string; onChange: (v: string) => void }) {
  const [points, setPoints] = useState<number[] | null>(null)
  const svg = useRef<SVGSVGElement>(null)
  const drag = useRef<number | null>(null)
  const [x1, y1, x2, y2] = points ?? bezierOf(value)
  const W = 232
  const H = 140
  const pad = 26
  const px = (x: number) => 8 + x * (W - 16)
  const py = (y: number) => H - pad - y * (H - pad * 2)

  const move = (e: ReactPointerEvent) => {
    if (drag.current === null || !svg.current) return
    const r = svg.current.getBoundingClientRect()
    const x = Math.min(1, Math.max(0, (e.clientX - r.left - 8) / (W - 16)))
    const y = Math.min(1.6, Math.max(-0.6, (H - pad - (e.clientY - r.top)) / (H - pad * 2)))
    const next = [x1, y1, x2, y2]
    next[drag.current * 2] = round(x)
    next[drag.current * 2 + 1] = round(y)
    setPoints(next)
  }
  const end = () => {
    if (drag.current !== null && points) onChange(format(points))
    drag.current = null
    setPoints(null)
  }
  const handle = (i: 0 | 1, x: number, y: number) => (
    <g>
      <line x1={px(i)} y1={py(i)} x2={px(x)} y2={py(y)} stroke="currentColor" className="text-faint" strokeWidth="1" />
      <circle
        cx={px(x)}
        cy={py(y)}
        r={5}
        className="cursor-grab fill-bg stroke-focus active:cursor-grabbing"
        strokeWidth="1.5"
        onPointerDown={(e) => {
          drag.current = i
          ;(e.target as Element).setPointerCapture(e.pointerId)
        }}
      />
    </g>
  )

  return (
    <svg ref={svg} width={W} height={H} viewBox={`0 0 ${W} ${H}`} className="block touch-none select-none rounded-xs bg-sunken" onPointerMove={move} onPointerUp={end} onPointerCancel={end} role="img" aria-label={t('Custom Curve')}>
      <line x1={px(0)} y1={py(0)} x2={px(1)} y2={py(0)} className="stroke-border-strong" strokeWidth="1" />
      <line x1={px(0)} y1={py(1)} x2={px(1)} y2={py(1)} className="stroke-border-strong" strokeWidth="1" strokeDasharray="2 3" />
      <line x1={px(0)} y1={py(0)} x2={px(1)} y2={py(1)} className="stroke-border" strokeWidth="1" />
      <path d={`M${px(0)},${py(0)} C${px(x1)},${py(y1)} ${px(x2)},${py(y2)} ${px(1)},${py(1)}`} fill="none" className="stroke-text" strokeWidth="2" strokeLinecap="round" />
      {handle(0, x1, y1)}
      {handle(1, x2, y2)}
    </svg>
  )
}
