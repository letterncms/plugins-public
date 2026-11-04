import { t } from '@letterncms/sdk'
import { Section } from '../controls'
import type { StyleApi } from '../useStyle'
import { StyleFields } from './StyleFields'

/** Position and offsets. */
export function Position({ s }: { s: StyleApi }) {
  const position = s.value('position')
  const placed = !!position && position !== 'static'
  return (
    <Section title={t('Position')} open={!!position} empty={!position}>
      <StyleFields
        s={s}
        fields={[
          {
            handle: 'position',
            type: 'select',
            size: 'sm',
            label: t('Type'),
            placeholder: t('Static'),
            options: [
              { value: 'static', label: t('Static') },
              { value: 'relative', label: t('Relative') },
              { value: 'absolute', label: t('Absolute') },
              { value: 'fixed', label: t('Fixed') },
              { value: 'sticky', label: t('Sticky') },
            ],
            instructions: position === 'absolute' ? t('Placed inside the nearest positioned parent') : position === 'fixed' ? t('Stays in the window while the page scrolls') : position === 'sticky' ? t('Sticks once its parent scrolls past the offset') : undefined,
          },
          placed && {
            handle: 'offsets',
            type: 'group',
            component: 'blocks-group',
            label: t('Offsets'),
            columns: 2,
            fields: (['top', 'right', 'bottom', 'left'] as const).map((side) => ({ handle: side, type: 'text', component: 'blocks-value', label: { top: t('Top'), right: t('Right'), bottom: t('Bottom'), left: t('Left') }[side], title: { top: t('Top'), right: t('Right'), bottom: t('Bottom'), left: t('Left') }[side], prefix: side[0].toUpperCase(), placeholder: 'auto', group: 'space', keywords: ['auto'] })),
          },
          placed && { handle: 'zIndex', type: 'text', component: 'blocks-value', label: t('Z-index'), unit: '', placeholder: 'auto', prefixIcon: 'b-stack' },
          { handle: 'isolation', type: 'select', display: 'segmented', block: true, size: 'sm', label: t('Isolation'), placeholder: 'auto', options: [{ value: 'auto', label: t('Auto') }, { value: 'isolate', label: t('Isolate') }], instructions: t('A new stacking context: keeps blend modes and z-index inside') },
        ]}
      />
    </Section>
  )
}
