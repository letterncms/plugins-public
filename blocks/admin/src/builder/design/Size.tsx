import { t } from '@letterncms/sdk'
import { Section } from '../controls'
import type { StyleApi } from '../useStyle'
import { StyleFields, type StyleField } from './StyleFields'

/** Width or height: a value, with Fill / Fit / Auto / window presets. */
function dimension(s: StyleApi, prop: 'width' | 'height', label: string): StyleField {
  const value = s.value(prop)
  const mode = value === '100%' ? 'fill' : value === 'fit-content' ? 'fit' : value === 'auto' || !value ? 'auto' : value.endsWith('vh') || value.endsWith('vw') || value.endsWith('dvh') ? 'viewport' : 'fixed'
  const names = { fill: t('Fill'), fit: t('Fit'), auto: t('Auto'), viewport: t('Window'), fixed: t('Fixed') }
  return {
    handle: prop,
    type: 'text',
    component: 'blocks-value',
    label,
    prefix: prop === 'width' ? 'W' : 'H',
    placeholder: t('Auto'),
    keywords: ['auto', 'fit-content', 'min-content', 'max-content'],
    group: prop === 'width' ? 'container' : undefined,
    menu: {
      label: names[mode],
      items: [
        { label: t('Fill the Parent (100%)'), value: '100%', checked: mode === 'fill' },
        { label: t('Fit the Content'), value: 'fit-content', checked: mode === 'fit' },
        { label: t('Auto'), value: null, checked: mode === 'auto' },
        { label: prop === 'width' ? t('Window Width (100vw)') : t('Window Height (100dvh)'), value: prop === 'width' ? '100vw' : '100dvh', checked: mode === 'viewport' },
      ],
    },
  }
}

/** Width and height (fill / fit / fixed), limits, aspect ratio, overflow and media fit. */
export function Size({ s, media }: { s: StyleApi; media?: boolean }) {
  const any = ['width', 'height', 'minWidth', 'maxWidth', 'minHeight', 'maxHeight', 'aspectRatio', 'overflow'].some((k) => s.value(k))
  return (
    <Section title={t('Size')} empty={!any}>
      <StyleFields
        s={s}
        fields={[
          dimension(s, 'width', t('Width')),
          dimension(s, 'height', t('Height')),
          {
            handle: 'limits',
            type: 'group',
            component: 'blocks-group',
            label: t('Min · Max'),
            columns: 2,
            fields: [
              { handle: 'minWidth', type: 'text', component: 'blocks-value', label: t('Min Width'), prefix: 'W', placeholder: t('Min'), title: t('Minimum Width'), min: 0 },
              { handle: 'maxWidth', type: 'text', component: 'blocks-value', label: t('Max Width'), prefix: 'W', placeholder: t('Max'), group: 'container', title: t('Maximum Width'), min: 0, keywords: ['none'] },
              { handle: 'minHeight', type: 'text', component: 'blocks-value', label: t('Min Height'), prefix: 'H', placeholder: t('Min'), title: t('Minimum Height'), min: 0 },
              { handle: 'maxHeight', type: 'text', component: 'blocks-value', label: t('Max Height'), prefix: 'H', placeholder: t('Max'), title: t('Maximum Height'), min: 0, keywords: ['none'] },
            ],
          },
          { handle: 'aspectRatio', type: 'select', size: 'sm', label: t('Aspect'), placeholder: t('Auto'), options: [{ value: '1/1', label: t('1:1 square') }, { value: '4/3', label: '4:3' }, { value: '3/2', label: '3:2' }, { value: '16/9', label: '16:9' }, { value: '21/9', label: '21:9' }, { value: '3/4', label: t('3:4 portrait') }, { value: '4/5', label: '4:5' }, { value: '9/16', label: t('9:16 story') }] },
          {
            handle: 'overflow',
            type: 'select',
            display: 'segmented',
            block: true,
            size: 'sm',
            label: t('Overflow'),
            placeholder: 'visible',
            options: [
              { value: 'visible', icon: 'b-eye', title: t('Visible') },
              { value: 'hidden', icon: 'b-eye-slash', title: t('Hidden') },
              { value: 'auto', icon: 'b-mouse-scroll', title: t('Scroll When Needed') },
              { value: 'clip', icon: 'b-scissors', title: t('Clip, without scrolling') },
            ],
          },
          media && { handle: 'objectFit', type: 'select', display: 'segmented', block: true, size: 'sm', label: t('Fit'), options: [{ value: 'cover', label: t('Cover') }, { value: 'contain', label: t('Contain') }, { value: 'fill', label: t('Stretch') }, { value: 'none', label: t('None') }] },
          media && { handle: 'objectPosition', type: 'json', component: 'blocks-origin', label: t('Focus'), className: 'items-start py-0.5 [&>label]:leading-6' },
        ]}
      />
    </Section>
  )
}
