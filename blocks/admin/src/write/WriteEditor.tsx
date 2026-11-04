import { DndContext, DragOverlay, PointerSensor, closestCenter, pointerWithin, useDraggable, useDroppable, useSensor, useSensors, type CollisionDetection, type DragEndEvent, type DragMoveEvent, type DragStartEvent } from '@dnd-kit/core'
import { t, useAbility } from '@letterncms/sdk'
import { Icon, IconButton, InspectorPanel, Menu, Popover, cn, type MenuItemDef } from '@letterncms/ui'
import { createContext, useContext, useEffect, useLayoutEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { caretRect } from '../core/caret'
import { useEditorContext } from '../core/context'
import { FormatToolbar } from '../core/FormatToolbar'
import { accepts, choiceToNodes, insertionPoint } from '../core/insert'
import { Inserter, type InsertChoice } from '../core/Inserter'
import { selectionOf, useEditor, type EditorStore } from '../core/store'
import * as tree from '../core/tree'
import type { BlockNode, BlockTypeDef } from '../core/types'
import { AccordionBlock, Actions, BlockCard, CodeBlock, ComponentBlock, Divider, ImageBlock, MediaCard, TableBlock } from './Blocks'
import { WriteContext, focusFirst, focusStop, paragraph, useWrite, type WriteApi } from './doc'
import { allRows, outline, rowId, rowNodes, stops as stopsOf, type Row, type Section, type Stop } from './outline'
import { Inspector } from '../builder/Inspector'
import { openSlash, slashPlacement, useSlash, useSlashDismiss } from './slash'
import { ListBlock, PROSE, TextBlock } from './Text'


/** Plugins can provide document views for their own block types (e.g. form fields). */
export const blockViews = new Map<string, (props: { node: BlockNode; type?: BlockTypeDef; onSettings: (id: string) => void }) => ReactNode>()

const viewFor = (type: string) => blockViews.get(type) ?? blockViews.get(type.split('/')[0] + '/*')
const custom = (type: string) => !!viewFor(type)

interface Drop {
  id: string
  side: 'before' | 'after'
}

const DropContext = createContext<Drop | null>(null)

/** One editable flow over the complete block tree. */
export function WriteEditor({ onInspect }: { onInspect?: (id: string) => void } = {}) {
  const { store, types, allowed, readOnly = false } = useEditorContext()
  const nodes = useEditor(store, (s) => s.nodes)
  const sections = useMemo(() => outline(nodes, types, custom), [nodes, types])
  const rows = useMemo(() => new Map(allRows(sections).map((r) => [rowId(r), r])), [sections])
  const root = useRef<HTMLDivElement>(null)
  const [settings, setSettings] = useState<string | null>(null)
  const [drop, setDrop] = useState<Drop | null>(null)
  const [dragging, setDragging] = useState<Row | null>(null)
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }))

  // Caret stops for the latest tree, derived on demand (key handlers run between renders).
  const cache = useRef<{ nodes: BlockNode[]; stops: Stop[] } | null>(null)
  const api = useMemo<WriteApi>(
    () => ({
      stops: () => {
        const current = store.get().nodes
        if (cache.current?.nodes !== current) cache.current = { nodes: current, stops: stopsOf(outline(current, types, custom)) }
        return cache.current.stops
      },
      settings: (id) => { store.select(id, true); onInspect ? onInspect(id) : setSettings(id) },
      readOnly,
    }),
    [store, types, readOnly, onInspect],
  )

  // An empty document starts with one empty paragraph to type in. It is not a change yet:
  // the entry stays as it was until something is written.
  useLayoutEffect(() => {
    if (nodes.length === 0 && !readOnly && accepts(null, 'paragraph', types, allowed)) store.reset([paragraph()])
  }, [nodes, readOnly, store, types, allowed])

  const rowsRef = useRef(rows)
  rowsRef.current = rows
  useBlockKeys(store, root, api, rowsRef)

  /** Where `row` would land dropped beside `over`, if its container takes it. */
  const target = (row: Row, over: Row, side: Drop['side']) => {
    const doc = store.get().nodes
    const anchor = side === 'before' ? rowNodes(over)[0] : rowNodes(over).at(-1)!
    const moving = rowNodes(row)
    if (moving.some((n) => n.id === anchor.id || tree.ancestors(doc, anchor.id).some((a) => a.id === n.id))) return null
    const loc = tree.locate(doc, anchor.id)
    if (!loc) return null
    const parent = loc.parentId ? tree.find(doc, loc.parentId) : null
    if (!moving.every((n) => accepts(parent, n.type, types, parent ? [] : allowed))) return null
    return { ids: moving.map((n) => n.id), parentId: loc.parentId, index: side === 'before' ? loc.index : loc.index + 1 }
  }

  const onDragMove = (e: DragMoveEvent) => {
    const row = rows.get(String(e.active.id))
    const over = e.over ? rows.get(String(e.over.id)) : null
    if (!row || !over || e.over!.id === e.active.id) return drop && setDrop(null)
    const y = (e.activatorEvent as PointerEvent).clientY + e.delta.y
    const side = y < e.over!.rect.top + e.over!.rect.height / 2 ? 'before' : 'after'
    const next = target(row, over, side) ? { id: String(e.over!.id), side } : null
    if (next?.id !== drop?.id || next?.side !== drop?.side) setDrop(next as Drop | null)
  }

  const onDragEnd = (e: DragEndEvent) => {
    const row = rows.get(String(e.active.id))
    const over = drop ? rows.get(drop.id) : null
    setDrop(null)
    setDragging(null)
    const to = row && over && drop ? target(row, over, drop.side) : null
    if (to) store.moveTo(to.ids, to.parentId, to.index)
  }

  /** Clicking below the text continues it: the last empty paragraph, or a new one at the end of the flow. */
  const continueWriting = () => {
    const all = api.stops()
    const last = all.at(-1)
    const lastNode = last ? tree.find(store.get().nodes, last.id) : null
    if (lastNode?.type === 'paragraph' && tree.isEmptyHtml(lastNode.props.text)) return focusStop(store, last!, 'end')
    const doc = store.get().nodes
    const tail = sections.at(-1)
    const after = tail?.container ? rowNodes(tail.rows.at(-1)!).at(-1)!.id : doc.at(-1)?.id
    const loc = after ? insertionPoint(doc, after, 'paragraph', types, allowed) : { parentId: null, index: 0 }
    if (!loc) return
    const p = paragraph()
    store.insert(loc.parentId, loc.index, [p])
    store.focus(p.id, 'start')
  }

  return (
    <WriteContext.Provider value={api}>
      <div ref={root} className="relative" data-cms-write>
        <DndContext
          sensors={sensors}
          collisionDetection={innermost}
          onDragStart={(e: DragStartEvent) => setDragging(rows.get(String(e.active.id)) ?? null)}
          onDragMove={onDragMove}
          onDragEnd={onDragEnd}
          onDragCancel={() => {
            setDrop(null)
            setDragging(null)
          }}
        >
          <DropContext.Provider value={drop}>
            {sections.map((section, i) => <SectionView key={section.id} section={section} spaced={i > 0 && (section.container || sections[i - 1].container)} />)}
          </DropContext.Provider>
          {createPortal(<DragOverlay dropAnimation={null}>{dragging && <DragGhost row={dragging} />}</DragOverlay>, document.body)}
        </DndContext>
        {!readOnly && <button type="button" tabIndex={-1} aria-label={t('Continue Writing')} onClick={continueWriting} className="block h-28 w-full cursor-text" />}
        <FormatToolbar />
        <SlashMenu />
        {settings && !onInspect && <InspectorPanel open onOpenChange={(open) => !open && setSettings(null)} title={t('Block')}>{tree.find(nodes, settings) && <Inspector node={tree.find(nodes, settings)!} onClose={() => setSettings(null)} />}</InspectorPanel>}
      </div>
    </WriteContext.Provider>
  )
}

