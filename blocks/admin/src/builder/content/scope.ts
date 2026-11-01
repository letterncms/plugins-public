import { t, useAbility, useManifest } from '@letterncms/sdk'
import { useEditorContext } from '../../core/context'
import { useEditor } from '../../core/store'
import * as tree from '../../core/tree'
import type { BlockNode } from '../../core/types'

export interface DataPath {
  path: string
  label: string
  group: string
}

const ENTRY = (): DataPath[] => [
  { path: 'entry.author.name', label: t('Author'), group: t('Page') },
  { path: 'entry.title', label: t('Title'), group: t('Page') },
  { path: 'entry.url', label: t('Link'), group: t('Page') },
  { path: 'entry.date|date:F j, Y', label: t('Published Date'), group: t('Page') },
  { path: 'entry.updated|date:F j, Y', label: t('Updated Date'), group: t('Page') },
  { path: 'entry.parent.title', label: t('Parent Title'), group: t('Page') },
  { path: 'entry.prev.url', label: t('Previous Page Link'), group: t('Page') },
  { path: 'entry.prev.title', label: t('Previous Page Title'), group: t('Page') },
  { path: 'entry.next.url', label: t('Next Page Link'), group: t('Page') },
  { path: 'entry.next.title', label: t('Next Page Title'), group: t('Page') },
]

const SITE = (): DataPath[] => [
  { path: 'site.name', label: t('Site Name'), group: t('Site') },
  { path: 'site.description', label: t('Site Description'), group: t('Site') },
  { path: 'site.url', label: t('Site URL'), group: t('Site') },
  { path: '© {site.year} {site.name}', label: t('Copyright Line'), group: t('Site') },
  { path: 'page.uri', label: t('Current Path'), group: t('Site') },
  { path: 'pagination.prev', label: t('Previous Page of a List'), group: t('Pagination') },
  { path: 'pagination.next', label: t('Next Page of a List'), group: t('Pagination') },
  { path: '{pagination.current} / {pagination.total}', label: t('Page x of y'), group: t('Pagination') },
]

/** Everything a block can bind to here: page fields, repeat items, component properties, site and globals. */
export function useDataPaths(node: BlockNode): DataPath[] {
  const { store, doc, catalog } = useEditorContext()
  const manifest = useManifest()
  const ancestors = useEditor(store, (s) => tree.ancestors(s.nodes, node.id))
  const entry = useAbility<{ collection: string }>('entries/get', { id: doc?.id ?? '' }, { enabled: doc?.kind === 'entry', staleTime: 60_000 })
  const handles = doc?.kind === 'template' ? doc.collections?.length ? doc.collections : manifest.collections.filter((c) => c.routable).map((c) => c.handle).slice(0, 1) : entry.data ? [entry.data.collection] : []
  const collection = useAbility<{ fields: { handle: string; label: string; type: string }[] }>('collections/get', { handle: handles[0] ?? '' }, { enabled: !!handles[0], staleTime: 300_000 })

  const out: DataPath[] = []

  // Repeat items, innermost first.
  for (const a of [...ancestors].reverse()) {
    if (a.type !== 'repeat') continue
    const as = (a.props?.as as string) || 'item'
    const source = catalog.sources?.find((s) => s.name === a.props?.source)
    const group = t('{list} item ({name})', { list: source?.label ?? t('List'), name: as })
    for (const [key, label] of Object.entries(source?.item ?? {})) out.push({ path: `${as}.${key}`, label, group })
    if (a.props?.source === 'entries' && a.props?.collection) out.push({ path: `${as}.excerpt`, label: t('Excerpt Field'), group }, { path: `${as}.cover`, label: t('Cover Image Field'), group })
    if (a.props?.source === 'list') out.push({ path: `{${as}}`, label: t('The Item Itself (Text)'), group })
    out.push({ path: 'index', label: t('Position (1, 2, 3…)'), group }, { path: 'first', label: t('Is First'), group }, { path: 'last', label: t('Is Last'), group })
  }

  if (doc?.kind === 'pattern') for (const p of doc.props ?? []) out.push({ path: `props.${p.handle}`, label: p.label ?? p.handle, group: t('Component Properties') })

  out.push(...ENTRY())
  for (const f of collection.data?.fields ?? []) {
    if (f.type === 'blocks') continue
    out.push({ path: `entry.${f.handle}${f.type === 'date' ? '|date:F j, Y' : ''}`, label: f.label, group: t('Page Fields') })
  }

  out.push(...SITE())
  for (const g of manifest.globals) out.push({ path: `globals.${g.handle}.`, label: t('{name} (add a field handle)', { name: g.title }), group: t('Globals') })
  return out
}
