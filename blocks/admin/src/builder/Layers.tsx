import { DndContext, PointerSensor, closestCenter, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core'
import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { t } from '@letterncms/sdk'
import { Icon, Tooltip, cn } from '@letterncms/ui'
import { useEffect, useMemo, useState } from 'react'
import { blockName, useComponentTitles, useEditorContext } from '../core/context'
import { selectionOf, useEditor } from '../core/store'
import * as tree from '../core/tree'
import type { BlockNode } from '../core/types'
import { accepts } from '../core/insert'
import { bus } from './bus'
import { PanelFields } from './controls'

/** Layer tree: select, hover, collapse, hide, rename (double-click) and drag to reorder or re-parent. */
export function Layers() {
  const { store, types, allowed, readOnly } = useEditorContext()
  const nodes = useEditor(store, (s) => s.nodes)
  const selected = useEditor(store, (s) => s.selected)
  const range = useEditor(store, selectionOf)
  const hovered = useEditor(store, (s) => s.hovered)
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({})
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }))

  // Reveal the selected layer.
  useEffect(() => {
    if (!selected) return
    const open = tree.ancestors(store.get().nodes, selected).filter((a) => collapsed[a.id])
    if (open.length) setCollapsed((c) => ({ ...c, ...Object.fromEntries(open.map((a) => [a.id, false])) }))
    document.querySelector(`[data-layer="${selected}"]`)?.scrollIntoView({ block: 'nearest' })
  }, [selected])

  const held = useMemo(() => hints(nodes, (node) => !!types.get(node.type)?.children || !!node.children?.length), [nodes, types])
  const visible = tree.flatten(nodes).filter(({ node }) => !tree.ancestors(nodes, node.id).some((a) => collapsed[a.id]))

  const onDragEnd = (e: DragEndEvent) => {
    if (readOnly || !e.over || e.active.id === e.over.id) return
    const doc = store.get().nodes
    const active = tree.find(doc, String(e.active.id))
    const over = tree.find(doc, String(e.over.id))
    const target = tree.locate(doc, String(e.over.id))
    const from = tree.locate(doc, String(e.active.id))
    if (!active || !over || !target || !from) return

    // Dropping onto an expanded, empty-or-open container nests inside it.
    const overType = types.get(over.type)
    if (overType?.children && !collapsed[over.id] && accepts(over, active.type, types) && (over.children?.length ?? 0) === 0) {
      return store.move(active.id, over.id, 0)
    }

    const parent = target.parentId ? tree.find(doc, target.parentId) : null
    if (!accepts(parent, active.type, types, parent ? [] : allowed)) return
    const index = from.parentId === target.parentId && from.index < target.index ? target.index + 1 : target.index
    store.move(active.id, target.parentId, index)
  }

  if (nodes.length === 0) {
    return (
      <div className="px-4 py-3">
        <p className="m-0 text-sm text-muted">{t('No elements yet')}</p>
      </div>
    )
  }

  return (
    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
      <SortableContext items={visible.map((v) => v.node.id)} strategy={verticalListSortingStrategy}>
        <div className="space-y-px px-2 pb-3 pt-1" onMouseLeave={() => store.hover(null)}>
          {visible.map(({ node, depth }) => (
            <LayerRow
              key={node.id}
              node={node}
              depth={depth}
              selected={range.includes(node.id)}
              hovered={hovered === node.id}
              collapsed={!!collapsed[node.id]}
              hint={held.get(node.id)}
              onToggle={() => setCollapsed((c) => ({ ...c, [node.id]: !c[node.id] }))}
            />
          ))}
        </div>
      </SortableContext>
    </DndContext>
  )
}

