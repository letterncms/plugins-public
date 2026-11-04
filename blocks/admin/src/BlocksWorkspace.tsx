import { events, plural, t, useScreenContext, type EntryEditorProps } from '@letterncms/sdk'
import { Button, IconButton, InspectorPanel, Menu, Popover, QueryError, ScreenSidebar, Spinner, fieldControlId, useScreenSidebar, useWideInspector } from '@letterncms/ui'
import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { EditorProvider, useCatalog, useEditorContext, type DocRef } from './core/context'
import { Inserter, type InsertChoice } from './core/Inserter'
import { accepts, choiceToNodes, insertionPoint } from './core/insert'
import { selectionOf, useEditor } from './core/store'
import { useBlockSession } from './core/useBlockSession'
import * as tree from './core/tree'
import { Inspector } from './builder/Inspector'
import { Layers } from './builder/Layers'
import { bus } from './builder/bus'
import { focusFirst } from './write/doc'
import { outline as blockOutline, stops } from './write/outline'
import { LiveEditor } from './LiveEditor'

/** The same block document for pages, articles, templates and components. */
export function BlocksWorkspace(props: EntryEditorProps) {
  const { catalog, types, isLoading, error, retry } = useCatalog()
  const field = props.bodyField.handle
  const store = useBlockSession(props.values[field], (nodes) => props.setValue(field, nodes), !props.editable)
  const values = useRef(props.values)
  values.current = props.values
  const doc: DocRef = {
    kind: props.collection.handle === 'templates' ? 'template' : props.collection.handle === 'components' ? 'pattern' : 'entry',
    id: props.entry?.id ?? '',
    collections: props.values.collections,
    props: props.values.props,
  }
  // The inspector holds its place while the catalog loads: the canvas never changes width when it arrives.
  if (isLoading) return <div className="flex h-full min-h-0">
    <div className="grid min-w-0 flex-1 place-items-center"><Spinner /></div>
    <InspectorPanel open={props.sidebarOpen} onOpenChange={props.setSidebarOpen} title={props.collection.singular}>{props.sidebar}</InspectorPanel>
  </div>
  if (!catalog) return <QueryError message={error?.message} onRetry={retry} />
  return <EditorProvider value={{ store, catalog, types, entryId: props.entry?.id ?? null, fieldHandle: field, allowed: props.bodyField.allowed as string[] ?? [], getValues: () => values.current, title: props.title, doc, readOnly: !props.editable }}>
    {error && <QueryError message={error.message} onRetry={retry} compact />}
    <Workspace props={props} />
  </EditorProvider>
}


/** The screens a page is previewed and styled for: one vocabulary everywhere (Desktop, Tablet, Phone). */
export const screens = () => [
  { value: 'base', label: t('Desktop'), icon: 'b-monitor' },
  { value: 'tablet', label: t('Tablet'), icon: 'b-device-tablet' },
  { value: 'mobile', label: t('Phone'), icon: 'b-device-mobile' },
] as const

/**
 * Which screen the canvas shows and the Design tab styles, in the top bar: there with or
 * without a selected block. One button that shows the screen, its menu the others — the
 * bar's middle belongs to the title. A phone-sized window has no room for it: it edits the
 * phone layout, and the Design tab offers the way back to desktop.
 */
function Devices() {
  const { store } = useEditorContext()
  const breakpoint = useEditor(store, (state) => state.breakpoint)
  const all = screens()
  const current = all.find((item) => item.value === breakpoint) ?? all[0]
  return <Menu align="start" trigger={<IconButton icon={current.icon} label={`${t('Screen')}: ${current.label}`} active={breakpoint !== 'base'} className="max-sm:hidden" />} items={all.map((item) => ({ label: item.label, icon: item.icon, checked: item.value === breakpoint, onSelect: () => store.setBreakpoint(item.value) }))} />
}

