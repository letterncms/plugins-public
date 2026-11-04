import { Fields, Icon, cn } from '@letterncms/ui'
import { t, useAbility } from '@letterncms/sdk'
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { useCatalog } from './context'
import { menuEnter, settleEnter } from './slashKeys'
import type { BlockNode, BlockTypeDef } from './types'

export interface PatternDef {
  handle: string
  title: string
  description: string
  category: string
  synced: boolean
  blocks: BlockNode[]
}

export type InsertChoice = { kind: 'block'; type: BlockTypeDef } | { kind: 'pattern'; pattern: PatternDef }

/** A category no catalog names yet: its handle in title case. */
const titled = (handle: string) => handle.replace(/[-_]+/g, ' ').replace(/(^|\s)\S/g, (c) => c.toUpperCase())

export type InsertItem = { kind: 'block'; type: BlockTypeDef; group: string } | { kind: 'pattern'; pattern: PatternDef; group: string }

/** Blocks, then components and sections, that match `query` and fit where they would go. */
export function useInsertItems(types: BlockTypeDef[], query: string, allowed?: (t: BlockTypeDef) => boolean, withPatterns = true): { items: InsertItem[]; loading: boolean } {
  const patterns = useAbility<PatternDef[]>('patterns/list', {}, { staleTime: 60_000 })
  const { catalog } = useCatalog()
  const items = useMemo(() => {
    const categories = catalog?.categories ?? []
    // Categories the catalog doesn't list go last, A–Z.
    const rank = (category: string) => { const at = categories.findIndex((c) => c.name === category); return at < 0 ? categories.length : at }
    const label = (category: string) => categories.find((c) => c.name === category)?.label ?? titled(category)
    const q = query.trim().toLowerCase()
    const match = (s: string) => s.toLowerCase().includes(q)
    const blocks = types
      .filter((type) => allowed ? allowed(type) : !type.parent)
      .filter((type) => !q || match(type.title) || match(type.name) || (type.keywords ?? []).some(match))
      .sort((a, b) => rank(a.category) - rank(b.category) || (rank(a.category) === categories.length ? label(a.category).localeCompare(label(b.category)) : 0))
    // A search puts what it names first: "form" is the Form block, before every field a form can hold.
    const named = (type: BlockTypeDef) => { const title = type.title.toLowerCase(); return title === q ? 0 : title.startsWith(q) ? 1 : match(type.title) ? 2 : 3 }
    const found = (q ? [...blocks].sort((a, b) => named(a) - named(b)) : blocks)
      .map((type) => ({ kind: 'block' as const, type, group: q ? t('Blocks') : label(type.category) }))
    const permits = (name: string) => { const type = types.find((item) => item.name === name); return !!type && (allowed ? allowed(type) : !type.parent) }
    const pats = (withPatterns ? (patterns.data ?? []) : [])
      .filter((pattern) => pattern.synced ? permits('pattern') : pattern.blocks.length > 0 && pattern.blocks.every((node) => permits(node.type)))
      .filter((p) => !q || match(p.title) || match(p.category) || match(p.description))
      .sort((a, b) => Number(b.synced) - Number(a.synced))
      .map((pattern) => ({ kind: 'pattern' as const, pattern, group: pattern.synced ? t('Components') : t('Sections') }))
    return [...found, ...pats]
  }, [types, query, patterns.data, allowed, withPatterns, catalog])
  return { items, loading: withPatterns && patterns.isLoading }
}

