import { t } from '@letterncms/sdk'
import { Icon, IconButton, Menu, Popover, Tooltip, cn } from '@letterncms/ui'
import { useLayoutEffect, useRef, useState, type PointerEvent, type ReactNode } from 'react'
import type { InsertionTarget, Rect } from './core/insertionTargets'
import type { BlockDrag } from './core/useBlockDrag'

/** Height of the block toolbar and its distance from the block. */
const BAR = 32, BAR_GAP = 6, EDGE = 4

interface PickerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Where focus goes when the picker closes (the new block's text, if one was inserted). */
  returnFocus: () => HTMLElement | null | false
  children: ReactNode
}

/** A boundary between blocks: a line along it and a `+` at its center that opens the quick inserter. */
export function BoundaryInserter({ target, position, ...picker }: PickerProps & { target: InsertionTarget; position: (rect: Rect) => Rect }) {
  const rect = position(target.rect)
  return <>
    <div data-cms-insertion-line className={cn('pointer-events-none absolute z-10 rounded-full bg-focus', target.axis === 'x' ? 'cms-block-line-y' : 'cms-block-line-x')} style={{ ...rect, minWidth: 2, minHeight: 2 }} />
    <Picker {...picker} label={t('Add Block')} className="cms-block-plus absolute size-6 -translate-x-1/2 -translate-y-1/2 rounded-xs bg-focus text-white shadow-sm" style={{ left: rect.left + rect.width / 2, top: rect.top + rect.height / 2 }}
      data={{ 'data-cms-inserter': `${target.parentId ?? ''}:${target.index}` }} />
  </>
}

/** The `+` inside an empty container's drop area. */
export function ContainerAppender({ target, position, ...picker }: PickerProps & { target: InsertionTarget; position: (rect: Rect) => Rect }) {
  const rect = position(target.hitRect)
  return <Picker {...picker} label={t('Add Block')} className="cms-block-chrome absolute size-7 -translate-x-1/2 -translate-y-1/2 rounded-sm border border-border-strong bg-bg text-muted shadow-sm hover:border-focus hover:text-focus"
    style={{ left: rect.left + rect.width / 2, top: rect.top + Math.min(rect.height / 2, 40) }} data={{ 'data-cms-appender': target.parentId ?? '' }} />
}

/** An empty document: start writing, or pick a block. */
export function DocumentAppender({ target, position, onWrite, ...picker }: PickerProps & { target: InsertionTarget; position: (rect: Rect) => Rect; onWrite?: () => void }) {
  const rect = position(target.hitRect)
  return <div data-cms-document-appender className="cms-block-chrome pointer-events-auto absolute z-10 flex h-10 whitespace-nowrap -translate-x-1/2 -translate-y-1/2 items-center gap-1 rounded-md border border-dashed border-border-strong bg-bg/90 py-1 pl-3 pr-1 shadow-sm" style={{ left: rect.left + rect.width / 2, top: rect.top + Math.max(24, Math.min(rect.height / 2, 120)) }}>
    {onWrite && <button type="button" onClick={onWrite} className="cms-focus-ring h-8 rounded-xs px-1 text-sm text-muted hover:text-text">{t('Write, or press “/” for blocks')}</button>}
    <Picker {...picker} label={t('Add Block')} className="size-8 rounded-xs text-muted hover:bg-hover hover:text-text" />
  </div>
}

function Picker({ open, onOpenChange, returnFocus, children, label, className, style, data }: PickerProps & { label: string; className: string; style?: React.CSSProperties; data?: Record<string, string> }) {
  return <Popover open={open} onOpenChange={onOpenChange} side="bottom" align="center" className="!overflow-hidden !p-0" returnFocus={returnFocus}
    trigger={<button type="button" {...data} aria-label={open ? t('Close') : label} aria-expanded={open} className={cn('cms-focus-ring pointer-events-auto z-20 grid place-items-center', className)} style={style}><Icon name="plus" size={14} className={cn('cms-block-plus-icon', open && 'rotate-45')} /></button>}>
    {open && children}
  </Popover>
}

interface ToolbarProps {
  id: string
  label: string
  icon: string
  parent: { label: string; icon: string } | null
  /** The selected block's frame, in workspace coordinates. */
  box: Rect
  viewport: { width: number; height: number }
  canMoveUp: boolean
  canMoveDown: boolean
  onSelectParent: () => void
  onDrag: (event: PointerEvent) => void
  onMove: (direction: -1 | 1) => void
  onInsert: (side: 'before' | 'after') => void
  onDuplicate: () => void
  /** Save the selection as a component or section, where the person may. */
  onMakeComponent?: () => void
  onDelete: () => void
  /** Whether the toolbar would cover the page's content (text, images, controls) at this place. */
  covers?: (rect: Rect) => boolean
}

