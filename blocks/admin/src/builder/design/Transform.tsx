import { t } from '@letterncms/sdk'
import { splitValue } from '../css'
import { Section } from '../controls'
import type { StyleApi } from '../useStyle'
import { StyleFields, changed } from './StyleFields'

const fn = (value: string | undefined, name: string) => value?.match(new RegExp(`${name}\\(([^)]*)\\)`))?.[1]

/** Move, rotate, scale and skew — the individual transform properties, plus 3D perspective. */
export function Transform({ s }: { s: StyleApi }) {
  const translate = splitValue(s.value('translate') ?? '')
  const transform = s.value('transform')
  const any = ['translate', 'rotate', 'scale', 'transform', 'transformOrigin', 'perspective'].some((k) => s.value(k))
  const move = { x: translate[0], y: translate[1] }
  const skew = { X: fn(transform, 'skewX'), Y: fn(transform, 'skewY') }

  const setTranslate = (i: number, v: string | null) => {
    const next = [translate[0] ?? '0', translate[1] ?? '0']
    next[i] = v ?? '0'
    s.set('translate', next.every((p) => p === '0' || p === '0px') ? null : next.join(' '))
  }

  const setSkew = (axis: 'X' | 'Y', v: string | null) => {
    const parts = splitValue(transform ?? '').filter((p) => !p.startsWith(`skew${axis}(`))
    if (v) parts.push(`skew${axis}(${/^-?[\d.]+$/.test(v) ? `${v}deg` : v})`)
    s.set('transform', parts.join(' ') || null)
  }

  const axes = (unit: string) => [
    { handle: 'x', type: 'text', component: 'blocks-value', label: 'X', prefix: 'X', placeholder: '0', unit },
    { handle: 'y', type: 'text', component: 'blocks-value', label: 'Y', prefix: 'Y', placeholder: '0', unit },
  ]

  return (
    <Section title={t('Transform')} open={!!any} empty={!any}>
      <StyleFields
        s={s}
        fields={[
          { handle: 'move', type: 'group', component: 'blocks-group', label: t('Move'), keys: ['translate'], fields: axes('px'), get: () => move, set: (v) => Object.entries(changed(move, v)).forEach(([k, x]) => setTranslate(k === 'x' ? 0 : 1, x ?? null)) },
          { handle: 'rotate', type: 'text', component: 'blocks-value', label: t('Rotate'), unit: 'deg', prefixIcon: 'b-arrow-clockwise', placeholder: '0' },
          { handle: 'scale', type: 'text', component: 'blocks-value', label: t('Scale'), unit: '', step: 0.01, prefixIcon: 'b-arrows-out-simple', placeholder: '1' },
          {
            handle: 'skew',
            type: 'group',
            component: 'blocks-group',
            label: t('Skew'),
            keys: ['transform'],
            fields: axes('deg').map((f) => ({ ...f, handle: f.handle.toUpperCase() })),
            get: () => skew,
            set: (v) => Object.entries(changed(skew, v)).forEach(([k, x]) => setSkew(k as 'X' | 'Y', x ?? null)),
          },
          { handle: 'transformOrigin', type: 'json', component: 'blocks-origin', label: t('Origin'), className: 'items-start py-0.5 [&>label]:leading-6' },
          { handle: 'perspective', type: 'text', component: 'blocks-value', label: t('Perspective'), prefixIcon: 'b-perspective', placeholder: 'none', keywords: ['none'], instructions: t('Depth for children rotated in 3D') },
        ]}
      />
    </Section>
  )
}