/** What a box is, by its element or its layout: a section, a row, a grid. */
function kindOf(node: BlockNode, title: string): string {
  if (node.type !== 'box') return title
  const tags: Record<string, string> = { section: t('Section'), header: t('Header'), footer: t('Footer'), nav: t('Navigation'), main: t('Main Content'), aside: t('Aside'), article: t('Article'), ul: t('List'), ol: t('List'), li: t('List Item'), a: t('Link'), button: t('Button'), form: t('Form'), figure: t('Figure') }
  const tag = String(node.props?.tag ?? '')
  if (tags[tag]) return tags[tag]
  const base = (node.style?.base ?? {}) as Record<string, unknown>
  if (base.display === 'grid') return t('Grid')
  if (base.display === 'flex' || base.display === 'inline-flex') return /column/.test(String(base.flexDirection ?? '')) ? t('Stack') : t('Row')
  return title
}

/** What a block says: its text, or for text that comes from data, the field it shows (Title, Date…). */
function says(node: BlockNode, max: number): string {
  const text = tree.summary(node, max)
  if (text) return text
  const path = String(node.bind?.text ?? '').replace(/[{}]/g, '').split('|')[0].trim()
  const field = path.split('.').pop() ?? ''
  return field ? field.replace(/[_-]+/g, ' ').replace(/^\w/, (c) => c.toUpperCase()) : ''
}

/**
 * What each container holds, to tell it from its siblings — one rule for every container: the
 * first heading among its own children, else the first things its own children say. A wrapper
 * says nothing: what is inside the container it wraps names that one.
 */
function hints(nodes: BlockNode[], containers: (node: BlockNode) => boolean): Map<string, string> {
  const out = new Map<string, string>()
  for (const { node } of tree.flatten(nodes)) {
    if (!containers(node) || node.label || node.type === 'pattern') continue
    const children = node.children ?? []
    const heading = children.find((child) => child.type === 'heading' && says(child, 48))
    if (heading) {
      out.set(node.id, says(heading, 48))
      continue
    }
    const said = children.filter((child) => !containers(child)).map((child) => says(child, 32)).filter(Boolean).slice(0, 2)
    if (said.length) out.set(node.id, said.join(' · '))
  }
  return out
}

