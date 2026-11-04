import { t } from '@letterncms/sdk'
import { Section } from '../controls'
import type { StyleApi } from '../useStyle'
import { StyleFields } from './StyleFields'

const DISPLAY = () => [
  { value: 'block', icon: 'b-square', title: t('Block') },
  { value: 'flex', icon: 'b-rows', title: t('Stack (flex)') },
  { value: 'grid', icon: 'b-grid-four', title: t('Grid') },
  { value: 'inline-flex', icon: 'b-arrows-in-line-horizontal', title: t('Inline Stack') },
  { value: 'none', icon: 'b-eye-slash', title: t('Hidden') },
]

/** Display, stack (flex) and grid. A 3×3 matrix sets both axes at once, like Framer. */
export function Layout({ s }: { s: StyleApi }) {
  const display = s.value('display')
  const flex = display === 'flex' || display === 'inline-flex'
  const grid = display === 'grid' || display === 'inline-grid'
  const row = flex && (s.value('flexDirection') ?? 'row').startsWith('row')

  return (
    <Section title={t('Layout')} empty={!display}>
      <StyleFields
        s={s}
        fields={[
          { handle: 'display', type: 'select', display: 'segmented', block: true, size: 'sm', label: t('Display'), options: DISPLAY() },
          flex && {
            handle: 'flexDirection',
            type: 'select',
            display: 'segmented',
            block: true,
            size: 'sm',
            label: t('Direction'),
            get: () => s.value('flexDirection') ?? 'row',
            options: [
              { value: 'row', icon: 'b-arrow-right', title: t('Horizontal') },
              { value: 'column', icon: 'b-arrow-down', title: t('Vertical') },
              { value: 'row-reverse', icon: 'b-arrow-left', title: t('Horizontal, Reversed') },
              { value: 'column-reverse', icon: 'b-arrow-up', title: t('Vertical, Reversed') },
            ],
          },
          flex && { handle: 'align', type: 'json', component: 'blocks-align', label: t('Align'), row, keys: ['alignItems', 'justifyContent'], get: () => ({ justifyContent: s.value('justifyContent'), alignItems: s.value('alignItems') }) },
          flex && { handle: 'gap', type: 'text', component: 'blocks-value', label: t('Gap'), group: 'space', prefixIcon: row ? 'b-split-horizontal' : 'b-split-vertical', placeholder: '0', min: 0 },
          flex && { handle: 'flexWrap', type: 'select', display: 'segmented', block: true, size: 'sm', label: t('Wrap'), options: [{ value: 'nowrap', label: t('One Line') }, { value: 'wrap', label: t('Wrap') }], get: () => s.value('flexWrap') ?? 'nowrap', set: (v) => s.set('flexWrap', v === 'wrap' ? 'wrap' : null) },
          grid && { handle: 'gridTemplateColumns', type: 'text', component: 'blocks-value', label: t('Columns'), placeholder: t('3 or 1fr 2fr'), unit: '', min: 1, title: t('A number of equal columns, or any grid template (1fr 2fr, repeat(auto-fill, minmax(16rem, 1fr)))') },
          grid && { handle: 'gridTemplateRows', type: 'text', component: 'blocks-value', label: t('Rows'), placeholder: 'auto', unit: '' },
          grid && {
            handle: 'gaps',
            type: 'group',
            component: 'blocks-group',
            label: t('Gap'),
            keys: ['gap', 'rowGap', 'columnGap'],
            get: () => ({ columnGap: s.value('columnGap') ?? s.value('gap'), rowGap: s.value('rowGap') ?? s.value('gap') }),
            set: (v) => s.write({ gap: null, columnGap: v.columnGap ?? null, rowGap: v.rowGap ?? null }),
            fields: [
              { handle: 'columnGap', type: 'text', component: 'blocks-value', label: t('Columns'), group: 'space', prefixIcon: 'b-split-horizontal', placeholder: '0', min: 0 },
              { handle: 'rowGap', type: 'text', component: 'blocks-value', label: t('Rows'), group: 'space', prefixIcon: 'b-split-vertical', placeholder: '0', min: 0 },
            ],
          },
          grid && {
            handle: 'alignItems',
            type: 'select',
            display: 'segmented',
            block: true,
            size: 'sm',
            label: t('Align'),
            keys: ['alignItems', 'justifyItems'],
            get: () => s.value('alignItems'),
            options: [{ value: 'start', icon: 'b-align-top', title: t('Top') }, { value: 'center', icon: 'b-align-center-vertical', title: t('Middle') }, { value: 'end', icon: 'b-align-bottom', title: t('Bottom') }, { value: 'stretch', icon: 'b-arrows-out-line-vertical', title: t('Stretch') }],
          },
          grid && { handle: 'gridAutoFlow', type: 'select', display: 'segmented', block: true, size: 'sm', label: t('Flow'), options: [{ value: 'row', icon: 'b-arrow-right', title: t('Rows First') }, { value: 'column', icon: 'b-arrow-down', title: t('Columns First') }, { value: 'row dense', icon: 'b-squares-four', title: t('Fill Gaps (Dense)') }] },
        ]}
      />
    </Section>
  )
}

/** How the element sits in a stack or grid parent. */
export function InParent({ s, parentDisplay }: { s: StyleApi; parentDisplay?: string }) {
  if (!parentDisplay || !/flex|grid/.test(parentDisplay)) return null
  const grid = parentDisplay.includes('grid')
  return (
    <Section title={grid ? t('Grid Item') : t('Stack Item')} open={false} empty={!s.value('alignSelf') && !s.value('flex') && !s.value('gridColumn')}>
      <StyleFields
        s={s}
        fields={[
          grid && { handle: 'gridColumn', type: 'text', component: 'blocks-value', label: t('Column'), placeholder: 'auto', unit: '', title: t('Span a number of columns (2), or a range (1 / -1 for full width)') },
          grid && { handle: 'gridRow', type: 'text', component: 'blocks-value', label: t('Row'), placeholder: 'auto', unit: '' },
          !grid && { handle: 'flex', type: 'select', display: 'segmented', block: true, size: 'sm', label: t('Grow'), options: [{ value: '1 1 0%', label: t('Fill') }, { value: '0 0 auto', label: t('Fixed') }, { value: '0 1 auto', label: t('Shrink') }] },
          { handle: 'alignSelf', type: 'select', display: 'segmented', block: true, size: 'sm', label: t('Align Self'), options: [{ value: 'flex-start', icon: 'b-align-top', title: t('Start') }, { value: 'center', icon: 'b-align-center-vertical', title: t('Center') }, { value: 'flex-end', icon: 'b-align-bottom', title: t('End') }, { value: 'stretch', icon: 'b-arrows-out-line-vertical', title: t('Stretch') }] },
          { handle: 'order', type: 'text', component: 'blocks-value', label: t('Display Order'), unit: '', placeholder: '0', prefixIcon: 'b-list-numbers' },
        ]}
      />
    </Section>
  )
}
