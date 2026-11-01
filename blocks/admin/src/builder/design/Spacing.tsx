import { t } from '@letterncms/sdk'
import { sides, splitValue } from '../css'
import { Section } from '../controls'
import type { SideStatus } from '../fields/spacing'
import type { StyleApi } from '../useStyle'
import { StyleFields } from './StyleFields'

type Box = 'padding' | 'margin'
const SIDES = ['Top', 'Right', 'Bottom', 'Left'] as const

/** The property one side's value comes from: longhand, then logical, then shorthand. */
function source(s: StyleApi, box: Box, i: number): string | null {
  const keys = [`${box}${SIDES[i]}`, i % 2 === 0 ? `${box}Block` : `${box}Inline`, box]
  return keys.find((k) => s.value(k) !== undefined) ?? null
}

/** Current value of one side, from longhand, logical or shorthand properties. */
function side(s: StyleApi, box: Box, i: number): string | undefined {
  const key = source(s, box, i)
  if (!key) return undefined
  const v = s.value(key)
  if (key === box) return sides(v)?.[i]
  if (key.endsWith('Block') || key.endsWith('Inline')) {
    // Logical pairs are "start end": top/left take the first, bottom/right the second.
    const [start, end] = splitValue(String(v))
    return i === 0 || i === 3 ? start : end ?? start
  }
  return v
}

const keysOf = (box: Box) => [box, `${box}Block`, `${box}Inline`, ...SIDES.map((n) => `${box}${n}`)]

/** Box-model spacing editor: margin outside, padding inside; click a side to type, drag to scrub. */
export function Spacing({ s }: { s: StyleApi }) {
  const boxes: Box[] = ['margin', 'padding']
  const any = boxes.some((b) => SIDES.some((_, i) => side(s, b, i)))
  const value = Object.fromEntries(boxes.map((b) => [b, [0, 1, 2, 3].map((i) => side(s, b, i))]))
  const status = Object.fromEntries(
    boxes.map((b) => [
      b,
      [0, 1, 2, 3].map((i): SideStatus => {
        const key = source(s, b, i)
        return key ? (s.status(key) === 'own' ? 'own' : 'inherited') : 'none'
      }),
    ]),
  )

  // Four sides turn shorthands into explicit sides (or one shorthand when all four match).
  const write = (box: Box, v: (string | undefined)[]) => {
    const patch: Record<string, string | null> = { [box]: null, [`${box}Block`]: null, [`${box}Inline`]: null }
    const same = v.every((x) => x !== undefined && x === v[0])
    if (same) patch[box] = v[0]!
    SIDES.forEach((name, j) => (patch[`${box}${name}`] = same ? null : v[j] ?? null))
    s.write(patch)
  }

  return (
    <Section title={t('Spacing')} empty={!any}>
      <StyleFields
        s={s}
        bare
        fields={[
          {
            handle: 'spacing',
            type: 'json',
            component: 'blocks-spacing',
            className: 'items-start',
            label: t('Spacing'),
            sideStatus: status,
            keys: [...keysOf('margin'), ...keysOf('padding')],
            get: () => value,
            set: (next) => boxes.forEach((b) => next[b] !== value[b] && write(b, next[b])),
          },
        ]}
      />
    </Section>
  )
}
