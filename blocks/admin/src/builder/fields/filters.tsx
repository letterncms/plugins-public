import { t, type FieldDef, type FieldProps } from '@letterncms/sdk'
import { Icon, Menu } from '@letterncms/ui'
import { RemoveButton } from '../controls'
import { splitValue } from '../css'
import { RowLabel, SubField } from './controls'

/* `blocks-filters` — filter and backdrop-filter as a list of functions (blur, brightness…),
   each a row with its own value; + adds one that is not there yet. */

interface FilterFn {
  name: string
  label: () => string
  unit: string
  /** The value a new row starts with. */
  initial: string
  step: number
  min?: number
  max?: number
  icon: string
}

const FUNCTIONS: FilterFn[] = [
  { name: 'blur', label: () => t('Blur'), unit: 'px', initial: '8px', step: 1, min: 0, icon: 'b-drop' },
  { name: 'brightness', label: () => t('Brightness'), unit: '%', initial: '100%', step: 5, min: 0, icon: 'b-sun' },
  { name: 'contrast', label: () => t('Contrast'), unit: '%', initial: '100%', step: 5, min: 0, icon: 'b-circle-half' },
  { name: 'saturate', label: () => t('Saturation'), unit: '%', initial: '100%', step: 5, min: 0, icon: 'b-drop-half' },
  { name: 'grayscale', label: () => t('Grayscale'), unit: '%', initial: '100%', step: 5, min: 0, max: 100, icon: 'b-circle-half' },
  { name: 'hue-rotate', label: () => t('Hue'), unit: 'deg', initial: '90deg', step: 1, icon: 'b-arrows-clockwise' },
  { name: 'invert', label: () => t('Invert'), unit: '%', initial: '100%', step: 5, min: 0, max: 100, icon: 'b-circle-half' },
  { name: 'sepia', label: () => t('Sepia'), unit: '%', initial: '100%', step: 5, min: 0, max: 100, icon: 'b-paint-brush' },
]

type Part = { name: string; value: string }

export function parseFilters(value: string | null | undefined): Part[] {
  if (!value || value === 'none') return []
  return splitValue(value).map((p) => {
    const m = p.match(/^([a-z-]+)\((.*)\)$/)
    return m ? { name: m[1], value: m[2] } : { name: p, value: '' }
  })
}

const format = (parts: Part[]) => (parts.length ? parts.map((p) => (FUNCTIONS.some((f) => f.name === p.name) || p.value ? `${p.name}(${p.value})` : p.name)).join(' ') : null)

export function FiltersField({ field, value, onChange, context, disabled }: FieldProps<string | null>) {
  const parts = parseFilters(value)
  const missing = FUNCTIONS.filter((f) => !parts.some((p) => p.name === f.name))
  const set = (next: Part[]) => onChange(format(next))

  return (
    <div className="min-w-0 flex-1">
      <div className="flex h-7 items-center gap-2">
        <RowLabel field={field} />
        <span className="min-w-0 flex-1 truncate text-xs text-faint">{parts.length ? '' : t('None')}</span>
        {missing.length > 0 && (
          <Menu
            align="end"
            trigger={
              <button type="button" disabled={disabled} className="grid size-6 shrink-0 place-items-center rounded-sm text-muted hover:bg-hover hover:text-ink" aria-label={t('Add a Filter')} title={t('Add a Filter')}>
                <Icon name="b-plus" size={14} />
              </button>
            }
            items={missing.map((f) => ({ label: f.label(), icon: f.icon, onSelect: () => set([...parts, { name: f.name, value: f.initial }]) }))}
          />
        )}
      </div>
      {parts.map((part, i) => {
        const fn = FUNCTIONS.find((f) => f.name === part.name)
        const sub: FieldDef = fn
          ? { handle: `${field.handle}-${part.name}`, type: 'text', component: 'blocks-value', label: fn.label(), unit: fn.unit, step: fn.step, min: fn.min, max: fn.max, placeholder: fn.initial }
          : { handle: `${field.handle}-${part.name}`, type: 'text', component: 'blocks-value', label: part.name, unit: '' }
        return (
          <div key={part.name + i} className="flex h-7 items-center gap-2">
            <span className="flex w-[88px] shrink-0 items-center gap-1.5 truncate pl-2 text-xs text-muted">
              <Icon name={fn?.icon ?? 'b-faders'} size={13} className="shrink-0 text-faint" />
              {fn?.label() ?? part.name}
            </span>
            <SubField field={sub} value={fn?.unit === '%' && /^[\d.]+$/.test(part.value) ? `${Math.round(parseFloat(part.value) * 1000) / 10}%` : part.value || null} onChange={(v) => set(parts.map((p, j) => (j === i ? { ...p, value: String(v ?? fn?.initial ?? '') } : p)))} disabled={disabled} context={context} />
            <RemoveButton label={t('Remove Filter')} onClick={() => set(parts.filter((_, j) => j !== i))} />
          </div>
        )
      })}
    </div>
  )
}
