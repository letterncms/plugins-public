import { t, useAbility, type FieldProps } from '@letterncms/sdk'
import { fieldControlId, Icon, Popover, Segmented, Spinner, cn } from '@letterncms/ui'
import { useEffect, useState } from 'react'

type Weight = 'regular' | 'bold' | 'fill' | 'light'

const WEIGHTS: Weight[] = ['regular', 'bold', 'fill', 'light']
const weightLabel = (w: Weight) => ({ regular: t('Regular'), bold: t('Bold'), fill: t('Fill'), light: t('Light') })[w]

const split = (name: string): { base: string; weight: Weight } => {
  const m = name.match(/^(.*)-(bold|fill|light)$/)
  return m ? { base: m[1], weight: m[2] as Weight } : { base: name, weight: 'regular' }
}

/** `blocks-icon` — a Phosphor icon picked from a searchable grid, in one of four weights. */
export function IconField({ field, value, onChange, disabled }: FieldProps<string | null>) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [debounced, setDebounced] = useState('')
  const name = (value ?? '').replace(/^ph:/, '')
  const { base, weight } = split(name)
  const preview = useAbility<{ name: string; svg: string }[]>('blocks/icons', { query: base, limit: 1 }, { enabled: !!base, staleTime: Infinity })
  const results = useAbility<{ name: string; svg: string }[]>('blocks/icons', { query: debounced, limit: 160 }, { enabled: open, staleTime: Infinity })
  const current = preview.data?.find((i) => i.name === base) ?? preview.data?.[0]

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(query.trim()), 140)
    return () => window.clearTimeout(timer)
  }, [query])

  const pick = (icon: string, w: Weight = weight) => onChange(w === 'regular' ? icon : `${icon}-${w}`)

  return (
    <div className="group/icon relative min-w-0 flex-1">
      <Popover
        open={open}
        onOpenChange={(o) => !disabled && setOpen(o)}
        className="w-[284px] !p-0"
        trigger={
          <button
            type="button"
            id={fieldControlId(field)}
            disabled={disabled}
            aria-label={field.label}
            className={cn('cms-input flex h-7 w-full min-w-0 items-center gap-2 rounded-sm px-2 text-left text-sm disabled:opacity-50', name && 'pr-6')}
          >
            {name && current ? <span className="grid size-3.5 shrink-0 place-items-center text-text [&_svg]:size-3.5" dangerouslySetInnerHTML={{ __html: current.svg }} /> : <Icon name="star" size={14} className="shrink-0 text-faint" />}
            <span className={cn('min-w-0 flex-1 truncate', !name && 'text-ghost')}>{name ? base : (field.placeholder as string) || t('Choose an Icon')}</span>
            {name && weight !== 'regular' && <span className="shrink-0 text-faint">{weightLabel(weight)}</span>}
          </button>
        }
      >
        <div className="flex h-10 items-center gap-2 border-b border-border px-3">
          <Icon name="search" size={14} className="shrink-0 text-faint" />
          <input
            autoFocus
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t('Search Icons')}
            aria-label={t('Search Icons')}
            spellCheck={false}
            className="h-full min-w-0 flex-1 bg-transparent text-sm text-text outline-none placeholder:text-faint"
          />
          {results.isFetching && <Spinner size={12} />}
        </div>
        <div className="border-b border-border p-1.5">
          <Segmented
            size="xs"
            block
            value={weight}
            onChange={(w) => (name ? pick(base, w) : undefined)}
            options={WEIGHTS.map((w) => ({ value: w, label: weightLabel(w) }))}
          />
        </div>
        <div className="grid max-h-64 grid-cols-8 gap-0.5 overflow-y-auto p-1.5" role="listbox" aria-label={t('Icons')}>
          {(results.data ?? []).map((icon) => (
            <button
              key={icon.name}
              type="button"
              role="option"
              aria-selected={icon.name === base}
              title={icon.name}
              onClick={() => {
                pick(icon.name)
                setOpen(false)
              }}
              className={cn('grid aspect-square place-items-center rounded-xs text-text hover:bg-hover [&_svg]:size-4', icon.name === base && 'bg-focus-soft text-focus')}
              dangerouslySetInnerHTML={{ __html: icon.svg }}
            />
          ))}
          {!results.isFetching && results.data?.length === 0 && <p className="col-span-8 m-0 py-6 text-center text-xs text-faint">{t('No icons found')}</p>}
        </div>
      </Popover>
      {name && !disabled && (
        <button type="button" onClick={() => onChange(null)} aria-label={t('Remove Icon')} className="absolute right-1 top-1/2 grid size-5 -translate-y-1/2 place-items-center rounded-xs text-faint opacity-0 hover:bg-hover hover:text-text focus-visible:opacity-100 group-hover/icon:opacity-100">
          <Icon name="close" size={12} />
        </button>
      )}
    </div>
  )
}