function LayerRow({ node, depth, selected, hovered, collapsed, hint, onToggle }: { node: BlockNode; depth: number; selected: boolean; hovered: boolean; collapsed: boolean; hint?: string; onToggle: () => void }) {
  const { store, types, readOnly } = useEditorContext()
  const type = types.get(node.type)
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: node.id, disabled: readOnly })
  const hasChildren = (node.children?.length ?? 0) > 0
  const components = useComponentTitles()
  const container = !!type?.children || hasChildren
  // A layer is named by what it is or says: its own name, the component, its text, or for a container, its kind and what it holds.
  const label = node.type === 'pattern' ? blockName(node, types, components) : node.label || (container ? kindOf(node, type?.title ?? node.type) : says(node, 48) || type?.title || node.type)
  const plain = !node.label && node.type !== 'pattern' && !container && !says(node, 48)
  const [renaming, setRenaming] = useState(false)
  const component = node.type === 'pattern'
  const badges = [
    node.motion?.length && { icon: 'b-magic-wand', title: t('Animated') },
    node.logic && { icon: 'b-lightning', title: t('Interactive') },
    node.bind && Object.keys(node.bind).some((k) => k !== 'visible' && k !== 'hidden') && { icon: 'b-database', title: t('Connected to Data') },
    (node.logic?.show || node.bind?.visible || node.bind?.hidden) && { icon: 'b-eye-slash', title: node.logic?.show ? t('Shown when {condition}', { condition: node.logic.show }) : t('Shown conditionally') },
  ].filter(Boolean) as { icon: string; title: string }[]

  return (
    <div
      ref={setNodeRef}
      data-layer={node.id}
      style={{ transform: CSS.Translate.toString(transform), transition, paddingLeft: 4 + depth * 12 }}
      {...attributes}
      {...listeners}
      onClick={(e) => {
        if (e.shiftKey) store.extendTo(node.id)
        else store.select(node.id, true)
        bus.emit('scroll-to', { node: node.id })
      }}
      onMouseEnter={() => store.hover(node.id)}
      onKeyDown={(e) => {
        if (renaming || e.target !== e.currentTarget) return
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault()
          store.select(node.id, true)
          bus.emit('scroll-to', { node: node.id })
        } else if (!readOnly && e.key === 'F2') {
          e.preventDefault()
          setRenaming(true)
        }
      }}
      className={cn(
        'group relative flex h-7 cursor-default select-none items-center gap-1.5 rounded-md pr-1 text-sm outline-none focus-visible:shadow-[inset_0_0_0_1.5px_var(--cms-focus)]',
        selected ? 'bg-focus-soft text-ink' : hovered ? 'bg-hover text-text' : 'text-text',
        isDragging && 'z-10 bg-bg opacity-80 shadow-pop',
      )}
    >
      <button
        type="button"
        onClick={(e) => {
          e.stopPropagation()
          onToggle()
        }}
        className={cn('-mr-1 grid size-4 shrink-0 place-items-center rounded-xs text-faint hover:text-ink', !hasChildren && 'invisible')}
        aria-label={collapsed ? t('Expand') : t('Collapse')}
      >
        <Icon name="b-caret-down" size={10} className={cn('transition-transform duration-120', collapsed && '-rotate-90')} />
      </button>
      <Icon name={component ? 'b-diamonds-four' : type?.icon ?? 'square'} size={14} className={cn('shrink-0', node.hidden ? 'text-ghost' : selected ? 'text-ink' : component ? 'text-muted' : 'text-faint')} />
      {renaming ? (
        <div className="min-w-0 flex-1" onClick={(e) => e.stopPropagation()} onPointerDown={(e) => e.stopPropagation()} onKeyDown={(e) => e.stopPropagation()} onKeyUp={(e) => e.key === 'Escape' && (e.target as HTMLElement).blur()} onBlur={() => setRenaming(false)}>
          <PanelFields
            bare
            fields={[{ handle: 'label', type: 'text', size: 'sm', commit: 'blur', label: t('Layer Name'), autofocus: true, placeholder: type?.title }]}
            values={{ label: node.label ?? '' }}
            onChange={(_, v: string) => store.updateNode(node.id, { label: v.trim() || undefined })}
          />
        </div>
      ) : (
        <span
          onDoubleClick={(e) => {
            e.stopPropagation()
            if (!readOnly) setRenaming(true)
          }}
          title={readOnly ? undefined : t('Double-Click to Rename')}
          className={cn('min-w-0 flex-1 truncate', node.hidden ? 'text-faint line-through decoration-faint/60' : plain && 'text-muted')}
        >
          {label}
          {hint && <span className={cn('ml-1.5', selected ? 'text-muted' : 'text-faint')}>{hint}</span>}
        </span>
      )}
      {!renaming && badges.map((b) => (
        <Tooltip key={b.icon} content={b.title}>
          <span className="grid size-5 shrink-0 place-items-center text-faint group-hover:hidden">
            <Icon name={b.icon} size={12} />
          </span>
        </Tooltip>
      ))}
      <Tooltip content={node.hidden ? t('Show') : t('Hide')}>
        <button
          type="button"
          disabled={readOnly}
          onClick={(e) => {
            e.stopPropagation()
            store.updateNode(node.id, { hidden: !node.hidden || undefined })
          }}
          onPointerDown={(e) => e.stopPropagation()}
          className={cn('grid size-6 shrink-0 place-items-center rounded-sm text-faint hover:bg-hover hover:text-ink', node.hidden ? 'grid' : 'hidden group-hover:grid group-focus-visible:grid')}
          aria-label={node.hidden ? t('Show') : t('Hide')}
        >
          <Icon name={node.hidden ? 'eye-off' : 'eye'} size={14} />
        </button>
      </Tooltip>
    </div>
  )
}
