import { t } from '@letterncms/sdk'
import { useState } from 'react'
import { useEditorContext } from '../../core/context'
import { AddButton, Section, PanelFields } from '../controls'
import type { StyleApi } from '../useStyle'
import { StyleFields } from './StyleFields'

/** Every property set at this breakpoint and state, as CSS — and any property the panels above don't cover. */
export function Css({ s }: { s: StyleApi }) {
  const { catalog } = useEditorContext()
  const [adding, setAdding] = useState(false)
  const layer = s.style[s.variant] ?? {}
  const keys = Object.keys(layer)
  const known = Object.keys(catalog.styles)

  return (
    <Section title={t('CSS')} open={false} empty={keys.length === 0 && !adding} actions={<AddButton label={t('Add a Property')} onClick={() => setAdding(true)} />}>
      <datalist id="cms-css-properties">{known.map((p) => <option key={p} value={p} />)}</datalist>
      <StyleFields
        s={s}
        bare
        fields={[
          {
            handle: 'css',
            type: 'json',
            component: 'blocks-pairs',
            className: 'items-start',
            label: t('CSS'),
            key: { list: 'cms-css-properties', allowed: known, className: '!w-[46%] !flex-none' },
            item: { handle: 'value', type: 'text', component: 'blocks-value', label: t('Value'), unit: '', property: true },
            get: () => layer,
            set: (next: Record<string, string | number | null>) => s.write({ ...Object.fromEntries(keys.filter((k) => !(k in next)).map((k) => [k, null])), ...Object.fromEntries(Object.entries(next).filter(([k, v]) => v !== layer[k])) }),
          },
        ]}
      />
      {adding && (
        <PanelFields
          bare
          fields={[{ handle: 'property', type: 'text', size: 'sm', commit: 'blur', label: t('Property'), list: 'cms-css-properties', mono: true, placeholder: t('Property, e.g. scrollSnapType') }]}
          values={{ property: '' }}
          onChange={(_, key: string) => {
            setAdding(false)
            if (known.includes(key.trim())) s.set(key.trim(), 'initial')
          }}
        />
      )}
    </Section>
  )
}
