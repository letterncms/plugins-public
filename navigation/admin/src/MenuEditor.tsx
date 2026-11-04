import { can, invalidate, run, t, useAbility, useManifest, type FieldDef, type SurfaceComponentProps } from '@letterncms/sdk'
import { Badge, Button, EmptyState, Fields, Icon, IconButton, Popover, SaveBar, Skeleton, SortableTree, Spinner, cn } from '@letterncms/ui'
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'

interface Target {
  title: string | null
  uri: string | null
  status: string
  collection: string | null
}

interface Item {
  id: string
  entry?: string
  url?: string
  label?: string
  newTab?: boolean
  locales?: Record<string, { label?: string; url?: string }>
  target?: Target
  children: Item[]
  [key: string]: unknown
}

interface Menu {
  handle: string
  title: string
  maxDepth: number
  items: Item[]
}

interface Found {
  id: string
  title: string
  uri: string | null
  collection: string
  displayStatus: string
}

// How deep links may nest, said plainly: 1 is a flat menu, 2 allows one level of submenus.
const levels = (): FieldDef => ({ handle: 'maxDepth', type: 'select', label: t('Submenus'), size: 'sm', required: true, options: [
  { value: '1', label: t('No Submenus') },
  { value: '2', label: t('One Level') },
  ...[3, 4, 5].map((n) => ({ value: String(n), label: t('{count} Levels', { count: n - 1 }) })),
] })
const search = (): FieldDef => ({ handle: 'query', type: 'text', label: t('Search Content'), autofocus: true, placeholder: t('Search pages and posts…') })

/** What is saved of a menu: its items without what is looked up for showing them, and nothing empty. */
const clean = (list: Item[]): Record<string, unknown>[] =>
  list.map(({ target: _t, ...i }) => ({ ...i, label: i.label?.trim() || undefined, url: i.url?.trim() || undefined, newTab: i.newTab || undefined, locales: words(i.locales), children: clean(i.children) }))
const words = (locales: Item['locales']) => {
  const filled = Object.entries(locales ?? {}).flatMap(([code, row]) => {
    const kept = Object.fromEntries(Object.entries(row ?? {}).filter(([, value]) => typeof value === 'string' && value.trim() !== ''))
    return Object.keys(kept).length ? [[code, kept]] : []
  })
  return filled.length ? Object.fromEntries(filled) : undefined
}
const snapshot = (maxDepth: number, items: Item[]) => JSON.stringify([maxDepth, clean(items)])

const newId = () => Array.from(crypto.getRandomValues(new Uint8Array(8)), (b) => 'abcdefghijklmnopqrstuvwxyz0123456789'[b % 36]).join('')

/**
 * The links of one menu, as a tree to drag: the place around it (its name, Rename and Delete)
 * is the view `menu` (NavigationPlugin::places). Saved from the save bar.
 */
export default function MenuEditor({ props }: SurfaceComponentProps<{ handle: string }>) {
  const menu = useAbility<Menu>('navigation/get', { handle: props.handle }, { refetchOnWindowFocus: false })

  if (menu.isLoading) return <Skeleton className="h-40" />
  if (!menu.data) return <EmptyState icon="list-tree" title={t('Not Found')} description={t('It may have been moved or deleted.')} />

  return <Editor key={menu.data.handle} menu={menu.data} />
}