function Workspace({ props }: { props: EntryEditorProps }) {
  const { store, types, allowed, readOnly } = useEditorContext()
  const selected = useEditor(store, (state) => state.selected ? tree.find(state.nodes, state.selected) : null)
  const blockMode = useEditor(store, (state) => state.blockMode)
  const count = useEditor(store, (state) => selectionOf(state).length)
  const undo = useEditor(store, () => store.canUndo())
  const redo = useEditor(store, () => store.canRedo())
  const [inspecting, setInspecting] = useState(false)
  const outline = useScreenSidebar()
  const [insert, setInsert] = useState(false)
  const [query, setQuery] = useState('')
  const wide = useWideInspector()
  const selection = useEditor(store, selectionOf)
  // A phone-sized window edits the page as phones show it: a desktop page would be too small to write in.
  useEffect(() => { if (window.matchMedia('(max-width: 639px)').matches) store.setBreakpoint('mobile') }, [store])
  useScreenContext({
    items: selection.flatMap((id) => {
      const node = tree.find(store.get().nodes, id)
      // Named by what it says: that's how someone tells which one it is.
      return node ? [{ id, kind: 'block', label: tree.summary(node, 40) || (types.get(node.type)?.title ?? t('Block')), icon: types.get(node.type)?.icon, read: () => tree.find(store.get().nodes, id) }] : []
    }),
  })
  const inspect = (id: string) => { store.select(id, true); setInspecting(true); props.setSidebarOpen(true) }
  useEffect(() => {
    if (selected && blockMode) { setInspecting(true); props.setSidebarOpen(true); if (!wide) outline.setOpen(false) }
    // Writing in a block makes it the active block too; it never opens a closed sidebar or a sheet over the text.
    else if (selected && wide && props.sidebarOpen) setInspecting(true)
  }, [selected?.id, blockMode, wide])
  useEffect(() => { if (!props.sidebarOpen) setInspecting(false) }, [props.sidebarOpen])
  useEffect(() => { if (Object.keys(props.errors).length) setInspecting(false) }, [props.errors])
  useEffect(() => bus.on('preview-motion', () => events.emit('entry.preview')), [])
  const entryDetails = () => { store.select(null); setInspecting(false) }
  const destination = (type: string | string[]) => {
    const current = store.get()
    const parent = current.selected ? tree.find(current.nodes, current.selected) : null
    if (parent && (Array.isArray(type) ? type : [type]).every((name) => accepts(parent, name, types))) return { parentId: parent.id, index: parent.children?.length ?? 0 }
    return current.selected ? insertionPoint(current.nodes, current.selected, type, types, allowed) : (Array.isArray(type) ? type : [type]).every((name) => accepts(null, name, types, allowed)) ? { parentId: null, index: current.nodes.length } : null
  }
  const choose = (choice: InsertChoice) => {
    const nodes = choiceToNodes(choice, types)
    if (!nodes.length) return
    const at = destination(nodes.map((node) => node.type))
    if (!at) return
    store.insert(at.parentId, at.index, nodes)
    focusFirst(store, nodes[0], stops(blockOutline(store.get().nodes, types, () => false)))
    setInsert(false); setQuery('')
  }
  return <div className="flex h-full min-h-0 flex-col" data-block-workspace>
    {props.toolbar && createPortal(<>
      {!readOnly && <Popover open={insert} onOpenChange={setInsert} align="start" trigger={<IconButton icon="plus" label={t('Insert block')} className="max-sm:hidden" />} className="!p-0">
        <Inserter types={[...types.values()]} query={query} onQuery={setQuery} onChoose={choose} onClose={() => setInsert(false)} allowed={(type) => !!destination(type.name)} />
      </Popover>}
      <IconButton icon="undo" label={t('Undo')} disabled={!undo} onClick={store.undo} className="max-sm:hidden" />
      <IconButton icon="redo" label={t('Redo')} disabled={!redo} onClick={store.redo} className="max-sm:hidden" />
      <Devices />
    </>, props.toolbar)}
    <ScreenSidebar title={t('Outline')} backTo={props.listPath} backLabel={props.listLabel ?? props.collection.title}><Layers /></ScreenSidebar>
    <div className="flex min-h-0 flex-1">
      <main id={fieldControlId(props.bodyField)} tabIndex={-1} aria-label={props.bodyField.label} aria-describedby={props.errors[props.bodyField.handle] ? `${fieldControlId(props.bodyField)}-error` : undefined} className="flex min-w-0 flex-1 flex-col outline-none" data-editor-document>
        <LiveEditor collection={props.collection.handle} entryId={props.entry?.id} content={props.content} onTitleChange={!['templates', 'components'].includes(props.collection.handle) ? props.setTitle : undefined} onInspect={inspect} />
        {props.errors[props.bodyField.handle] && <p id={`${fieldControlId(props.bodyField)}-error`} role="alert" className="m-0 shrink-0 px-3 py-2 text-xs text-danger">{props.errors[props.bodyField.handle]}</p>}
        <div className="max-h-[30vh] shrink-0 overflow-y-auto">{props.footer}</div>
      </main>
      <InspectorPanel open={props.sidebarOpen} onOpenChange={props.setSidebarOpen} title={inspecting && selected ? types.get(selected.type)?.title ?? t('Block') : props.collection.singular}>
        {inspecting && selected ? count > 1 ? <>
          <div className="flex h-12 shrink-0 items-center gap-1 border-b border-border pl-2 pr-3">
            <IconButton icon="arrow-left" label={t('Back to {name}', { name: props.collection.singular })} size="sm" onClick={entryDetails} />
            <span className="min-w-0 flex-1 truncate pl-1 text-sm font-semibold text-ink">{plural(count, '{count} block selected', '{count} blocks selected')}</span>
            <IconButton icon="close" label={t('Close Panel')} size="sm" onClick={() => props.setSidebarOpen(false)} />
          </div>
          {!readOnly && <div className="px-2 py-3"><Button variant="ghost" size="sm" icon="trash" className="text-danger" onClick={() => store.removeSelection()}>{t('Delete')}</Button></div>}
        </> : <Inspector node={selected} onBack={entryDetails} backLabel={props.collection.singular} onClose={() => props.setSidebarOpen(false)} /> : props.sidebar}
      </InspectorPanel>
    </div>
  </div>
}