/** The deepest row under the pointer (a paragraph inside a column, not the columns), else the nearest. */
const innermost: CollisionDetection = (args) => {
  const inside = pointerWithin(args)
  if (inside.length === 0) return closestCenter(args)
  const area = (id: string | number) => {
    const r = args.droppableRects.get(id)
    return r ? r.width * r.height : Infinity
  }
  return [...inside].sort((a, b) => area(a.id) - area(b.id)).slice(0, 1)
}

function SectionView({ section, spaced }: { section: Section; spaced: boolean }) {
  return (
    <section className={cn('group/section relative', spaced && 'mt-10')}>
      {section.label && (
        <div className="pointer-events-none absolute right-[calc(100%+76px)] top-[5px] max-w-40 truncate whitespace-nowrap text-2xs text-faint opacity-0 transition-opacity duration-150 group-focus-within/section:opacity-100 group-hover/section:opacity-100 max-xl:hidden">
          {section.label}
        </div>
      )}
      {section.rows.map((row, i) => (
        <RowView key={rowId(row)} row={row} first={i === 0} />
      ))}
    </section>
  )
}

/** Space above a row and the height of its first line (where the gutter sits). */
function rhythm(row: Row, first: boolean): { className: string; line: number } {
  if (row.kind === 'text' && row.node.type === 'heading') {
    const level = String(row.node.props.level ?? 2)
    const line = { '1': 38, '2': 32, '3': 28 }[level] ?? 26
    return { className: first ? '' : level === '1' ? 'mt-8' : level === '2' ? 'mt-7' : 'mt-5', line }
  }
  if (row.kind === 'actions') return { className: first ? '' : 'mt-2', line: 44 }
  if (row.kind === 'text' || row.kind === 'list') return { className: first ? '' : 'mt-1', line: 27 }
  return { className: first ? '' : 'mt-2', line: 30 }
}

