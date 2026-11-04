import { t } from '@letterncms/sdk'
import { Section } from '../controls'
import type { StyleApi } from '../useStyle'
import { StyleFields } from './StyleFields'

const BLEND = ['multiply', 'screen', 'overlay', 'darken', 'lighten', 'color-dodge', 'color-burn', 'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity']
const CURSORS = ['pointer', 'default', 'text', 'move', 'grab', 'grabbing', 'zoom-in', 'zoom-out', 'not-allowed', 'none', 'crosshair']

/** Opacity, shadows, filters (blur, backdrop blur…), clip path, blend mode, cursor and interaction. */
export function Effects({ s }: { s: StyleApi }) {
  const any = ['opacity', 'boxShadow', 'filter', 'backdropFilter', 'mixBlendMode', 'cursor', 'pointerEvents', 'clipPath'].some((k) => s.value(k))

  return (
    <Section title={t('Effects')} empty={!any}>
      <StyleFields
        s={s}
        fields={[
          { handle: 'opacity', type: 'text', component: 'blocks-value', label: t('Opacity'), percent: true, step: 0.05, min: 0, max: 1, placeholder: '100', slider: { min: 0, max: 1, step: 0.01, neutral: 1 } },
        ]}
      />
      <StyleFields s={s} bare fields={[{ handle: 'boxShadow', type: 'text', component: 'blocks-shadows', label: t('Shadows'), group: 'shadow' }]} />
      <StyleFields s={s} bare fields={[{ handle: 'filter', type: 'text', component: 'blocks-filters', label: t('Filters') }]} />
      <StyleFields s={s} bare fields={[{ handle: 'backdropFilter', type: 'text', component: 'blocks-filters', label: t('Backdrop'), instructions: t('Filters what is behind the element, like frosted glass') }]} />
      <StyleFields
        s={s}
        fields={[
          { handle: 'clipPath', type: 'text', component: 'blocks-clip', label: t('Clip Path'), className: 'items-start py-0.5 [&>label]:leading-6' },
          { handle: 'mixBlendMode', type: 'select', size: 'sm', label: t('Blending'), placeholder: t('Normal'), options: BLEND },
          { handle: 'cursor', type: 'select', size: 'sm', label: t('Cursor'), placeholder: t('Auto'), options: CURSORS },
          { handle: 'pointerEvents', type: 'select', display: 'segmented', block: true, size: 'sm', label: t('Clicks'), placeholder: 'auto', options: [{ value: 'auto', label: t('On') }, { value: 'none', label: t('Pass Through') }] },
        ]}
      />
    </Section>
  )
}
