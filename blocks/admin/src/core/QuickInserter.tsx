import { t } from '@letterncms/sdk'
import { Fields, Icon, cn } from '@letterncms/ui'
import { useEffect, useMemo, useState, type KeyboardEvent } from 'react'
import { Inserter, useInsertItems, type InsertChoice, type InsertItem } from './Inserter'
import type { BlockTypeDef } from './types'

const RECENT = 'cms:blocks:recent'
/** What an empty quick inserter suggests after the blocks you used last. */
const DEFAULTS = ['paragraph', 'heading', 'image', 'list', 'button', 'box']
const SUGGESTED = 6, RESULTS = 9, COLUMNS = 3

function recent(): string[] {
  try { const value = JSON.parse(localStorage.getItem(RECENT) ?? '[]'); return Array.isArray(value) ? value.filter((name) => typeof name === 'string') : [] } catch { return [] }
}
function remember(choice: InsertChoice) {
  if (choice.kind !== 'block') return
  try { localStorage.setItem(RECENT, JSON.stringify([choice.type.name, ...recent().filter((name) => name !== choice.type.name)].slice(0, 12))) } catch { /* Private windows keep the defaults. */ }
}

/**
 * Gutenberg's quick inserter: search, six suggestions for this spot, and **Browse all** for the full
 * catalogue with components and sections. Every insertion control opens this same picker.
 */
export function QuickInserter({ types, allowed, onChoose, onClose }: { types: BlockTypeDef[]; allowed: (type: BlockTypeDef) => boolean; onChoose: (choice: InsertChoice) => void; onClose: () => void }) {
  const [query, setQuery] = useState('')
  const [browsing, setBrowsing] = useState(false)
  const [active, setActive] = useState(0)
  const { items: found } = useInsertItems(types, query, allowed, !!query.trim())
  const items = useMemo<InsertItem[]>(() => {
    if (query.trim()) return found.slice(0, RESULTS)
    const order = [...recent(), ...DEFAULTS]
    const rank = (item: InsertItem) => { const at = item.kind === 'block' ? order.indexOf(item.type.name) : -1; return at < 0 ? order.length : at }
    return found.filter((item) => item.kind === 'block').sort((a, b) => rank(a) - rank(b)).slice(0, SUGGESTED)
  }, [found, query])
  useEffect(() => setActive(0), [query])
  const choose = (item: InsertChoice) => { remember(item); onChoose(item) }

  if (browsing) return <div data-cms-insert-menu data-cms-browse><Inserter types={types} query={query} onQuery={setQuery} allowed={allowed} onChoose={choose} onClose={onClose} /></div>

  const onKeyDown = (event: KeyboardEvent) => {
    const step = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: COLUMNS, ArrowUp: -COLUMNS }[event.key]
    if (step && items.length) {
      event.preventDefault()
      setActive((current) => Math.max(0, Math.min(items.length - 1, current + step)))
    } else if (event.key === 'Enter' && items[active]) {
      event.preventDefault()
      choose(items[active])
    } else if (event.key === 'Escape') {
      event.preventDefault()
      onClose()
    }
  }

  return <div data-cms-insert-menu className="flex w-[300px] flex-col" onKeyDown={onKeyDown}>
    <div className="p-2 pb-0">
      <Fields bare fields={[{ handle: 'query', type: 'text', label: t('Search'), icon: 'search', size: 'sm', autofocus: true, placeholder: t('Search blocks and components…') }]} values={{ query }} onChange={(_, value) => setQuery(value ?? '')} />
    </div>
    <div role="listbox" aria-label={query.trim() ? t('Results') : t('Suggestions')} className="grid grid-cols-3 gap-1 p-2">
      {items.map((item, index) => {
        const title = item.kind === 'block' ? item.type.title : item.pattern.title
        const icon = item.kind === 'block' ? item.type.icon : item.pattern.synced ? 'symbol' : 'pattern'
        return <button key={item.kind === 'block' ? item.type.name : `p-${item.pattern.handle}`} type="button" role="option" aria-selected={index === active} data-index={index}
          onMouseMove={() => index !== active && setActive(index)} onMouseDown={(event) => event.preventDefault()} onClick={() => choose(item)}
          className={cn('flex h-[68px] min-w-0 flex-col items-center justify-center gap-1.5 rounded-sm px-1 text-text', index === active && 'bg-hover')}>
          <Icon name={icon} size={20} />
          <span className="w-full truncate text-center text-xs font-medium">{title}</span>
        </button>
      })}
      {!items.length && <div className="col-span-3 px-3 py-5 text-center text-xs text-faint">{t('No matching blocks')}</div>}
    </div>
    <button type="button" onMouseDown={(event) => event.preventDefault()} onClick={() => setBrowsing(true)} className="cms-focus-ring flex h-9 shrink-0 items-center justify-center gap-1.5 border-t border-border text-sm font-medium text-text hover:bg-hover">
      {t('Browse All')}<Icon name="arrow-right" size={14} className="text-muted" />
    </button>
  </div>
}
