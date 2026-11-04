import { t } from '@letterncms/sdk'
import { useState } from 'react'
import { sides, splitValue } from '../css'
import { Section } from '../controls'
import type { StyleApi } from '../useStyle'
import { StyleFields, changed, shownStatus } from './StyleFields'

const CORNERS = ['borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius'] as const
const EDGES = ['borderTop', 'borderRight', 'borderBottom', 'borderLeft'] as const
const STYLES = ['solid', 'dashed', 'dotted', 'double', 'none']
const isStyle = (p: string) => STYLES.includes(p)
const isWidth = (p: string) => /^-?[\d.]/.test(p) || /^token:(space|border)/.test(p) || /^(thin|medium|thick)$/.test(p)

type Edge = 'all' | (typeof EDGES)[number]
interface Line {
  width?: string
  style?: string
  color?: string
}

/** "1px solid token:color.border" as its parts. */
export function parseLine(value: string | undefined): Line {
  const parts = splitValue(value ?? '')
  return { width: parts.find(isWidth), style: parts.find(isStyle), color: parts.find((p) => !isWidth(p) && !isStyle(p)) }
}

export const formatLine = (line: Line) => (line.width || line.style || line.color ? [line.width ?? '1px', line.style ?? 'solid', line.color].filter(Boolean).join(' ') : null)

const styleOptions = () => [
  { value: 'solid', label: t('Solid') },
  { value: 'dashed', label: t('Dashed') },
  { value: 'dotted', label: t('Dotted') },
  { value: 'double', label: t('Double') },
  { value: 'none', label: t('None') },
]

