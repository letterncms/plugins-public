import { t } from '@letterncms/sdk'
import { Icon } from '@letterncms/ui'
import { useTokens } from '../../core/context'
import { splitValue } from '../css'
import { Section } from '../controls'
import type { StyleApi } from '../useStyle'
import { StyleFields } from './StyleFields'

const PROPERTIES = () => [
  { value: 'all', label: t('Everything') },
  { value: 'color, background-color, border-color, text-decoration-color, fill, stroke', label: t('Colors') },
  { value: 'transform, translate, rotate, scale', label: t('Transform') },
  { value: 'opacity', label: t('Opacity') },
  { value: 'opacity, transform, translate, scale', label: t('Opacity and Transform') },
  { value: 'box-shadow', label: t('Shadow') },
]

function parse(value: string | undefined) {
  if (!value) return null
  const bits = splitValue(value.replace(/,\s*(?![^(]*\))/g, ', '))
  const times = bits.filter((b) => /^[\d.]+m?s$/.test(b) || b.startsWith('token:duration'))
  const ease = bits.find((b) => /^(ease|linear|cubic-bezier|steps|token:ease)/.test(b))
  const props = value.split(/\s+\d/)[0].replace(/\s+(ease|linear|cubic-bezier).*$/, '').trim()
  return { property: props || 'all', duration: times[0] ?? '200ms', ease: ease ?? 'ease', delay: times[1] }
}

/** How state changes (hover, is-open…) animate: what, how long, which curve. */
export function Transition({ s }: { s: StyleApi }) {
  const { byGroup } = useTokens()
  const tr = parse(s.value('transition'))
  const set = (patch: Partial<NonNullable<typeof tr>>) => {
    const next = { property: 'all', duration: '200ms', ease: 'cubic-bezier(0.2, 0.8, 0.2, 1)', ...tr, ...patch }
    const props = next.property.split(',').map((p) => p.trim())
    s.set('transition', props.map((p) => `${p} ${next.duration} ${next.ease}${next.delay ? ` ${next.delay}` : ''}`).join(', '))
  }

  return (
    <Section title={t('Transition')} open={!!tr} empty={!tr}>
      {!tr ? (
        <button type="button" onClick={() => set({})} className="flex h-7 w-full items-center justify-center gap-1.5 rounded-sm text-xs text-muted hover:bg-hover hover:text-ink">
          <Icon name="b-plus" size={14} />
          {t('Animate State Changes')}
        </button>
      ) : (
        <StyleFields
          s={s}
          fields={[
            { handle: 'property', type: 'select', size: 'sm', label: t('Animate'), placeholder: t('Everything'), options: PROPERTIES(), keys: ['transition'], get: () => PROPERTIES().find((p) => p.value.split(',')[0].trim() === tr.property.split(',')[0].trim())?.value, set: (v) => set({ property: v ?? 'all' }) },
            {
              handle: 'timing',
              type: 'group',
              component: 'blocks-group',
              label: t('Duration'),
              keys: ['transition'],
              get: () => ({ duration: tr.duration, delay: tr.delay }),
              set: (v) => set(v.duration !== tr.duration ? { duration: v.duration ?? '200ms' } : { delay: v.delay ?? undefined }),
              fields: [
                { handle: 'duration', type: 'text', component: 'blocks-value', label: t('Duration'), unit: 'ms', step: 10, min: 0, group: 'duration', prefixIcon: 'b-timer' },
                { handle: 'delay', type: 'text', component: 'blocks-value', label: t('Delay'), unit: 'ms', step: 10, min: 0, prefixIcon: 'b-pause', placeholder: t('Delay') },
              ],
            },
            {
              handle: 'ease',
              type: 'select',
              component: 'blocks-ease',
              label: t('Curve'),
              keys: ['transition'],
              get: () => tr.ease,
              set: (v) => set({ ease: v ?? 'ease' }),
              options: [...(byGroup.get('ease') ?? []).map((e) => ({ value: `token:${e.path}`, label: e.name })), 'ease', 'ease-in-out', 'linear', { value: 'cubic-bezier(0.2, 0.8, 0.2, 1)', label: t('Smooth Out') }, { value: 'cubic-bezier(0.65, 0, 0.35, 1)', label: t('Smooth In-Out') }, { value: 'cubic-bezier(0.34, 1.56, 0.64, 1)', label: t('Overshoot') }],
            },
          ]}
        />
      )}
    </Section>
  )
}