function RowView({ row, first, nested }: { row: Row; first: boolean; nested?: boolean }) {
  const { store } = useEditorContext()
  const { readOnly } = useWrite()
  const id = rowId(row)
  const ids = rowNodes(row).map((n) => n.id)
  const picked = useEditor(store, (s) => s.blockMode && selectionOf(s).some((x) => ids.includes(x)))
  const drop = useContext(DropContext)
  const droppable = useDroppable({ id })
  const draggable = useDraggable({ id, disabled: readOnly })
  const { className, line } = rhythm(row, first)

  return (
    <div
      ref={(el) => {
        droppable.setNodeRef(el)
        draggable.setNodeRef(el)
      }}
      data-row={id}
      data-nested={nested || undefined}
      style={{ '--line': `${line}px` } as CSSProperties}
      className={cn(nested ? 'group/nested' : 'group/row', 'relative py-[3px]', className, draggable.isDragging && 'opacity-35', row.hidden && 'opacity-45')}
      onMouseDown={(e) => {
        const el = e.target as HTMLElement
        if (el.closest('[data-row]') !== e.currentTarget || el.closest('[contenteditable="true"],textarea,input,button,a,[role="button"]')) return
        if (e.shiftKey && store.get().selected) {
          e.preventDefault()
          if (!store.get().blockMode) store.select(store.get().selected, true)
          store.extendTo(ids.at(-1)!)
        } else {
          store.select(ids[0], true)
          if (ids.length > 1) store.extendTo(ids.at(-1)!)
        }
      }}
    >
      {drop?.id === id && <div className={cn('pointer-events-none absolute inset-x-0 z-10 h-0.5 rounded-full bg-focus', drop.side === 'before' ? '-top-px' : '-bottom-px')} />}
      {!readOnly && <Gutter row={row} handle={draggable} nested={nested} />}
      <div className={cn('rounded-sm transition-[background-color,box-shadow] duration-100', picked && 'bg-focus/10 shadow-[0_0_0_3px_color-mix(in_srgb,var(--cms-focus)_10%,transparent)]')}>
        <RowContent row={row} />
      </div>
    </div>
  )
}

