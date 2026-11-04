import { t, type FieldDef } from '@letterncms/sdk'
import { Icon, Menu, Tooltip, cn } from '@letterncms/ui'
import { useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'
import { useEditorContext } from '../../core/context'
import type { BlockNode, MotionDef, MotionStep } from '../../core/types'
import { bus } from '../bus'
import { RemoveButton, Section, PanelFields } from '../controls'
import { groupLabel, presets, propertyLabels, triggers, type Preset } from './presets'

/** Fields shown only when `on`. */
const when = (on: unknown, ...fields: FieldDef[]): FieldDef[] => (on ? fields : [])

const TEXT_TYPES = ['heading', 'paragraph', 'link', 'button', 'quote']

const nextId = (list: MotionDef[]) => {
  let i = list.length + 1
  while (list.some((m) => m.id === `m${i}`)) i++
  return `m${i}`
}

const targetLabel = (target = 'self') =>
  target.startsWith('#') ? t('Another Element') : ({ self: t('This Element'), children: t('Children'), words: t('Words') } as Record<string, string>)[target] ?? target

/** Animations: Framer-style effects with presets, and a timeline of steps when one effect is not enough. */
export function MotionPanel({ node }: { node: BlockNode }) {
  const { store } = useEditorContext()
  const list = node.motion ?? []
  const [open, setOpen] = useState<string | null>(list[0]?.id ?? null)
  const save = (next: MotionDef[]) => store.updateNode(node.id, { motion: next.length ? next : undefined }, `motion:${node.id}`)
  const update = (id: string, patch: Partial<MotionDef>) => save(list.map((m) => (m.id === id ? { ...m, ...patch } : m)))
  const text = TEXT_TYPES.includes(node.type)
  const kinds = triggers()

  const add = (preset?: Preset) => {
    const id = nextId(list)
    const motion: MotionDef = preset ? { id, name: preset.name, ...preset.make() } : { id, name: t('Custom'), trigger: { type: 'appear' }, steps: [{ target: 'self', from: { opacity: 0, y: 24 }, duration: 0.8, ease: 'power3.out' }] }
    save([...list, motion])
    setOpen(id)
    requestAnimationFrame(() => bus.emit('preview-motion', { node: node.id, motion }))
  }

  const all = presets()
  const groups = [...new Set(all.map((p) => p.group))]

  return (
    <Section
      title={t('Animations')}
      empty={list.length === 0}
      actions={
        <Menu
          align="end"
          trigger={
            <button type="button" className="grid size-6 shrink-0 place-items-center rounded-sm text-muted hover:bg-hover hover:text-ink" aria-label={t('Add Animation')}>
              <Icon name="b-plus" size={14} />
            </button>
          }
          items={[
            ...groups.flatMap((g) => [{ label: groupLabel(g), heading: true }, ...all.filter((p) => p.group === g && (!p.text || text)).map((p) => ({ label: p.name, icon: kinds[p.make().trigger.type]?.icon, onSelect: () => add(p) }))]),
            { separator: true, label: '' },
            { label: t('Custom Animation'), icon: 'b-magic-wand', onSelect: () => add() },
          ]}
        />
      }
    >
      {list.map((m) => (
        <div key={m.id} className={cn('rounded-sm border', open === m.id ? 'border-border-strong' : 'border-border')}>
          <div className="flex h-8 items-center gap-1.5 pl-2 pr-1">
            <Icon name={kinds[m.trigger.type]?.icon ?? 'b-magic-wand'} size={13} className="shrink-0 text-muted" />
            <button type="button" onClick={() => setOpen(open === m.id ? null : m.id)} aria-expanded={open === m.id} className="flex min-w-0 flex-1 items-baseline gap-1.5 text-left">
              <span className="truncate text-xs font-medium text-ink">{m.name || kinds[m.trigger.type]?.label}</span>
              <span className="shrink-0 text-xs text-faint">{kinds[m.trigger.type]?.label}</span>
            </button>
            <Tooltip content={t('Preview Animation')}>
              <button type="button" onClick={() => bus.emit('preview-motion', { node: node.id, motion: m })} className="grid size-6 shrink-0 place-items-center rounded-sm text-muted hover:bg-hover hover:text-ink" aria-label={t('Preview')}>
                <Icon name="b-play" size={14} />
              </button>
            </Tooltip>
            <RemoveButton onClick={() => save(list.filter((x) => x.id !== m.id))} label={t('Remove Animation')} />
          </div>
          {open === m.id && <MotionEditor node={node} motion={m} onChange={(patch) => update(m.id, patch)} />}
        </div>
      ))}
    </Section>
  )
}

function MotionEditor({ node, motion, onChange }: { node: BlockNode; motion: MotionDef; onChange: (patch: Partial<MotionDef>) => void }) {
  const [active, setActive] = useState(0)
  const kinds = triggers()
  const trigger = motion.trigger
  const setTrigger = (patch: Partial<MotionDef['trigger']>) => onChange({ trigger: { ...trigger, ...patch } })
  const setStep = (i: number, patch: Partial<MotionStep>) => onChange({ steps: motion.steps.map((s, j) => (j === i ? { ...s, ...patch } : s)) })
  const loop = trigger.type === 'loop' || trigger.type === 'load'
  const step = Math.min(active, motion.steps.length - 1)

  const fields: FieldDef[] = [
    { handle: 'name', type: 'text', size: 'sm', commit: 'blur', label: t('Name') },
    { handle: 'type', type: 'select', size: 'sm', label: t('Trigger'), required: true, options: Object.entries(kinds).map(([value, d]) => ({ value, label: d.label })), instructions: kinds[trigger.type]?.help },
  ]
  const after: FieldDef[] = [
    ...when(
      trigger.type === 'appear',
      { handle: 'start', type: 'select', size: 'sm', label: t('Starts'), placeholder: t('A little into view'), options: [{ value: 'top 95%', label: t('As soon as it peeks in') }, { value: 'top 85%', label: t('A little into view') }, { value: 'top 65%', label: t('A third into view') }, { value: 'center center', label: t('At the Center') }] },
      { handle: 'replay', type: 'toggle', size: 'sm', label: t('Reverse When Scrolled Back') },
    ),
    ...when(
      trigger.type === 'scroll',
      { handle: 'start', type: 'select', size: 'sm', label: t('From'), placeholder: t('Enters the Screen'), options: [{ value: 'top bottom', label: t('Enters the Screen') }, { value: 'top 80%', label: t('Top at 80%') }, { value: 'top center', label: t('Top at Center') }, { value: 'top top', label: t('Top at Top') }, { value: 'center center', label: t('Centered') }] },
      { handle: 'end', type: 'select', size: 'sm', label: t('To'), placeholder: t('Leaves the Screen'), options: [{ value: 'bottom top', label: t('Leaves the Screen') }, { value: 'center center', label: t('Centered') }, { value: 'top top', label: t('Top at Top') }, { value: 'bottom center', label: t('Bottom at Center') }, { value: 'bottom bottom', label: t('Bottom at Bottom') }] },
    ),
    ...when(
      loop,
      { handle: 'repeat', type: 'select', display: 'segmented', block: true, size: 'sm', label: t('Repeat'), required: true, options: [{ value: '0', label: t('Once') }, { value: '1', label: t('Twice') }, { value: '-1', label: t('Forever') }] },
      { handle: 'yoyo', type: 'toggle', size: 'sm', label: t('Back and Forth') },
      { handle: 'hold', type: 'number', size: 'sm', label: t('Loop as a Timeline, Resting (s)'), if: { repeat: '-1' }, instructions: t('On Appear: all steps loop together while on screen.') },
    ),
    ...when(trigger.type === 'scroll' || trigger.type === 'appear', { handle: 'watch', type: 'select', component: 'blocks-node', label: t('Watch'), placeholder: t('This Element'), exclude: node.id }),
    { handle: 'desktop', type: 'toggle', size: 'sm', label: t('Plays on Desktop') },
    { handle: 'tablet', type: 'toggle', size: 'sm', label: t('Plays on Tablet') },
    { handle: 'mobile', type: 'toggle', size: 'sm', label: t('Plays on Phone') },
  ]
  const on = motion.on ?? ['desktop', 'tablet', 'mobile']
  const values = {
    name: motion.name ?? '',
    type: trigger.type,
    start: trigger.start,
    end: trigger.end,
    replay: trigger.once === false,
    desktop: on.includes('desktop'),
    tablet: on.includes('tablet'),
    mobile: on.includes('mobile'),
    repeat: String(motion.repeat ?? (trigger.type === 'loop' ? -1 : 0)),
    yoyo: !!motion.yoyo,
    hold: motion.hold,
    watch: trigger.node?.slice(1),
  }
  const apply = (h: string, v: any) => {
    if (h === 'name') onChange({ name: v ?? '' })
    else if (h === 'type') v && setTrigger({ type: v })
    else if (h === 'start' || h === 'end') setTrigger({ [h]: v ?? undefined })
    else if (h === 'replay') setTrigger({ once: v ? false : undefined })
    else if (h === 'desktop' || h === 'tablet' || h === 'mobile') {
      const next = v ? [...on, h] : on.filter((screen) => screen !== h)
      // Off everywhere means nothing to edit: keep at least one.
      if (next.length) onChange({ on: next.length === 3 ? undefined : next })
    }
    else if (h === 'repeat') onChange({ repeat: Number(v ?? 0) })
    else if (h === 'yoyo') onChange({ yoyo: v || undefined })
    else if (h === 'hold') onChange({ hold: v === '' || v == null ? undefined : Number(v) })
    else if (h === 'watch') setTrigger({ node: v ? `#${v}` : undefined })
  }
  const addStep = () => {
    onChange({ steps: [...motion.steps, { target: 'children', from: { opacity: 0, y: 16 }, duration: 0.5, ease: 'power3.out', stagger: 0.06 }] })
    setActive(motion.steps.length)
  }

  return (
    <div className="space-y-2 border-t border-border p-2">
      <PanelFields fields={[...fields, ...after]} values={values} onChange={apply} />
      {trigger.type === 'event' && (
        <p className="m-0 flex h-7 items-center gap-2 text-xs">
          <span className="w-[88px] shrink-0 text-muted">{t('Animation ID')}</span>
          <code className="select-all font-mono text-text">{motion.id}</code>
        </p>
      )}
      <Timeline steps={motion.steps} active={step} onSelect={setActive} onChange={setStep} onAdd={addStep} />
      {motion.steps[step] && (
        <StepEditor
          key={step}
          node={node}
          step={motion.steps[step]}
          index={step}
          count={motion.steps.length}
          onChange={(patch) => setStep(step, patch)}
          onRemove={() => {
            onChange({ steps: motion.steps.filter((_, j) => j !== step) })
            setActive(Math.max(0, step - 1))
          }}
        />
      )}
    </div>
  )
}

/** Where each step sits in time: one after another, unless it has its own start (`at`). */
function layout(steps: MotionStep[]) {
  let cursor = 0
  return steps.map((s) => {
    const base = s.at ?? cursor
    const start = base + (s.delay ?? 0)
    const duration = s.duration ?? 0.6
    const spread = (s.stagger ?? 0) * 3
    cursor = start + duration + spread
    return { base, start, duration, spread }
  })
}

const snap = (n: number) => Math.max(0, Math.round(n * 20) / 20)

/**
 * The steps as bars on a time ruler. Click a bar to edit its step, drag it to delay
 * it, drag its right edge to change how long it runs. Staggered steps show their
 * spread (children, words…) as a lighter tail.
 */
function Timeline({ steps, active, onSelect, onChange, onAdd }: { steps: MotionStep[]; active: number; onSelect: (i: number) => void; onChange: (i: number, patch: Partial<MotionStep>) => void; onAdd: () => void }) {
  const bars = layout(steps)
  const end = Math.max(0.5, ...bars.map((b) => b.start + b.duration + b.spread))
  const live = useRef<{ total: number; width: number } | null>(null)
  const total = live.current?.total ?? Math.ceil(end * 2 + 0.25) / 2
  const tick = total <= 1.5 ? 0.25 : total <= 4 ? 0.5 : total <= 10 ? 1 : 2
  const ticks = Array.from({ length: Math.floor(total / tick) + 1 }, (_, i) => i * tick)
  const pct = (s: number) => `${(s / total) * 100}%`

  const startDrag = (e: ReactPointerEvent, i: number, edge: 'move' | 'end') => {
    e.preventDefault()
    e.stopPropagation()
    onSelect(i)
    const track = (e.currentTarget as HTMLElement).closest('[data-track]') as HTMLElement | null
    if (!track) return
    live.current = { total, width: track.clientWidth }
    const x0 = e.clientX
    const s = steps[i]
    const from = edge === 'move' ? (s.at !== undefined ? s.at : s.delay ?? 0) : s.duration ?? 0.6
    const move = (ev: PointerEvent) => {
      const dt = ((ev.clientX - x0) / live.current!.width) * live.current!.total
      if (edge === 'end') onChange(i, { duration: Math.max(0.05, snap(from + dt)) })
      else if (s.at !== undefined) onChange(i, { at: snap(from + dt) })
      else onChange(i, { delay: snap(from + dt) || undefined })
    }
    const up = () => {
      window.removeEventListener('pointermove', move)
      window.removeEventListener('pointerup', up)
      live.current = null
    }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', up)
  }

  return (
    <div className="rounded-md bg-active/60 py-1.5">
      <div className="mb-1 flex items-end pr-2">
        <span className="w-16 shrink-0 pl-2 text-2xs font-medium text-faint">{t('Timeline')}</span>
        <div className="relative h-3 flex-1">
          {/* Every other tick is labeled when they would crowd the narrow panel. */}
          {ticks.filter((_, i) => ticks.length <= 5 || i % 2 === 0).map((s) => (
            <span key={s} className="absolute -translate-x-1/2 font-mono text-[9px] leading-none text-faint first:translate-x-0 last:-translate-x-full" style={{ left: pct(s) }}>
              {s === 0 ? '0' : `${s}s`}
            </span>
          ))}
        </div>
      </div>
      {steps.map((s, i) => {
        const b = bars[i]
        return (
          <div key={i} className={cn('flex h-7 items-center pr-2', i === active && 'bg-focus-soft')}>
            <button type="button" onClick={() => onSelect(i)} className={cn('w-16 shrink-0 truncate pl-2 text-left text-2xs', i === active ? 'font-medium text-ink' : 'text-muted hover:text-text')}>
              {targetLabel(s.target)}
            </button>
            <div data-track className="relative h-full flex-1">
              {ticks.map((x) => (
                <span key={x} className="absolute inset-y-0 w-px bg-border/70" style={{ left: pct(x) }} />
              ))}
              <div
                role="slider"
                tabIndex={0}
                aria-label={t('Step {n}', { n: i + 1 })}
                aria-valuenow={b.start}
                aria-valuetext={t('Starts at {start}s, runs {duration}s', { start: b.start.toFixed(2), duration: b.duration.toFixed(2) })}
                onPointerDown={(e) => startDrag(e, i, 'move')}
                onKeyDown={(e) => {
                  if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return
                  e.preventDefault()
                  const d = e.key === 'ArrowRight' ? 0.05 : -0.05
                  if (e.shiftKey) onChange(i, { duration: Math.max(0.05, snap((s.duration ?? 0.6) + d)) })
                  else if (s.at !== undefined) onChange(i, { at: snap(s.at + d) })
                  else onChange(i, { delay: snap((s.delay ?? 0) + d) || undefined })
                }}
                className={cn('cms-focus-ring group/bar absolute top-1 flex h-4 cursor-grab items-center rounded-[3px] active:cursor-grabbing', i === active ? 'bg-focus' : 'bg-focus/50 hover:bg-focus/70')}
                style={{ left: pct(b.start), width: `max(6px, ${pct(b.duration)})` }}
              >
                <span className="pointer-events-none truncate px-1 font-mono text-[9px] leading-none text-white">{b.duration.toFixed(1)}s</span>
                <span onPointerDown={(e) => startDrag(e, i, 'end')} className="absolute -right-1 inset-y-0 w-2 cursor-ew-resize" aria-hidden />
              </div>
              {b.spread > 0 && <span className="pointer-events-none absolute top-1 h-4 rounded-r-[3px] bg-focus/20" style={{ left: pct(b.start + b.duration), width: pct(b.spread) }} />}
            </div>
          </div>
        )
      })}
      <button type="button" onClick={onAdd} className="ml-16 mt-0.5 flex h-6 items-center gap-1 rounded-sm px-1.5 text-xs text-muted hover:bg-hover hover:text-ink">
        <Icon name="b-plus" size={12} /> {t('Add Step')}
      </button>
    </div>
  )
}

/** A time in seconds: a value to type, nudge or scrub. */
const seconds = (handle: string, title: string, icon: string, extra: Partial<FieldDef> = {}): FieldDef => ({ handle, type: 'text', component: 'blocks-value', label: title, title, prefixIcon: icon, unit: '', step: 0.05, min: 0, ...extra })

function StepEditor({ node, step, index, count, onChange, onRemove }: { node: BlockNode; step: MotionStep; index: number; count: number; onChange: (patch: Partial<MotionStep>) => void; onRemove: () => void }) {
  const { catalog } = useEditorContext()
  const target = step.target ?? 'self'
  const labels = propertyLabels()
  const props = Object.keys(catalog.motion?.properties ?? labels)
  const text = TEXT_TYPES.includes(node.type)

  const sideEditor = (side: 'from' | 'to') => {
    const values = step[side] ?? {}
    const add = (key: string, v: string) => onChange({ [side]: { ...values, [key]: /^-?[\d.]+$/.test(v) ? Number(v) : v } })
    return (
      <div className="space-y-1">
        <div className="flex h-7 items-center justify-between">
          <span className="text-xs font-medium text-muted">{side === 'from' ? t('From') : t('To')}</span>
          <Menu
            align="end"
            trigger={
              <button type="button" className="grid size-6 shrink-0 place-items-center rounded-sm text-muted hover:bg-hover hover:text-ink" aria-label={side === 'from' ? t('Add a Start Property') : t('Add an End Property')}>
                <Icon name="b-plus" size={14} />
              </button>
            }
            items={props.filter((p) => !(p in values)).map((p) => ({ label: labels[p] ?? p, onSelect: () => add(p, catalog.motion.properties[p] === 'css' ? (p === 'clipPath' ? 'inset(0% 0% 0% 0%)' : 'token:color.primary') : p === 'opacity' || p.startsWith('scale') ? '1' : '0') }))}
          />
        </div>
        {Object.keys(values).length === 0 && <p className="m-0 text-2xs text-faint">{side === 'from' ? t('As it looks now') : t('As Designed')}</p>}
        <PanelFields
          bare
          fields={[
            {
              handle: side,
              type: 'json',
              component: 'blocks-pairs',
              className: 'items-start',
              label: side === 'from' ? t('From') : t('To'),
              labels,
              item: { handle: 'value', type: 'text', component: 'blocks-value', label: t('Value'), unit: '' },
              items: Object.fromEntries(Object.keys(values).map((key) => [key, { handle: 'value', type: 'text', component: 'blocks-value', label: t('Value'), unit: '', step: key === 'opacity' || key.startsWith('scale') ? 0.05 : 1, group: key.toLowerCase().includes('color') ? 'color' : undefined }])),
            },
          ]}
          values={{ [side]: values }}
          onChange={(_, next: Record<string, string | number | null>) => {
            const out = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== '').map(([k, v]) => [k, typeof v === 'string' && /^-?[\d.]+$/.test(v) ? Number(v) : (v as string | number)]))
            onChange({ [side]: Object.keys(out).length ? out : undefined })
          }}
        />
      </div>
    )
  }

  return (
    <div className="space-y-2">
      <div className="flex h-7 items-center gap-1">
        <span className="text-xs font-semibold text-text">{count > 1 ? t('Step {n}', { n: index + 1 }) : t('Animate')}</span>
        <span className="flex-1" />
        {count > 1 && <RemoveButton onClick={onRemove} label={t('Remove Step')} />}
      </div>
      <PanelFields
        fields={[
          {
            handle: 'target',
            type: 'select',
            size: 'sm',
            label: t('Target'),
            required: true,
            options: [{ value: 'self', label: t('This Element') }, { value: 'children', label: t('Each Child in Turn') }, ...(text ? [{ value: 'words', label: t('Each Word') }] : []), { value: 'other', label: t('Another Element') }],
          },
          ...when(target.startsWith('#'), { handle: 'element', type: 'select', component: 'blocks-node', label: t('Element') }),
          ...when(target === 'words', { handle: 'mask', type: 'toggle', size: 'sm', label: t('Rise from Behind a Line') }),
        ]}
        values={{ target: target.startsWith('#') ? 'other' : target, element: target.slice(1), mask: !!step.mask }}
        onChange={(h, v) => (h === 'mask' ? onChange({ mask: v || undefined }) : onChange({ target: h === 'target' ? (v === 'other' ? `#${node.id}` : v ?? 'self') : v ? `#${v}` : 'self' }))}
      />
      {sideEditor('from')}
      {sideEditor('to')}
      <PanelFields
        fields={[
          {
            handle: 'timing',
            type: 'group',
            component: 'blocks-group',
            label: t('Timing'),
            columns: 2,
            fields: [
              seconds('duration', t('Duration in Seconds'), 'b-timer'),
              seconds('delay', t('Delay in Seconds'), 'b-clock', { placeholder: '0' }),
              ...when(target !== 'self', seconds('stagger', t('Time between items in seconds'), 'b-dots-three', { step: 0.01, placeholder: '0' })),
              ...when(count > 1, seconds('at', t('Start in seconds into the timeline'), 'b-flow-arrow', { placeholder: t('After') })),
            ],
          },
          { handle: 'ease', type: 'select', component: 'blocks-easing', label: t('Ease'), placeholder: t('Smooth Out') },
        ]}
        values={{ timing: { duration: String(step.duration ?? 0.6), delay: step.delay !== undefined ? String(step.delay) : undefined, stagger: step.stagger !== undefined ? String(step.stagger) : undefined, at: step.at !== undefined ? String(step.at) : undefined }, ease: step.ease }}
        onChange={(h, v) =>
          h === 'ease'
            ? onChange({ ease: v ?? undefined })
            : onChange({ duration: Number(v.duration ?? 0.6), delay: v.delay ? Number(v.delay) : undefined, stagger: v.stagger ? Number(v.stagger) : undefined, at: v.at ? Number(v.at) : undefined })
        }
      />
    </div>
  )
}