/** Searchable block + pattern picker used by the slash menu, the + buttons and the workspace toolbar. */
export function Inserter({ types, query, onQuery, onChoose, onClose, allowed, showSearch = true, className, style, compact, chooseFirst, onNoMatch, patterns: withPatterns = true, keyboardDocument }: { types: BlockTypeDef[]; query: string; onQuery?: (q: string) => void; onChoose: (c: InsertChoice) => void; onClose?: () => void; allowed?: (t: BlockTypeDef) => boolean; showSearch?: boolean; className?: string; style?: React.CSSProperties; compact?: boolean; chooseFirst?: boolean; onNoMatch?: () => void; patterns?: boolean; keyboardDocument?: Document }) {
  const { items, loading } = useInsertItems(types, query, allowed, withPatterns)
  const [active, setActive] = useState(0)
  const list = useRef<HTMLDivElement>(null)

  useEffect(() => setActive(0), [query])

  // The key handler reads the latest list through a ref and is attached in a layout effect:
  // once the menu is on screen it takes Enter, however fast someone typed.
  const latest = useRef({ items, active, loading })
  latest.current = { items, active, loading }
  // Enter came before there was anything to pick (the list not drawn yet, components still loading).
  const pending = useRef(!!chooseFirst)

  useEffect(() => {
    if (!pending.current) return
    const decision = settleEnter(items.length, loading)
    if (decision === 'wait') return
    pending.current = false
    // Nothing matches: the Enter was meant for the text after all.
    decision === 'choose' ? onChoose(items[0]) : onNoMatch?.()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [items, loading])

  useLayoutEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const { items, active, loading } = latest.current
      if (e.key === 'ArrowDown') {
        e.preventDefault()
        setActive((a) => Math.min(items.length - 1, a + 1))
      } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        setActive((a) => Math.max(0, a - 1))
      } else if (e.key === 'Enter') {
        const decision = menuEnter(items.length, active, loading)
        if (decision !== 'pass') {
          e.preventDefault()
          e.stopPropagation()
          if (decision === 'choose') onChoose(items[active])
          else pending.current = true
        }
      } else if (e.key === 'Escape') {
        e.preventDefault()
        onClose?.()
      }
    }
    window.addEventListener('keydown', onKey, true)
    keyboardDocument?.addEventListener('keydown', onKey, true)
    return () => { window.removeEventListener('keydown', onKey, true); keyboardDocument?.removeEventListener('keydown', onKey, true) }
  }, [onChoose, onClose, keyboardDocument])

  useEffect(() => {
    list.current?.querySelector(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' })
  }, [active])

  let lastGroup = ''

  return (
    <div className={cn('flex max-h-[min(420px,60vh)] w-[300px] flex-col', className)} style={style}>
      {showSearch && (
        <div className="border-b border-border p-1.5">
          <Fields bare fields={[{ handle: 'query', type: 'text', label: t('Search'), icon: 'search', size: 'sm', autofocus: true, placeholder: t('Search blocks and components…') }]} values={{ query }} onChange={(_, q) => onQuery?.(q ?? '')} />
        </div>
      )}
      <div ref={list} className="min-h-0 flex-1 overflow-y-auto p-1">
        {items.length === 0 && <div className="px-3 py-6 text-center text-xs text-faint">{t('No matching blocks')}</div>}
        {items.map((item, i) => {
          const heading = item.group !== lastGroup ? item.group : null
          lastGroup = item.group
          const title = item.kind === 'block' ? item.type.title : item.pattern.title
          const description = item.kind === 'block' ? item.type.description : item.pattern.description || (item.pattern.synced ? t('Component') : t('Section'))
          const icon = item.kind === 'block' ? item.type.icon : item.pattern.synced ? 'symbol' : 'pattern'
          return (
            <div key={item.kind === 'block' ? item.type.name : `p-${item.pattern.handle}`}>
              {heading && <div className="cms-label px-2 pb-1 pt-2">{heading}</div>}
              <button
                type="button"
                data-index={i}
                // Moving, not entering: a menu that opens under a resting pointer keeps its first item.
                onMouseMove={() => i !== active && setActive(i)}
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => onChoose(item)}
                className={cn('flex w-full items-center gap-2.5 rounded-sm px-2 text-left', compact ? 'h-8' : 'py-1.5', i === active ? 'bg-hover' : '')}
              >
                <span className="grid size-7 shrink-0 place-items-center rounded-xs border border-border bg-surface text-muted">
                  <Icon name={icon} size={14} />
                </span>
                <span className="min-w-0">
                  <span className="block truncate text-sm font-medium text-text">{title}</span>
                  {!compact && description && <span className="block truncate text-xs text-faint">{description}</span>}
                </span>
              </button>
            </div>
          )
        })}
      </div>
    </div>
  )
}
