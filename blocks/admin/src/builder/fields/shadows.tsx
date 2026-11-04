import { t, type FieldProps } from '@letterncms/sdk'
import { Popover, cn } from '@letterncms/ui'
import { useState } from 'react'
import { useColorPreview } from '../color'
import { AddButton, RemoveButton, PanelFields } from '../controls'
import { splitList, splitValue } from '../css'
import { RowLabel } from './controls'
import { TokenButton, TokenChip, useTokenOf } from './tokens'

/* `blocks-shadows` — box-shadow and text-shadow as a list of layers, like Webflow and Figma:
   each layer a row (preview, offsets, color) that opens its editor; tokens stand for the whole list. */

export interface Shadow {
  inset: boolean
  x: string
  y: string
  blur: string
  spread: string
  color: string
}

const LENGTH = /^-?[\d.]+(px|rem|em)?$/

/** Layers of a shadow value; null for a token. */
export function parseShadows(value: string | undefined | null): Shadow[] | null {
  if (!value || value === 'none') return []
  if (value.startsWith('token:')) return null
  return splitList(value).map((part) => {
    const bits = splitValue(part)
    const inset = bits.includes('inset')
    const rest = bits.filter((b) => b !== 'inset')
    const lengths = rest.filter((b) => LENGTH.test(b))
    const color = rest.find((b) => !LENGTH.test(b)) ?? 'rgb(0 0 0 / 15%)'
    return { inset, x: lengths[0] ?? '0', y: lengths[1] ?? '0', blur: lengths[2] ?? '0', spread: lengths[3] ?? '0', color }
  })
}

export function formatShadows(list: Shadow[], text = false): string | null {
  if (!list.length) return null
  return list.map((s) => (text ? `${s.x} ${s.y} ${s.blur} ${s.color}` : `${s.inset ? 'inset ' : ''}${s.x} ${s.y} ${s.blur} ${s.spread} ${s.color}`)).join(', ')
}

const NEW_BOX: Shadow = { inset: false, x: '0px', y: '8px', blur: '24px', spread: '-8px', color: 'rgb(0 0 0 / 18%)' }
const NEW_TEXT: Shadow = { inset: false, x: '0px', y: '1px', blur: '2px', spread: '0', color: 'rgb(0 0 0 / 25%)' }

const short = (v: string) => v.replace(/px$/, '')

export function ShadowsField({ field, value, onChange, context }: FieldProps<string | null>) {
  const text = field.kind === 'text'
  const group = text ? undefined : ((field.group as string | undefined) ?? 'shadow')
  const tokenOf = useTokenOf()
  const preview = useColorPreview()
  const [editing, setEditing] = useState<number | null>(null)
  const chosen = tokenOf(value)
  const layers = parseShadows(value) ?? []
  const set = (list: Shadow[]) => onChange(formatShadows(list, text))
  const add = () => {
    set([...layers, text ? NEW_TEXT : NEW_BOX])
    setEditing(layers.length)
  }

  return (
    <div className="min-w-0 flex-1">
      {/* Like a value's: the token button shows while the row is pointed at, or when a token is chosen. */}
      <div className="group/value flex h-7 items-center gap-2">
        <RowLabel field={field} />
        <span className="min-w-0 flex-1 truncate text-xs text-faint">{!chosen && !layers.length ? t('None') : ''}</span>
        {group && <TokenButton group={group} value={value} onPick={onChange} active={!!chosen} className="size-6" />}
        {!chosen && <AddButton label={text ? t('Add Text Shadow') : t('Add Shadow')} onClick={add} />}
      </div>
      {chosen && (
        <div className="flex h-7 items-center gap-2 pl-[88px]">
          <span className="size-4 shrink-0 rounded-[3px] border border-border bg-bg" style={{ boxShadow: preview(String(chosen.token.value)) }} />
          <TokenChip token={chosen.token} className="text-xs" />
          <span className="flex-1" />
          <RemoveButton label={t('Remove Shadow')} onClick={() => onChange(null)} />
        </div>
      )}
      {layers.map((layer, i) => (
        <div key={i} className="flex h-7 items-center gap-1 pl-[88px]">
          <Popover
            open={editing === i}
            onOpenChange={(o) => setEditing(o ? i : null)}
            align="end"
            className="w-64 !p-2"
            trigger={
              <button type="button" className={cn('flex h-7 min-w-0 flex-1 items-center gap-1.5 rounded-sm px-1.5 text-left text-xs hover:bg-hover', editing === i && 'bg-active')}>
                {text ? (
                  <span className="grid size-4 shrink-0 place-items-center text-[11px] font-semibold text-text" style={{ textShadow: preview(formatShadows([layer], true) ?? '') }}>A</span>
                ) : (
                  <span className="size-4 shrink-0 rounded-[3px] border border-border bg-bg" style={{ boxShadow: preview(formatShadows([{ ...layer, x: scale(layer.x), y: scale(layer.y), blur: scale(layer.blur), spread: scale(layer.spread) }]) ?? '') }} />
                )}
                <span className="min-w-0 flex-1 truncate font-mono tabular-nums text-muted">
                  {[layer.x, layer.y, layer.blur, ...(text ? [] : [layer.spread])].map(short).join(' ')}
                </span>
                {layer.inset && <span className="rounded-[3px] bg-sunken px-1 text-2xs text-muted">{t('Inside')}</span>}
              </button>
            }
          >
            <p className="m-0 mb-2 text-xs font-semibold text-ink">{text ? t('Text Shadow') : t('Shadow')}</p>
            <PanelFields
              context={context}
              fields={[
                !text && { handle: 'inset', type: 'select', display: 'segmented', block: true, size: 'sm', required: true, label: t('Type'), options: [{ value: 'outside', label: t('Outside') }, { value: 'inside', label: t('Inside') }] },
                { handle: 'x', type: 'text', component: 'blocks-value', label: t('X Offset'), prefix: 'X', placeholder: '0' },
                { handle: 'y', type: 'text', component: 'blocks-value', label: t('Y Offset'), prefix: 'Y', placeholder: '0' },
                { handle: 'blur', type: 'text', component: 'blocks-value', label: t('Blur'), min: 0, prefixIcon: 'b-drop', placeholder: '0' },
                !text && { handle: 'spread', type: 'text', component: 'blocks-value', label: t('Spread'), prefixIcon: 'b-arrows-out-simple', placeholder: '0' },
                { handle: 'color', type: 'color', component: 'blocks-color', label: t('Color') },
              ].filter(Boolean) as never}
              values={{ ...layer, inset: layer.inset ? 'inside' : 'outside' }}
              onChange={(handle, v) => set(layers.map((x, j) => (j === i ? { ...x, [handle]: handle === 'inset' ? v === 'inside' : (v ?? (handle === 'color' ? 'transparent' : '0')) } : x)))}
            />
          </Popover>
          <RemoveButton label={t('Remove Shadow')} onClick={() => set(layers.filter((_, j) => j !== i))} />
        </div>
      ))}
    </div>
  )
}

/** Large offsets would leave the 16px preview; keep its shape, scaled down. */
function scale(v: string): string {
  const n = parseFloat(v)
  return Number.isNaN(n) ? v : `${Math.max(-4, Math.min(4, n / 4))}px`
}