/** Border on every side or one side (width, style, color), corner radius (linked or per corner), and outline. */
export function Border({ s }: { s: StyleApi }) {
  const [edge, setEdge] = useState<Edge>(() => EDGES.find((k) => s.value(k)) ?? 'all')
  const shorthand = parseLine(s.value('border'))
  const all: Line = { width: s.value('borderWidth') ?? shorthand.width, style: s.value('borderStyle') ?? shorthand.style, color: s.value('borderColor') ?? shorthand.color }
  const line: Line = edge === 'all' ? all : parseLine(s.value(edge))
  const radii = CORNERS.map((c) => s.value(c))
  const corner = (i: number) => s.value(CORNERS[i]) ?? sides(s.value('borderRadius'))?.[i]
  const outline = parseLine(s.value('outline'))
  const any = all.width || all.style || all.color || s.value('borderRadius') || radii.some(Boolean) || EDGES.some((k) => s.value(k)) || s.value('outline')
  const edgeStatus = (k: Edge) => (k === 'all' ? ['border', 'borderWidth', 'borderStyle', 'borderColor'] : [k]).map((key) => s.status(key))
  const status = (k: Edge) => (edgeStatus(k).includes('own') ? 'own' : edgeStatus(k).includes('inherited') ? 'inherited' : undefined)

  // Editing all sides turns the shorthand into its three properties; one side keeps its own shorthand.
  const setLine = (patch: Line) => {
    const next = { ...line, ...patch }
    if (patch.width && !next.style) next.style = 'solid'
    if (edge === 'all') s.write({ border: null, borderWidth: next.width ?? null, borderStyle: next.style ?? null, borderColor: next.color ?? null })
    else s.set(edge, formatLine(next))
  }

  const sideOption = (k: Edge, icon: string, title: string) => ({ value: k, icon, title: status(k) ? `${title} · ${status(k) === 'own' ? t('set') : t('inherited')}` : title })

  return (
    <Section title={t('Border')} empty={!any}>
      <StyleFields
        s={s}
        fields={[
          {
            handle: 'edge',
            type: 'select',
            display: 'segmented',
            block: true,
            required: true,
            size: 'sm',
            label: t('Sides'),
            keys: [],
            get: () => edge,
            set: (v) => v && setEdge(v),
            options: [sideOption('all', 'b-side-all', t('All Sides')), sideOption('borderTop', 'b-side-top', t('Top')), sideOption('borderRight', 'b-side-right', t('Right')), sideOption('borderBottom', 'b-side-bottom', t('Bottom')), sideOption('borderLeft', 'b-side-left', t('Left'))],
          },
          {
            handle: 'line',
            type: 'group',
            component: 'blocks-group',
            label: t('Width'),
            status: status(edge),
            keys: edge === 'all' ? ['border', 'borderWidth', 'borderStyle'] : [edge],
            get: () => ({ width: line.width, style: line.style }),
            set: (v) => setLine(changed({ width: line.width, style: line.style }, v)),
            fields: [
              { handle: 'width', type: 'text', component: 'blocks-value', label: t('Width'), placeholder: '0', min: 0, prefixIcon: 'b-square' },
              { handle: 'style', type: 'select', size: 'sm', label: t('Style'), options: styleOptions(), placeholder: t('Style') },
            ],
          },
          {
            handle: 'lineColor',
            type: 'color',
            component: 'blocks-color',
            label: t('Color'),
            placeholder: t('Text Color'),
            keys: edge === 'all' ? ['border', 'borderColor'] : [edge],
            status: edge === 'all' ? shownStatus(s.status('borderColor')) : status(edge),
            get: () => line.color,
            set: (v) => setLine({ color: v ?? undefined }),
          },
          {
            handle: 'radius',
            type: 'group',
            component: 'blocks-group',
            label: t('Radius'),
            status: s.status('borderRadius') === 'own' || CORNERS.some((c) => s.status(c) === 'own') ? 'own' : shownStatus(s.status('borderRadius')),
            keys: ['borderRadius', ...CORNERS],
            columns: 2,
            split: radii.some(Boolean),
            splitLabel: t('Each Corner'),
            joinLabel: t('Same radius for every corner'),
            link: { handle: 'borderRadius', type: 'text', component: 'blocks-value', label: t('Radius'), group: 'radius', prefixIcon: 'b-frame-corners', placeholder: '0', min: 0 },
            fields: CORNERS.map((c, i) => ({ handle: c, type: 'text', component: 'blocks-value', label: [t('Top Left'), t('Top Right'), t('Bottom Right'), t('Bottom Left')][i], title: [t('Top Left'), t('Top Right'), t('Bottom Right'), t('Bottom Left')][i], group: 'radius', prefix: ['◜', '◝', '◞', '◟'][i], placeholder: '0', min: 0 })),
            get: () => ({ borderRadius: s.value('borderRadius'), ...Object.fromEntries(CORNERS.map((c, i) => [c, corner(i)])) }),
            set: (v) => {
              if (v.borderRadius !== s.value('borderRadius')) return s.set('borderRadius', v.borderRadius)
              s.write({ borderRadius: null, ...Object.fromEntries(CORNERS.map((c) => [c, v[c] ?? null])) })
            },
          },
        ]}
      />
      <div className="!mt-3 border-t border-border pt-2">
        <StyleFields
          s={s}
          fields={[
            {
              handle: 'outlineLine',
              type: 'group',
              component: 'blocks-group',
              label: t('Outline'),
              keys: ['outline'],
              get: () => ({ width: outline.width, style: outline.style }),
              set: (v) => s.set('outline', formatLine({ ...outline, ...changed({ width: outline.width, style: outline.style }, v) })),
              fields: [
                { handle: 'width', type: 'text', component: 'blocks-value', label: t('Width'), placeholder: '0', min: 0, prefixIcon: 'b-selection' },
                { handle: 'style', type: 'select', size: 'sm', label: t('Style'), options: styleOptions(), placeholder: t('Style') },
              ],
            },
            outline.width || outline.style
              ? { handle: 'outlineColor', type: 'color', component: 'blocks-color', label: t('Outline Color'), placeholder: t('Text Color'), keys: ['outline'], get: () => outline.color, set: (v) => s.set('outline', formatLine({ ...outline, color: v ?? undefined })) }
              : null,
            outline.width || outline.style || s.value('outlineOffset') ? { handle: 'outlineOffset', type: 'text', component: 'blocks-value', label: t('Offset'), placeholder: '0', prefixIcon: 'b-arrows-out-simple' } : null,
          ]}
        />
      </div>
    </Section>
  )
}