function RowContent({ row }: { row: Row }) {
  const { types } = useEditorContext()
  const { settings } = useWrite()
  if (row.kind === 'text') return <TextBlock node={row.node} />
  if (row.kind === 'list') return <ListBlock node={row.node} />
  if (row.kind === 'actions') return <Actions nodes={row.nodes} />
  if (row.kind === 'container') {
    const type = types.get(row.node.type)
    return <div className="rounded-md border border-border/70 px-4 pb-3 pt-2">
      <button type="button" onClick={() => settings(row.node.id)} className="mb-2 flex max-w-full items-center gap-1.5 text-xs text-faint hover:text-text"><Icon name={type?.icon ?? 'square'} size={12} /><span className="truncate">{row.node.label || type?.title || row.node.type}</span></button>
      {row.rows.length ? row.rows.map((child, i) => <RowView key={rowId(child)} row={child} first={i === 0} nested />) : <EmptyColumn column={row.node} />}
    </div>
  }
  if (row.kind === 'columns') {
    const template = String(row.node.props.layout ?? '1-1').split('-').map((n) => `minmax(0,${n}fr)`).join(' ')
    return (
      <div className="grid gap-8 py-1 max-md:!grid-cols-1" style={{ gridTemplateColumns: template }}>
        {row.columns.map((column) => (
          <div key={column.node.id} className="min-w-0">
            {column.rows.length ? column.rows.map((r, i) => <RowView key={rowId(r)} row={r} first={i === 0} nested />) : <EmptyColumn column={column.node} />}
          </div>
        ))}
      </div>
    )
  }
  const node = row.node
  const type = types.get(node.type)
  const View = viewFor(node.type)
  if (View) return <View node={node} type={type} onSettings={settings} />
  switch (node.type) {
    case 'image':
      return <ImageBlock node={node} />
    case 'video':
    case 'embed':
      return <MediaCard node={node} type={type} />
    case 'code':
      return <CodeBlock node={node} />
    case 'table':
      return <TableBlock node={node} />
    case 'divider':
      return <Divider />
    case 'accordion':
      return <AccordionBlock node={node} />
    case 'pattern':
      return <ComponentBlock node={node} />
    default:
      return <BlockCard node={node} type={type} />
  }
}

/** A column with nothing in it yet: a line to type on. */
function EmptyColumn({ column }: { column: BlockNode }) {
  const { store, types } = useEditorContext()
  const { readOnly } = useWrite()
  if (readOnly || !accepts(column, 'paragraph', types, [])) return null
  return (
    <button
      type="button"
      onClick={() => {
        const p = paragraph()
        store.insert(column.id, 0, [p])
        store.focus(p.id, 'start')
      }}
      className={cn(PROSE, 'block w-full cursor-text py-[3px] text-left text-faint opacity-0 hover:opacity-100')}
    >
      {t('Write here')}
    </button>
  )
}