/**
 * One toolbar for the selected block, at its left edge (or right): above it, or — where that would
 * cover the page's content or leave the view — below it, or inside its top. A tall block keeps it at
 * the top of the view while it is partly scrolled away; it leaves with the block.
 */
export function BlockToolbar({ id, label, icon, parent, box, viewport, canMoveUp, canMoveDown, onSelectParent, onDrag, onMove, onInsert, onDuplicate, onMakeComponent, onDelete, covers }: ToolbarProps) {
  const bar = useRef<HTMLDivElement>(null)
  const [width, setWidth] = useState(240)
  useLayoutEffect(() => { if (bar.current) setWidth(bar.current.offsetWidth) })
  const bottom = box.top + box.height
  if (bottom < BAR + EDGE * 2 || box.top > viewport.height - BAR || box.left > viewport.width || box.left + box.width < 0) return null
  const clamp = (x: number) => Math.max(EDGE, Math.min(viewport.width - width - EDGE, x))
  const start = clamp(box.left), end = clamp(box.left + box.width - width)
  const above = box.top - BAR - BAR_GAP, below = bottom + BAR_GAP, inside = Math.max(EDGE, box.top + EDGE)
  const fits = (y: number) => y >= EDGE && y + BAR <= viewport.height - EDGE
  // Its left edge first, then its right: above, below, then inside the block's top.
  const places = [above, below, inside].flatMap((y) => [{ left: start, top: y }, { left: end, top: y }])
  const { left, top } = places.find((at) => fits(at.top) && !covers?.({ ...at, width, height: BAR })) ?? { left: start, top: fits(above) ? above : inside }
  return <div ref={bar} data-cms-block-controls={id} role="toolbar" aria-label={t('Block Actions')} className="cms-block-chrome pointer-events-auto absolute z-30 flex h-8 items-center gap-0.5 rounded-sm border border-border bg-bg pl-0.5 pr-1 shadow-pop" style={{ left, top }}>
    <button type="button" aria-label={t('Drag to Move')} title={t('Drag to Move')} className="cms-focus-ring grid h-6 w-4 touch-none cursor-grab place-items-center rounded-xs text-faint hover:bg-hover hover:text-muted active:cursor-grabbing" onPointerDown={onDrag}><Icon name="drag" size={12} /></button>
    {parent && <Tooltip content={t('Select {block}', { block: parent.label })}><IconButton size="xs" icon="b-arrow-elbow-left-up" label={t('Select {block}', { block: parent.label })} onClick={onSelectParent} /></Tooltip>}
    <span className="mx-0.5 h-4 border-l border-border" />
    <span className="flex h-6 max-w-40 items-center gap-1.5 px-1 text-xs font-medium text-text"><Icon name={icon} size={14} className="shrink-0 text-muted" /><span className="truncate">{label}</span></span>
    <IconButton size="xs" icon="chevron-up" label={t('Move Up')} disabled={!canMoveUp} onClick={() => onMove(-1)} />
    <IconButton size="xs" icon="chevron-down" label={t('Move Down')} disabled={!canMoveDown} onClick={() => onMove(1)} />
    <span className="mx-0.5 h-4 border-l border-border" />
    <Menu align="end" trigger={<IconButton size="xs" icon="more" label={t('More Block Actions')} />} items={[
      { label: t('Insert Before'), icon: 'arrow-up', shortcut: 'alt+mod+t', onSelect: () => onInsert('before') },
      { label: t('Insert After'), icon: 'arrow-down', shortcut: 'alt+mod+y', onSelect: () => onInsert('after') },
      { label: t('Duplicate'), icon: 'duplicate', shortcut: 'mod+d', onSelect: onDuplicate },
      onMakeComponent && { label: t('Make a Component'), icon: 'symbol', onSelect: onMakeComponent },
      { separator: true, label: '' },
      { label: t('Delete'), icon: 'trash', danger: true, shortcut: '⌫', onSelect: onDelete },
    ]} />
  </div>
}

export function LiveDragPreview({ drag, position }: { drag: BlockDrag; position: (rect: Rect) => Rect }) {
  return <>
    <div data-cms-drag-overlay className="pointer-events-auto absolute inset-0 z-30 cursor-grabbing touch-none" />
    {drag.target && <div data-cms-drop-indicator className="pointer-events-none absolute z-40 rounded-full bg-focus" style={{ ...position(drag.target.rect), minWidth: 2, minHeight: 2 }} />}
    <div data-cms-drag-ghost className="pointer-events-none fixed z-[80] flex max-w-56 items-center gap-2 truncate rounded-sm border border-border-strong bg-bg px-3 py-2 text-xs text-ink shadow-pop" style={{ left: Math.max(8, Math.min(window.innerWidth - 232, drag.point.x + 16)), top: Math.max(8, Math.min(window.innerHeight - 40, drag.point.y + 16)) }}><Icon name="drag" size={14} /><span className="truncate">{drag.label}</span></div>
  </>
}
