import { t, type FieldProps } from '@letterncms/sdk'
import { Icon, cn } from '@letterncms/ui'
import { useColorPreview } from '../color'
import { RemoveButton, PanelFields } from '../controls'
import { splitList, splitValue } from '../css'
import { Control, SubField } from './controls'

/* Field components for CSS values that need more than one control: gradients and the
   flex alignment matrix. Shadows, spacing, filters and clip paths have files of their own. */

interface Stop {
  color: string
  at: string
}

type Gradient = { kind: 'linear' | 'radial'; angle: string; stops: Stop[] }

export function parseGradient(value: string | undefined | null): Gradient | null {
  const m = value?.match(/^(linear|radial)-gradient\((.*)\)$/)
  if (!m) return null
  const parts = splitList(m[2])
  let angle = m[1] === 'linear' ? '180deg' : 'circle'
  if (parts[0] && (/deg$|^to /.test(parts[0]) || /^(circle|ellipse)/.test(parts[0]))) angle = parts.shift()!
  const stops = parts.map((p) => {
    const bits = splitValue(p)
    return { color: bits[0], at: bits[1] ?? '' }
  })
  return { kind: m[1] as 'linear' | 'radial', angle, stops }
}

export function formatGradient(g: Gradient): string {
  return `${g.kind}-gradient(${[g.angle, ...g.stops.map((s) => `${s.color}${s.at ? ` ${s.at}` : ''}`)].join(', ')})`
}

/** `blocks-gradient` — a linear or radial gradient: preview, shape, angle and color stops. */
export function GradientField({ value, onChange, context }: FieldProps<string | null>) {
  const preview = useColorPreview()
  const gradient = parseGradient(value)
  if (!gradient) return null
  const set = (g: Gradient) => onChange(formatGradient(g))

  return (
    <div className="min-w-0 flex-1 space-y-1 rounded-md bg-active/60 p-1.5">
      <div className="h-6 rounded-sm shadow-[inset_0_0_0_1px_rgb(0_0_0/0.1)]" style={{ background: preview(formatGradient({ ...gradient, kind: 'linear', angle: '90deg' })) }} />
      <PanelFields
        bare
        context={context}
        fields={[
          {
            handle: 'shape',
            type: 'group',
            component: 'blocks-group',
            label: t('Shape'),
            fields: [
              { handle: 'kind', type: 'select', display: 'segmented', block: true, size: 'sm', label: t('Kind'), required: true, options: [{ value: 'linear', label: t('Linear') }, { value: 'radial', label: t('Radial') }] },
              { handle: 'angle', type: 'text', component: 'blocks-value', label: t('Angle'), unit: 'deg', prefix: '∠', className: '!flex-none w-20', if: { kind: 'linear' } },
            ],
          },
        ]}
        values={{ shape: { kind: gradient.kind, angle: gradient.angle } }}
        onChange={(_, v) => set(v.kind !== gradient.kind ? { ...gradient, kind: v.kind, angle: v.kind === 'radial' ? 'circle' : '180deg' } : { ...gradient, angle: v.angle ?? '180deg' })}
      />
      {gradient.stops.map((stop, i) => (
        <div key={i} className="flex items-center gap-1">
          <PanelFields
            bare
            className="min-w-0 flex-1"
            context={context}
            fields={[
              {
                handle: 'stop',
                type: 'group',
                component: 'blocks-group',
                label: t('Stop'),
                fields: [
                  { handle: 'color', type: 'color', component: 'blocks-color', label: t('Color') },
                  { handle: 'at', type: 'text', component: 'blocks-value', label: t('Position'), unit: '%', placeholder: 'auto', className: '!flex-none w-14' },
                ],
              },
            ]}
            values={{ stop }}
            onChange={(_, v) => set({ ...gradient, stops: gradient.stops.map((x, j) => (j === i ? { color: v.color ?? 'transparent', at: v.at ?? '' } : x)) })}
          />
          {gradient.stops.length > 2 && <RemoveButton onClick={() => set({ ...gradient, stops: gradient.stops.filter((_, j) => j !== i) })} />}
        </div>
      ))}
      <button type="button" onClick={() => set({ ...gradient, stops: [...gradient.stops, { color: 'token:color.foreground', at: '' }] })} className="flex h-7 w-full items-center justify-center gap-1.5 rounded-sm text-xs text-muted hover:bg-hover hover:text-ink">
        <Icon name="b-plus" size={14} /> {t('Add Stop')}
      </button>
    </div>
  )
}

const MAIN = ['flex-start', 'center', 'flex-end']
const DISTRIBUTE = () => [
  { value: 'flex-start', label: t('Start') },
  { value: 'center', label: t('Center') },
  { value: 'flex-end', label: t('End') },
  { value: 'space-between', label: t('Space Between') },
  { value: 'space-around', label: t('Space Around') },
  { value: 'space-evenly', label: t('Space Evenly') },
]