/** Hover gutter: add a block below, and the handle that drags the row or opens its menu. */
function Gutter({ row, handle, nested }: { row: Row; handle: ReturnType<typeof useDraggable>; nested?: boolean }) {
  const { store, types, allowed } = useEditorContext()
  const api = useWrite()
  const [inserting, setInserting] = useState(false)
  const [query, setQuery] = useState('')
  const picked = useEditor(store, (s) => s.blockMode && rowNodes(row).some((n) => n.id === s.selected))
  const last = rowNodes(row).at(-1)!

  const insert = (choice: InsertChoice) => {
    const created = choiceToNodes(choice, types)
    const loc = insertionPoint(store.get().nodes, last.id, created.map((node) => node.type), types, allowed)
    setInserting(false)
    setQuery('')
    if (!loc) return
    store.insert(loc.parentId, loc.index, created)
    focusFirst(store, created[0], api.stops())
  }

  return (
    <div
      className={cn(
        'absolute top-[3px] z-10 flex h-[var(--line)] items-center gap-0.5 opacity-0 transition-opacity duration-100 focus-within:opacity-100',
        // In a column only the handle, in the gap beside it; the columns' own gutter steps back meanwhile.
        nested ? '-left-[22px] group-hover/nested:opacity-100' : '-left-[54px] group-hover/row:opacity-100 group-has-[[data-nested]:hover]/row:!opacity-0',
        (inserting || picked) && '!opacity-100',
      )}
    >
      {!nested && (
      <Popover
        open={inserting}
        onOpenChange={(o) => {
          setInserting(o)
          if (!o) setQuery('')
        }}
        align="start"
        className="!p-0"
        trigger={<IconButton icon="plus" label={t('Add Below')} size="xs" className="!text-faint hover:!text-text" />}
      >
        <Inserter types={[...types.values()]} query={query} onQuery={setQuery} onChoose={insert} onClose={() => setInserting(false)} allowed={(type) => !!insertionPoint(store.get().nodes, last.id, type.name, types, allowed)} />
      </Popover>
      )}
      <Menu
        align="start"
        trigger={
          <button
            type="button"
            ref={handle.setActivatorNodeRef}
            {...handle.attributes}
            {...handle.listeners}
            aria-label={t('Drag to move, click for options')}
            className="grid h-6 w-[18px] cursor-grab place-items-center rounded-xs text-faint hover:bg-hover hover:text-text active:cursor-grabbing"
          >
            <Icon name="drag" size={14} />
          </button>
        }
        items={rowMenu(store, row, types, allowed, api)}
      />
    </div>
  )
}

const TURN_INTO: { label: () => string; icon: string; type: string; props: Record<string, any> }[] = [
  { label: () => t('Text'), icon: 'paragraph', type: 'paragraph', props: {} },
  { label: () => t('Heading 1'), icon: 'heading', type: 'heading', props: { level: '1' } },
  { label: () => t('Heading 2'), icon: 'heading', type: 'heading', props: { level: '2' } },
  { label: () => t('Heading 3'), icon: 'heading', type: 'heading', props: { level: '3' } },
  { label: () => t('Bulleted List'), icon: 'list', type: 'list', props: { ordered: false } },
  { label: () => t('Numbered List'), icon: 'list-ordered', type: 'list', props: { ordered: true } },
  { label: () => t('Quote'), icon: 'quote', type: 'quote', props: {} },
  { label: () => t('Callout'), icon: 'info', type: 'callout', props: { tone: 'note' } },
]

function rowMenu(store: EditorStore, row: Row, types: Map<string, BlockTypeDef>, allowed: string[], api: WriteApi): (MenuItemDef | false)[] {
  const nodes = rowNodes(row)
  const node = nodes[0]
  const text = row.kind === 'text' || row.kind === 'list'
  const plain = (): string => (node.type === 'list' ? (node.props.items ?? []).join('<br>') : String(node.props.text ?? ''))
  const isCurrent = (o: (typeof TURN_INTO)[number]) => o.type === node.type && Object.entries(o.props).every(([k, v]) => String(node.props[k] ?? (k === 'ordered' ? false : '')) === String(v))
  const turnInto = (o: (typeof TURN_INTO)[number]) => () => {
    const props = o.type === 'list' ? { ...o.props, items: plain().split(/<br\s*\/?>/) } : { ...o.props, text: plain(), ...(o.type === 'quote' ? { cite: '' } : {}) }
    store.updateNode(node.id, { type: o.type, props })
    store.focus(node.id, 'end', o.type === 'list' ? 'items' : 'text', o.type === 'list' ? 0 : undefined)
  }
  const settings = nodes.length === 1
  const parent = row.parent ? tree.find(store.get().nodes, row.parent) : null

  return [
    ...(text ? [{ label: t('Turn Into'), heading: true }, ...TURN_INTO.filter((o) => types.has(o.type) && accepts(parent, o.type, types, parent ? [] : allowed)).map((o) => ({ label: o.label(), icon: o.icon, checked: isCurrent(o), onSelect: turnInto(o) })), { separator: true, label: '' }] : []),
    settings && { label: t('Settings'), icon: 'settings', onSelect: () => api.settings(node.id) },
    { label: t('Duplicate'), icon: 'copy', shortcut: 'mod+d', onSelect: () => duplicate(store, nodes.map((n) => n.id)) },
    { separator: true, label: '' },
    { label: t('Delete'), icon: 'trash', danger: true, shortcut: '⌫', onSelect: () => store.commit(nodes.reduce((doc, n) => tree.remove(doc, n.id), store.get().nodes)) },
  ]
}