function Editor({ menu }: { menu: Menu }) {
  const [items, setItems] = useState<Item[]>(menu.items)
  const [maxDepth, setMaxDepth] = useState(menu.maxDepth)
  const [open, setOpen] = useState<Set<string>>(new Set())
  const [saved, setSaved] = useState(() => snapshot(menu.maxDepth, menu.items))
  // What Discard puts back: the menu as last saved.
  const [base, setBase] = useState<Pick<Menu, 'maxDepth' | 'items'>>(menu)
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState<string | null>(null)
  const manage = can('navigation.manage')
  // Changed only while it differs from what was saved: putting something back is no change.
  const dirty = useMemo(() => snapshot(maxDepth, items) !== saved, [maxDepth, items, saved])

  const change = (next: Item[]) => setItems(next)

  const patch = (id: string, values: Partial<Item>) => {
    const walk = (list: Item[]): Item[] => list.map((i) => (i.id === id ? { ...i, ...values } : { ...i, children: walk(i.children) }))
    change(walk(items))
  }

  const remove = (id: string) => {
    const walk = (list: Item[]): Item[] => list.filter((i) => i.id !== id).map((i) => ({ ...i, children: walk(i.children) }))
    change(walk(items))
  }

  const toggle = (id: string) => setOpen((s) => {
    const next = new Set(s)
    next.has(id) ? next.delete(id) : next.add(id)
    return next
  })

  const addEntry = (e: Found) => change([...items, { id: newId(), entry: e.id, target: { title: e.title, uri: e.uri, status: e.displayStatus, collection: e.collection }, children: [] }])

  const addLink = () => {
    const id = newId()
    change([...items, { id, url: '', label: '', children: [] }])
    setOpen((s) => new Set(s).add(id))
  }

  const discard = () => {
    setMaxDepth(base.maxDepth)
    setItems(base.items)
    setFailed(null)
  }

  const save = async () => {
    setBusy(true)
    setFailed(null)
    try {
      const stored = await run<Menu>('navigation/save', { handle: menu.handle, maxDepth, items: clean(items) })
      setItems(stored.items)
      setMaxDepth(stored.maxDepth)
      setSaved(snapshot(stored.maxDepth, stored.items))
      setBase(stored)
      invalidate('navigation/')
    } catch (e) {
      setFailed(e instanceof Error ? e.message : String(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      {items.length === 0 ? (
        <div className="rounded-md border border-dashed border-border-strong px-6 py-10 text-center text-sm text-muted">{t('This menu is empty.')}</div>
      ) : (
        <SortableTree<Item>
          items={items}
          maxDepth={maxDepth - 1}
          onChange={change}
          renderItem={(item, ctx) => (
            <Row
              item={item}
              ctx={ctx}
              expanded={open.has(item.id)}
              disabled={!manage}
              onToggle={() => toggle(item.id)}
              onPatch={(v) => patch(item.id, v)}
              onRemove={() => remove(item.id)}
            />
          )}
        />
      )}

      {manage && (
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <AddPage onPick={addEntry} />
          <Button icon="link" onClick={addLink}>{t('Add Link')}</Button>
          <div className="ml-auto flex items-center gap-2 text-sm text-muted">
            <span aria-hidden>{t('Submenus')}</span>
            <span className="w-36"><Fields bare fields={[levels()]} values={{ maxDepth: String(maxDepth) }} onChange={(_, v) => setMaxDepth(Number(v))} /></span>
          </div>
        </div>
      )}
      {manage && <SaveBar dirty={dirty} saving={busy} error={failed} onSave={() => void save()} onDiscard={discard} />}
    </>
  )
}

type RowCtx = { depth: number; handle: ReactNode; indent: () => void; outdent: () => void; canIndent: boolean; canOutdent: boolean }

function Row({ item, ctx, expanded, disabled, onToggle, onPatch, onRemove }: { item: Item; ctx: RowCtx; expanded: boolean; disabled: boolean; onToggle: () => void; onPatch: (v: Partial<Item>) => void; onRemove: () => void }) {
  const link = !item.entry
  const status = item.target?.status
  const where = link ? item.url || t('No URL yet') : item.target?.uri ?? t('No URL')
  const site = useManifest().site
  const languages = site.locales.filter((code: string) => code !== site.defaultLocale)
  const language = (code: string) => new Intl.DisplayNames([code], { type: 'language' }).of(code) ?? code

  return (
    <div className={cn('group rounded-sm border bg-bg', expanded ? 'border-border-strong' : 'border-border hover:border-border-strong')}>
      <div className="flex h-10 items-center gap-1.5 pl-1 pr-1.5">
        {!disabled && ctx.handle}
        <Icon name={link ? 'link' : 'page'} size={14} className="shrink-0 text-muted" />
        <Fields
          bare
          className="min-w-0 flex-1"
          disabled={disabled}
          fields={[{ handle: 'label', type: 'text', component: 'navigation-label', label: t('Label'), placeholder: link ? t('Label') : item.target?.title ?? t('Missing entry'), display: link ? undefined : 'fallback' }]}
          values={{ label: item.label ?? '' }}
          onChange={(_, v) => onPatch({ label: v })}
        />
        <span className={cn('max-w-[38%] truncate text-xs', link && !item.url ? 'text-warning' : 'text-faint')}>{where}</span>
        {status && status !== 'published' && status !== 'changed' && <Badge tone={status === 'missing' ? 'danger' : 'neutral'}>{status === 'missing' ? t('Missing') : t('Hidden until live')}</Badge>}
        {!disabled && (
          <div className="flex items-center text-faint">
            <IconButton icon="arrow-left" label={t('Move Out a Level')} size="xs" disabled={!ctx.canOutdent} onClick={ctx.outdent} />
            <IconButton icon="arrow-right" label={t('Nest Under the Item Above')} size="xs" disabled={!ctx.canIndent} onClick={ctx.indent} />
            <IconButton icon="settings" label={t('Link Options')} size="xs" active={expanded} onClick={onToggle} />
            <IconButton icon="trash" label={t('Remove')} size="xs" onClick={onRemove} />
          </div>
        )}
      </div>
      {expanded && (
        <Fields
          className="gap-y-2.5! border-t border-border px-3 py-3 pl-9"
          disabled={disabled}
          fields={[
            ...(link ? [{ handle: 'url', type: 'url', component: 'link', label: t('Link'), placeholder: t('Choose a page or paste a link') }] : []),
            { handle: 'newTab', type: 'toggle', label: t('Open in a New Tab'), display: 'checkbox' },
            // What this item says, and for a link where it goes, in the site's other languages.
            ...languages.flatMap((code: string): FieldDef[] => [
              { handle: `label:${code}`, type: 'text', label: t('Label in {language}', { language: language(code) }), placeholder: item.label || item.target?.title || undefined, width: link ? 'half' : undefined },
              ...(link ? [{ handle: `url:${code}`, type: 'url', component: 'link', label: t('Link in {language}', { language: language(code) }), placeholder: item.url || undefined, width: 'half' } as FieldDef] : []),
            ]),
          ]}
          values={{ url: item.url ?? '', newTab: !!item.newTab, ...Object.fromEntries(languages.flatMap((code: string) => [[`label:${code}`, item.locales?.[code]?.label ?? ''], [`url:${code}`, item.locales?.[code]?.url ?? '']])) }}
          onChange={(h, v) => {
            const [key, code] = h.split(':')
            if (!code) return onPatch(h === 'newTab' ? { newTab: v || undefined } : { url: v })
            onPatch({ locales: { ...item.locales, [code]: { ...item.locales?.[code], [key]: v } } })
          }}
        />
      )}
    </div>
  )
}

function AddPage({ onPick }: { onPick: (e: Found) => void }) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [debounced, setDebounced] = useState('')
  const timer = useRef<number | undefined>(undefined)

  useEffect(() => {
    window.clearTimeout(timer.current)
    timer.current = window.setTimeout(() => setDebounced(query.trim()), 180)
  }, [query])

  const results = useAbility<Found[]>(debounced ? 'content/search' : 'content/recent', debounced ? { query: debounced } : {}, { enabled: open })
  const routable = (results.data ?? []).filter((e) => e.uri !== null)

  return (
    <Popover
      open={open}
      onOpenChange={(o) => { setOpen(o); if (!o) setQuery('') }}
      className="w-[360px] p-0"
      trigger={<Button icon="page">{t('Add Page')}</Button>}
    >
      <div className="border-b border-border p-2">
        <Fields bare fields={[search()]} values={{ query }} onChange={(_, v) => setQuery(v)} />
      </div>
      <div className="max-h-72 overflow-y-auto p-1">
        {results.isFetching && !results.data ? (
          <div className="grid place-items-center py-6 text-faint"><Spinner size={14} /></div>
        ) : routable.length === 0 ? (
          <p className="m-0 px-3 py-6 text-center text-sm text-faint">{debounced ? t('Nothing found') : t('No pages yet')}</p>
        ) : (
          <>
            {!debounced && <div className="cms-label px-2 pb-1 pt-1.5">{t('Recently Edited')}</div>}
            {routable.map((e) => (
              <button
                key={e.id}
                type="button"
                onClick={() => { onPick(e); setOpen(false); setQuery('') }}
                className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-hover"
              >
                <Icon name="page" size={14} className="shrink-0 text-muted" />
                <span className="min-w-0 flex-1 truncate">{e.title}</span>
                <span className="max-w-[45%] truncate text-xs text-faint">{e.uri}</span>
              </button>
            ))}
          </>
        )}
      </div>
    </Popover>
  )
}