/**
 * `blocks-align` — a stack's alignment: a 3×3 matrix sets both axes at once, like Framer,
 * beside the distribution and cross-axis alignment. The value is `{ justifyContent, alignItems }`;
 * `row` tells which way the stack runs.
 */
export function AlignField({ field, value, onChange, context }: FieldProps<{ justifyContent?: string; alignItems?: string } | null>) {
  const row = field.row === true
  const justify = value?.justifyContent ?? undefined
  const align = value?.alignItems ?? undefined
  return (
    <Control>
      <div className="grid size-[60px] shrink-0 grid-cols-3 gap-px rounded-sm bg-bg p-1">
        {[0, 1, 2].flatMap((y) =>
          [0, 1, 2].map((x) => {
            const main = MAIN[row ? x : y]
            const cross = MAIN[row ? y : x]
            const on = (justify ?? 'flex-start') === main && (align ?? 'stretch').replace('stretch', 'flex-start') === cross
            return (
              <button key={`${x}${y}`} type="button" onClick={() => onChange({ justifyContent: main, alignItems: cross })} className="grid place-items-center rounded-[2px] hover:bg-hover" title={`${main.replace('flex-', '')} · ${cross.replace('flex-', '')}`}>
                <span className={cn('rounded-full', on ? 'size-2 bg-ink' : 'size-1 bg-faint')} />
              </button>
            )
          }),
        )}
      </div>
      <PanelFields
        bare
        className="min-w-0 flex-1"
        context={context}
        fields={[
          { handle: 'justifyContent', type: 'select', size: 'sm', label: t('Distribute'), options: DISTRIBUTE(), placeholder: t('Distribute') },
          {
            handle: 'alignItems',
            type: 'select',
            display: 'segmented',
            block: true,
            size: 'sm',
            label: t('Align'),
            options: [
              { value: 'flex-start', icon: row ? 'b-align-top' : 'b-align-left', title: t('Start') },
              { value: 'center', icon: row ? 'b-align-center-vertical' : 'b-align-center-horizontal', title: t('Center') },
              { value: 'flex-end', icon: row ? 'b-align-bottom' : 'b-align-right', title: t('End') },
              { value: 'stretch', icon: row ? 'b-arrows-out-line-vertical' : 'b-arrows-out-line-horizontal', title: t('Stretch') },
              { value: 'baseline', icon: 'b-text-t', title: t('Baseline') },
            ],
          },
        ]}
        values={{ justifyContent: justify, alignItems: align }}
        onChange={(h, v) => onChange({ justifyContent: justify, alignItems: align, [h]: v })}
      />
    </Control>
  )
}

const SPOTS = [
  ['top left', 'top', 'top right'],
  ['left', 'center', 'right'],
  ['bottom left', 'bottom', 'bottom right'],
]
const PERCENT: Record<string, string> = { left: '0%', top: '0%', center: '50%', right: '100%', bottom: '100%' }

/** "top left", "50% 0%", "right" → the same "x y" in percentages, to match a spot. */
function spotKey(value: string | null | undefined): string {
  const parts = (value ?? 'center').trim().split(/\s+/)
  const vertical = (p: string) => p === 'top' || p === 'bottom'
  // One keyword: the other axis is centered. Keywords may come in either order ("top left").
  if (parts.length === 1) parts.push('center')
  const [x, y] = vertical(parts[0]) || (parts[1] && (parts[1] === 'left' || parts[1] === 'right')) ? [parts[1], parts[0]] : parts
  return `${PERCENT[x] ?? x} ${PERCENT[y] ?? y}`
}

/**
 * `blocks-origin` — a position (transform-origin, background-position, object-position):
 * a 3×3 grid of spots, or any value typed beside it ("30% 70%").
 */
export function OriginField({ field, value, onChange, context, disabled }: FieldProps<string | null>) {
  const current = spotKey(value)
  return (
    <Control>
      <div className="grid size-[60px] shrink-0 grid-cols-3 gap-px rounded-sm bg-bg p-1" role="radiogroup" aria-label={field.label}>
        {SPOTS.flat().map((spot) => {
          const on = current === spotKey(spot)
          return (
            <button key={spot} type="button" role="radio" aria-checked={on} disabled={disabled} onClick={() => onChange(spot === 'center' && !field.required ? null : spot)} className="grid place-items-center rounded-[2px] hover:bg-hover" title={spot}>
              <span className={cn('rounded-full', on ? 'size-2 bg-ink' : 'size-1 bg-faint')} />
            </button>
          )
        })}
      </div>
      <SubField field={{ handle: `${field.handle}-value`, type: 'text', component: 'blocks-value', label: field.label, unit: '', placeholder: t('Center') }} value={value ?? null} onChange={(v) => onChange((v as string | null) || null)} context={context} disabled={disabled} />
    </Control>
  )
}