/** Copies of sibling blocks, right after the last of them. */
function duplicate(store: EditorStore, ids: string[]) {
  const doc = store.get().nodes
  const copies = ids.map((id) => tree.find(doc, id)).filter((n): n is BlockNode => !!n).map(tree.clone)
  const loc = tree.locate(doc, ids.at(-1)!)
  if (!loc || copies.length === 0) return
  store.insert(loc.parentId, loc.index + 1, copies)
  store.select(copies[0].id, true)
  if (copies.length > 1) store.extendTo(copies.at(-1)!.id)
}

function DragGhost({ row }: { row: Row }) {
  const text = rowNodes(row).map((n) => tree.summary(n, 60)).filter(Boolean).join(' · ')
  return <div className="cms-pop max-w-[360px] truncate px-3 py-1.5 text-sm text-muted">{text || t('Block')}</div>
}

/** Type "/" on an empty line to insert a block in its place. */
function SlashMenu() {
  const { store, types, allowed } = useEditorContext()
  const api = useWrite()
  const slash = useSlash()
  const [rect, setRect] = useState<DOMRect | null>(null)
  // Components load with the editor, so they're there the moment someone types "/".
  useAbility('patterns/list', {}, { staleTime: 60_000, enabled: allowed.length === 0 || allowed.includes('pattern') })

  useEffect(() => {
    if (!slash) return setRect(null)
    const frame = requestAnimationFrame(() => {
      const el = document.querySelector(`[data-row="${slash.id}"] [contenteditable]`)
      setRect(caretRect() ?? el?.getBoundingClientRect() ?? null)
    })
    return () => cancelAnimationFrame(frame)
  }, [slash])

  // Leaving the line closes the menu.
  useEffect(() => {
    if (!slash) return
    const onFocus = () => !(document.activeElement as HTMLElement | null)?.closest(`[data-row="${slash.id}"]`) && openSlash(null)
    document.addEventListener('focusin', onFocus)
    return () => document.removeEventListener('focusin', onFocus)
  }, [slash])
  useSlashDismiss(!!slash, [document])

  if (!slash || !rect) return null

  const choose = (choice: InsertChoice) => {
    const created = choiceToNodes(choice, types)
    const doc = store.get().nodes
    const loc = tree.locate(doc, slash.id)
    openSlash(null)
    if (!loc) return
    const parent = loc.parentId ? tree.find(doc, loc.parentId) : null
    if (created.every((node) => accepts(parent, node.type, types, parent ? [] : allowed))) {
      store.commit(tree.insert(tree.remove(doc, slash.id), loc.parentId, loc.index, created))
    } else {
      // Not allowed in this container (a table inside a button row): it goes after the container instead.
      const at = insertionPoint(doc, slash.id, created.map((node) => node.type), types, allowed)
      if (!at) return
      store.commit(tree.insert(tree.remove(doc, slash.id), at.parentId, at.index, created))
    }
    focusFirst(store, created[0], api.stops())
  }

  const { maxHeight, ...place } = slashPlacement(rect)
  return createPortal(
    <div data-cms-slash className="cms-pop fixed z-[60] overflow-hidden !p-0" style={place}>
      <Inserter
        types={[...types.values()]}
        query={slash.query}
        chooseFirst={slash.enter}
        patterns={allowed.length === 0 || allowed.includes('pattern')}
        onNoMatch={() => {
          const el = document.querySelector<HTMLElement>(`[data-row="${slash.id}"] [contenteditable]`)
          openSlash(null)
          el?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))
        }}
        showSearch={false}
        compact
        style={{ maxHeight }}
        onChoose={choose}
        onClose={() => openSlash(null)}
        allowed={(type) => !!insertionPoint(store.get().nodes, slash.id, type.name, types, allowed)}
      />
    </div>,
    document.body,
  )
}

