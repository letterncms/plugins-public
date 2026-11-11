import { t } from '@letterncms/sdk'
import { cn } from '@letterncms/ui'
import { useEffect, useId, useRef, useState, type KeyboardEvent, type PointerEvent } from 'react'
import { longDate, shortDate } from './data'

const PAD = { top: 12, right: 12, bottom: 28, left: 48 }

/**
 * Round numbers for the y axis: 0 and two to four clean steps, the top as close to the maximum as they allow.
 * A count (`whole`) only has whole steps: one view is drawn against 0, 1 and 2, never against 0.5.
 */
function ticks(max: number, whole: boolean): number[] {
  if (max <= 0) return whole ? [0, 1, 2] : [0, 1, 2, 3]
  if (whole && max < 4) return Array.from({ length: Math.max(2, Math.ceil(max)) + 1 }, (_, i) => i)
  const magnitude = 10 ** Math.floor(Math.log10(max / 4))
  const steps = [1, 2, 2.5, 5, 10, 20].map((m) => m * magnitude).filter((s) => !whole || Number.isInteger(s))
  const fits = steps.map((step) => ({ step, count: Math.ceil(max / step) })).filter((c) => c.count >= 2 && c.count <= 4)
  const best = fits.sort((a, b) => a.step * a.count - b.step * b.count || a.count - b.count)[0] ?? { step: Math.ceil(max / 3), count: 3 }
  return Array.from({ length: best.count + 1 }, (_, i) => Number((i * best.step).toPrecision(12)))
}

/**
 * One metric over time: a 2px line over a soft wash, hairline grid, round y ticks and a
 * few dates. Hover or arrow keys move a crosshair that reads out the day; the numbers are
 * also in a table for screen readers.
 */
export function AreaChart({ points, format, label, height = 240, pending, whole = false }: { points: { date: string; value: number }[]; format: (value: number) => string; label: string; height?: number; pending?: boolean; /** The values are counts: the axis has whole numbers only. */ whole?: boolean }) {
  const box = useRef<HTMLDivElement>(null)
  const [width, setWidth] = useState(0)
  const [active, setActive] = useState<number | null>(null)
  const gradient = useId()

  useEffect(() => {
    const el = box.current
    if (!el) return
    const observer = new ResizeObserver(([entry]) => setWidth(entry.contentRect.width))
    observer.observe(el)
    return () => observer.disconnect()
  }, [])

  const n = points.length
  const values = points.map((p) => p.value)
  const scale = ticks(Math.max(...values, 0), whole)
  const top = scale[scale.length - 1]
  const plotW = Math.max(0, width - PAD.left - PAD.right)
  const plotH = height - PAD.top - PAD.bottom
  const x = (i: number) => PAD.left + (n <= 1 ? plotW / 2 : (i / (n - 1)) * plotW)
  const y = (v: number) => PAD.top + plotH - (top > 0 ? (v / top) * plotH : 0)
  const line = points.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.value).toFixed(1)}`).join('')
  const area = n > 1 ? `${line}L${x(n - 1).toFixed(1)},${y(0)}L${x(0).toFixed(1)},${y(0)}Z` : ''
  const labelCount = Math.max(2, Math.min(n, Math.floor(plotW / 84)))
  // Dates at an even step of days, ending on the last one.
  const every = Math.max(1, Math.ceil((n - 1) / Math.max(1, labelCount - 1)))
  const dateTicks = Array.from({ length: Math.ceil(n / every) }, (_, k) => n - 1 - k * every).reverse()

  const pick = (e: PointerEvent<SVGRectElement>) => {
    const rect = e.currentTarget.getBoundingClientRect()
    const ratio = (e.clientX - rect.left) / Math.max(1, rect.width)
    setActive(Math.max(0, Math.min(n - 1, Math.round(ratio * (n - 1)))))
  }

  const keys = (e: KeyboardEvent) => {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight' && e.key !== 'Home' && e.key !== 'End') return
    e.preventDefault()
    setActive((a) => {
      if (e.key === 'Home') return 0
      if (e.key === 'End') return n - 1
      const from = a ?? n - 1
      return Math.max(0, Math.min(n - 1, from + (e.key === 'ArrowLeft' ? -1 : 1)))
    })
  }

  const point = active !== null ? points[active] : null
  const today = n > 0 && point === points[n - 1]

  return (
    <div ref={box} className={cn('relative select-none transition-opacity duration-move', pending && 'opacity-60')}>
      {width > 0 && (
        <svg width={width} height={height} role="img" aria-label={label} tabIndex={0} onKeyDown={keys} onBlur={() => setActive(null)} className="block overflow-visible text-ink outline-none focus-visible:[&_.cms-focus]:stroke-focus">
          <defs>
            <linearGradient id={gradient} x1="0" x2="0" y1="0" y2="1">
              <stop offset="0" stopColor="currentColor" stopOpacity="0.1" />
              <stop offset="1" stopColor="currentColor" stopOpacity="0" />
            </linearGradient>
          </defs>

          {scale.map((tick) => (
            <g key={tick}>
              <line x1={PAD.left} x2={width - PAD.right} y1={y(tick)} y2={y(tick)} stroke="var(--cms-border)" strokeWidth={1} shapeRendering="crispEdges" />
              <text x={PAD.left - 10} y={y(tick)} dy="0.32em" textAnchor="end" className="fill-faint text-2xs tabular-nums">
                {format(tick)}
              </text>
            </g>
          ))}

          {dateTicks.map((i) => (
            <text key={i} x={x(i)} y={height - 8} textAnchor={i === 0 && n > 1 ? 'start' : i === n - 1 && n > 1 ? 'end' : 'middle'} className="fill-faint text-2xs tabular-nums">
              {shortDate(points[i].date)}
            </text>
          ))}

          {area && <path d={area} fill={`url(#${gradient})`} />}
          <path d={line} fill="none" stroke="currentColor" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />

          {point && active !== null && (
            <g pointerEvents="none">
              <line className="cms-focus" x1={x(active)} x2={x(active)} y1={PAD.top} y2={PAD.top + plotH} stroke="var(--cms-border-strong)" strokeWidth={1} shapeRendering="crispEdges" />
              <circle cx={x(active)} cy={y(point.value)} r={4} fill="currentColor" stroke="var(--cms-bg)" strokeWidth={2} />
            </g>
          )}

          <rect x={PAD.left} y={0} width={plotW} height={height} fill="transparent" onPointerMove={pick} onPointerDown={pick} onPointerLeave={() => setActive(null)} />
        </svg>
      )}

      {point && active !== null && (
        <div
          role="status"
          className="pointer-events-none absolute top-0 z-raised min-w-36 -translate-x-1/2 rounded-md border border-border-float bg-bg px-3 py-2 shadow-pop"
          style={{ left: Math.max(PAD.left + 64, Math.min(width - 64, x(active))) }}
        >
          <p className="m-0 text-2xs text-muted">{today ? t('Today so far') : longDate(point.date)}</p>
          <p className="m-0 mt-1 flex items-center gap-2 text-xs text-muted">
            <span className="h-0.5 w-3 rounded-full bg-ink" />
            <span className="text-sm font-semibold tabular-nums text-ink">{format(point.value)}</span>
            {label}
          </p>
        </div>
      )}

      <table className="sr-only">
        <caption>{label}</caption>
        <tbody>
          {points.map((p) => (
            <tr key={p.date}>
              <th scope="row">{longDate(p.date)}</th>
              <td>{format(p.value)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