/**
 * Keys for selected blocks (Esc selects the block you are typing in): arrows
 * move through the document, ⌥↑↓ move the block, ⇧↑↓ select a run, ⌫ deletes,
 * ⌘D duplicates, ⌘C/⌘X/⌘V copy blocks; ⌘Z undoes anywhere in the document.
 */
function useBlockKeys(store: EditorStore, root: React.RefObject<HTMLDivElement | null>, api: WriteApi, rows: React.RefObject<Map<string, Row>>) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.defaultPrevented) return
      const within = root.current?.contains(document.activeElement) || root.current?.contains(e.target as Node)
      const { selected, blockMode, nodes } = store.get()
      const mod = e.metaKey || e.ctrlKey

      // Undo belongs to the document while you are in it, have blocks selected, or nothing else has focus (after a drag).
      const idle = document.activeElement === document.body || !document.activeElement
      if (mod && e.key.toLowerCase() === 'z' && (within || blockMode || idle)) {
        e.preventDefault()
        const typing = within && (document.activeElement as HTMLElement | null)?.isContentEditable
        ;(document.activeElement as HTMLElement | null)?.blur()
        e.shiftKey ? store.redo() : store.undo()
        // Keep writing where you were, if that text survived.
        const stop = typing && selected ? api.stops().find((s) => s.id === selected) : null
        if (stop) focusStop(store, stop, 'end')
        return
      }

      if (!blockMode || !selected || api.readOnly) return
      if ((e.target as HTMLElement)?.closest?.('input,textarea,[contenteditable="true"],[role="dialog"],[data-cms-settings]')) return

      const list = [...rows.current.values()]
      const at = list.findIndex((r) => rowNodes(r).some((n) => n.id === selected))
      const row = list[at]
      const pick = (r: Row | undefined) => {
        if (!r) return
        const ids = rowNodes(r).map((n) => n.id)
        store.select(ids[0], true)
        if (ids.length > 1) store.extendTo(ids.at(-1)!)
        document.querySelector(`[data-row="${ids[0]}"]`)?.scrollIntoView({ block: 'nearest' })
      }

      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault()
        const up = e.key === 'ArrowUp'
        if (e.altKey) return store.nudge(selected, up ? -1 : 1)
        if (e.shiftKey) return store.extend(up ? -1 : 1)
        pick(list[at + (up ? -1 : 1)])
      } else if (e.key === 'Enter') {
        e.preventDefault()
        const stop = api.stops().find((s) => s.id === selected)
        const node = row ? rowNodes(row)[0] : null
        if (stop) focusStop(store, stop, 'end')
        else if (node) api.settings(node.id)
      } else if (e.key === 'Backspace' || e.key === 'Delete') {
        e.preventDefault()
        const fallback = list[at - 1] ?? list.slice(at + 1).find((r) => !rowNodes(r).some((n) => store.selection().includes(n.id)))
        store.removeSelection()
        pick(fallback)
      } else if (mod && e.key.toLowerCase() === 'd') {
        e.preventDefault()
        duplicate(store, store.selection())
      } else if (mod && (e.key.toLowerCase() === 'c' || e.key.toLowerCase() === 'x')) {
        const picked = store.selection().map((id) => tree.find(nodes, id)).filter(Boolean)
        if (picked.length === 0) return
        e.preventDefault()
        void navigator.clipboard.writeText('cms-blocks:' + JSON.stringify(picked))
        if (e.key.toLowerCase() === 'x') store.removeSelection()
      } else if (mod && e.key.toLowerCase() === 'v') {
        e.preventDefault()
        void navigator.clipboard.readText().then((text) => {
          if (!text.startsWith('cms-blocks:')) return
          const pasted = (JSON.parse(text.slice(12)) as BlockNode[]).map(tree.clone)
          store.insertAfter(store.selection().at(-1) ?? selected, pasted)
          store.select(pasted[0].id, true)
        })
      } else if (e.key === 'Escape') {
        store.select(null)
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [store, root, api, rows])
}

