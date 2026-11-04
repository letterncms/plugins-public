import { can, locale, plural, t, xsrfToken, type EntryContent } from '@letterncms/sdk'
import { QueryError, Spinner } from '@letterncms/ui'
import { createPortal, flushSync } from 'react-dom'
import { memo, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { blockName, useComponentTitles, useEditorContext, useTokens } from './core/context'
import { caretRect } from './core/caret'
import { FormatToolbar } from './core/FormatToolbar'
import { InlineEditable } from './core/InlineEditable'
import { Inserter, type InsertChoice } from './core/Inserter'
import { accepts, choiceToNodes, insertionPoint, patternFits } from './core/insert'
import { savePattern } from './core/SavePattern'
import { insertionTargets, pickDropTarget, pickHoverTarget, type BlockBox, type InsertionTarget, type Rect } from './core/insertionTargets'
import { QuickInserter } from './core/QuickInserter'
import { morphDocument } from './core/morph'
import { useBlockDrag } from './core/useBlockDrag'
import { BlockToolbar, BoundaryInserter, ContainerAppender, DocumentAppender, LiveDragPreview } from './LiveBlockControls'
import { selectionOf, useEditor } from './core/store'
import * as tree from './core/tree'
import type { BlockNode } from './core/types'
import { bus } from './builder/bus'
import { WriteContext, focusFirst, paragraph, type WriteApi } from './write/doc'
import { outline, stops as documentStops } from './write/outline'
import { EditableText, ListBlock } from './write/Text'
import { openSlash, slashPlacement, useSlash, useSlashDismiss } from './write/slash'

interface Props {
  collection?: string
  entryId?: string | null
  content: EntryContent
  onInspect: (id: string) => void
  standalone?: boolean
  onTitleChange?: (title: string) => void
}
interface Slot { element: HTMLElement; id: string; prop: string; index?: number; instance?: { type: string; value: string } }
/** Block geometry in frame-viewport coordinates, taken at `scroll`. */
interface Metrics { blocks: BlockBox[]; rootRect: Rect; scroll: { x: number; y: number } }
/** A boundary drawn just outside one edge of a block. */
const edgeTarget = (at: InsertionTarget, block: Rect, side: 'before' | 'after'): InsertionTarget => at.axis === 'x'
  ? { ...at, rect: { left: side === 'before' ? block.left - 3 : block.left + block.width + 1, top: block.top, width: 2, height: block.height } }
  : { ...at, rect: { left: block.left, top: side === 'before' ? block.top - 3 : block.top + block.height + 1, width: block.width, height: 2 } }
/** One identity for a boundary, so the open inserter survives re-measuring. */
const targetKey = (at: InsertionTarget) => `${at.parentId ?? ''}:${at.index}:${at.inside ? 'inside' : at.axis}`
const bridgeCss = `
html{scrollbar-width:thin;scrollbar-color:rgb(127 127 127/.35) transparent}
[data-cms-document][data-cms-node]{cursor:default}
.cms-block-text{display:block;min-width:1ch;min-height:1lh;white-space:pre-wrap;overflow-wrap:break-word;outline:none;border-radius:0;font:inherit;color:inherit;line-height:inherit;text-align:inherit;cursor:text}
.cms-block-text:empty::before{content:attr(data-placeholder);opacity:.4;pointer-events:none}
.cms-block-text[data-quiet]:empty:not(:focus)::before{content:none}
[data-cms-prop]{cursor:text}
.cms-block-text::selection,.cms-block-text ::selection{background:rgb(0 122 255/.2)}
img,a{-webkit-user-drag:none}
[data-cms-conditional=hidden]:not([data-cms-reveal]){display:none!important}
[data-cms-editor-empty-root]{visibility:hidden}
.cms-js [data-cms-appear]{visibility:visible!important}
`

const MEDIA = new Set(['IMG', 'PICTURE', 'VIDEO', 'IFRAME', 'CANVAS', 'INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'])

/** Whether a point of the page has content on it: text (where its lines are), an image or icon, or a control. Padding and backgrounds don't count. */
function contentAt(d: Document, x: number, y: number): boolean {
  const el = d.elementFromPoint(x, y)
  if (!el || el === d.body || el === d.documentElement) return false
  if (MEDIA.has(el.tagName) || el.closest('svg')) return true
  const texts = [...el.childNodes].filter((n) => n.nodeType === Node.TEXT_NODE && n.textContent?.trim())
  if (!texts.length) return false
  const range = d.createRange()
  return texts.some((text) => {
    range.selectNodeContents(text)
    return [...range.getClientRects()].some((r) => x >= r.left - 2 && x <= r.right + 2 && y >= r.top - 2 && y <= r.bottom + 2)
  })
}

/** A breakpoint's width in CSS pixels (rem and em at 16px). */
function pixels(value: string | undefined): number {
  const n = parseFloat(value ?? '')
  return Number.isNaN(n) ? 0 : /r?em$/.test(value ?? '') ? n * 16 : n
}

/** The site's renderer is the editor. React owns only explicit inline text slots. */
export function LiveEditor({ collection, entryId, content, onInspect, standalone, onTitleChange }: Props) {
  const { store, types, allowed, fieldHandle, readOnly = false } = useEditorContext()
  const components = useComponentTitles()
  const nodes = useEditor(store, (state) => state.nodes)
  const selected = useEditor(store, (state) => state.selected)
  const selectedIds = useEditor(store, selectionOf)
  const blockMode = useEditor(store, (state) => state.blockMode)
  const hovered = useEditor(store, (state) => state.hovered)
  const breakpoint = useEditor(store, (state) => state.breakpoint)
  const { breakpoints } = useTokens()
  // Desktop styles apply from the site's large breakpoint up (tablet styles below it).
  const desktopWidth = pixels(breakpoints.lg) || 1024
  const viewport = useRef<HTMLDivElement>(null)
  const frame = useRef<HTMLIFrameElement>(null)
  const [doc, setDoc] = useState<Document | null>(null)
  const token = useRef('')
  const [slots, setSlots] = useState<Slot[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [retry, setRetry] = useState(0)
  const [layout, setLayout] = useState({ width: 1024, height: 700, scale: 1, left: 0 })
  const [boxes, setBoxes] = useState<Rect[]>([])
  const [hoverBox, setHoverBox] = useState<Rect | null>(null)
  const [gaps, setGaps] = useState<InsertionTarget[]>([])
  /** The boundary under a resting pointer (shown after a short rest, then fixed to its boundary). */
  const [hoverGap, setHoverGap] = useState<InsertionTarget | null>(null)
  const hoverTimer = useRef(0), pendingGap = useRef('')
  /** The caret is in text; typing hides hover chrome until the pointer moves again. */
  const [writing, setWriting] = useState(false)
  const [typing, setTyping] = useState(false)
  const typingRef = useRef(false)
  const geometry = useRef<Metrics | null>(null)
  const sourceIndex = useMemo(() => {
    const out: { node: BlockNode; parentId: string | null; index: number }[] = []
    const walk = (list: BlockNode[], parentId: string | null) => list.forEach((node, index) => { out.push({ node, parentId, index }); if (node.children) walk(node.children, node.id) })
    walk(nodes, null); return out
  }, [nodes])
  const indexed = useRef(sourceIndex); indexed.current = sourceIndex
  /** The boundary the quick inserter is open on. */
  const [chooser, setChooser] = useState<InsertionTarget | null>(null)
  const chooserRef = useRef(chooser); chooserRef.current = chooser
  /** Insert before/after a block anchors to that block's edge rather than to the middle of the gap. */
  const chooserEdge = useRef<{ key: string; id: string; side: 'before' | 'after' } | null>(null)
  useEffect(() => { if (!chooser) chooserEdge.current = null }, [chooser])
  const request = useRef(0)
  const rendering = useRef({ busy: false, queued: false, timer: 0, alive: true })
  const runRender = useRef<() => Promise<void>>(async () => {})
  const scheduleRender = () => {
    const state = rendering.current
    state.queued = true
    if (!state.alive || state.busy || state.timer) return
    state.timer = window.setTimeout(() => { state.timer = 0; void runRender.current() }, token.current ? 80 : 0)
  }
  const structure = () => indexed.current.map(({ node, parentId, index }) => `${parentId}:${index}:${node.id}:${node.type}:${node.type === 'list' ? node.props.items?.length ?? 0 : ''}`).join('|')
  const abort = useRef<AbortController | null>(null)
  const latest = useRef({ content, onInspect, readOnly, layout, onTitleChange })
  latest.current = { content, onInspect, readOnly, layout, onTitleChange }
  // A block inside a Repeat is drawn once per item, marked as a prototype: its first item stands for it.
  const source = (d: Document, id: string) => d.querySelector<HTMLElement>(`[data-cms-document="${token.current}"][data-cms-node="${CSS.escape(id)}"]`)
    ?? d.querySelector<HTMLElement>(`[data-cms-proto-document="${token.current}"][data-cms-proto="${CSS.escape(id)}"]`)
  /** The innermost block of this document under an element: a source block, or one a Repeat draws. */
  const ownerOf = (target: EventTarget | null): string | null => {
    const match = '[data-cms-document][data-cms-node],[data-cms-proto-document][data-cms-proto]'
    let el = (target as Element | null)?.closest?.<HTMLElement>(match) ?? null
    while (el) {
      const id = el.dataset.cmsDocument === token.current ? el.dataset.cmsNode : el.dataset.cmsProtoDocument === token.current ? el.dataset.cmsProto : undefined
      if (id && tree.find(store.get().nodes, id)) return id
      el = el.parentElement?.closest<HTMLElement>(match) ?? null
    }
    return null
  }
  const rectOf = (el: Element): DOMRect => {
    if (el.ownerDocument.defaultView?.getComputedStyle(el).display !== 'contents') return el.getBoundingClientRect()
    const range = el.ownerDocument.createRange(); range.selectNodeContents(el); return range.getBoundingClientRect()
  }
  const metrics = (): Metrics | null => {
    if (geometry.current) return geometry.current
    const d = frame.current?.contentDocument
    const root = d?.querySelector<HTMLElement>(`[data-cms-editor-root="${token.current}"]`)
    if (!d || !root) return null
    const axis = (el: Element) => { const style = d.defaultView!.getComputedStyle(el); return (style.display.includes('flex') && style.flexDirection.startsWith('row')) || (style.display.includes('grid') && style.gridTemplateColumns.split(' ').length > 1) ? 'x' as const : 'y' as const }
    const elements = new Map([...d.querySelectorAll<HTMLElement>(`[data-cms-document="${token.current}"][data-cms-node]`)].map((el) => [el.dataset.cmsNode, el]))
    const blocks = indexed.current.flatMap(({ node, parentId, index }) => {
      const el = elements.get(node.id)
      if (!el || d.defaultView!.getComputedStyle(el).display === 'none') return []
      return [{ id: node.id, parentId, index, rect: rectOf(el), container: !!types.get(node.type)?.children && !['repeat', 'pattern'].includes(node.type), axis: axis(el) }]
    })
    let rootRect: Rect = rectOf(root)
    if (rootRect.width === 0) rootRect = rectOf(root.parentElement ?? d.body)
    if (!blocks.length) rootRect = { ...rootRect, left: rootRect.left, top: rootRect.top, width: rootRect.width, height: Math.max(32, rootRect.height) }
    geometry.current = { blocks, rootRect, scroll: { x: d.defaultView!.scrollX, y: d.defaultView!.scrollY } }
    return geometry.current
  }
  const permits = (parentId: string | null, names: string[] = ['paragraph']) => {
    const parent = parentId ? tree.find(store.get().nodes, parentId) : null
    return names.every((name) => accepts(parent, name, types, parent ? [] : allowed))
  }
  const hasChoices = (parentId: string | null) => [...types.values()].some((type) => permits(parentId, [type.name]))
  /** `hasChoices` for one geometry pass: every boundary of a parent asks the same question. */
  const choicesOnce = () => { const known = new Map<string | null, boolean>(); return (parentId: string | null) => known.get(parentId) ?? known.set(parentId, hasChoices(parentId)).get(parentId)! }
  const measure = () => {
    const d = frame.current?.contentDocument, m = metrics()
    if (!d || !m) return
    setBoxes(selectionOf(store.get()).flatMap((id) => { const el = source(d, id); return el ? [rectOf(el)] : [] }))
    const hover = store.get().hovered
    const hoverElement = hover ? source(d, hover) : null
    setHoverBox(hoverElement ? rectOf(hoverElement) : null)
    const fresh = insertionTargets(m.blocks, { rootRect: m.rootRect, canInsert: choicesOnce() })
    setGaps(fresh)
    // An open inserter stays on its boundary as the page scrolls, and closes when the boundary leaves view.
    setChooser((current) => {
      if (!current) return current
      const edge = chooserEdge.current?.key === targetKey(current) ? chooserEdge.current : null
      const element = edge ? source(d, edge.id) : null
      const next = edge ? (element ? edgeTarget(current, rectOf(element), edge.side) : current) : fresh.find((at) => targetKey(at) === targetKey(current)) ?? current
      const y = next.inside ? next.hitRect.top : next.rect.top
      return y < 0 || y > latest.current.layout.height ? null : next
    })
  }
  const showGap = (at: InsertionTarget | null) => {
    const key = at ? targetKey(at) : ''
    if (key === pendingGap.current) return
    pendingGap.current = key
    clearTimeout(hoverTimer.current)
    setHoverGap(null)
    if (at) hoverTimer.current = window.setTimeout(() => setHoverGap(at), 80)
  }
  /** Insert before or after the selection: the same boundary the between-block inserter would show. */
  const openInserter = (side: 'before' | 'after') => {
    const ids = selectionOf(store.get())
    const loc = ids.length ? tree.locate(store.get().nodes, side === 'before' ? ids[0] : ids.at(-1)!) : null
    const d = frame.current?.contentDocument
    if (!loc || !d || latest.current.readOnly || !hasChoices(loc.parentId)) return
    const index = side === 'before' ? loc.index : loc.index + 1
    const element = source(d, side === 'before' ? ids[0] : ids.at(-1)!)
    const edge = element ? rectOf(element) : null
    const win = d.defaultView!, height = latest.current.layout.height
    if (edge) {
      const y = side === 'before' ? edge.top : edge.top + edge.height
      if (y < 48 || y > height - 160) win.scrollBy(0, y - height / 3)
    }
    requestAnimationFrame(() => {
      geometry.current = null
      const m = metrics(), rect = element ? rectOf(element) : null
      if (!rect) return
      const found = m && insertionTargets(m.blocks, { rootRect: m.rootRect, canInsert: choicesOnce() }).find((at) => !at.inside && at.parentId === loc.parentId && at.index === index)
      // The line hugs the selected block, whatever the gap to its neighbor.
      const target = edgeTarget(found ?? { parentId: loc.parentId, index, axis: 'y', kind: 'edge', inside: false, depth: 0, rect, hitRect: rect }, rect, side)
      chooserEdge.current = { key: targetKey(target), id: side === 'before' ? ids[0] : ids.at(-1)!, side }
      showGap(null)
      setChooser(target)
    })
  }
  /** Where focus goes when the inserter closes: the new block's text, or the text you were writing in. */
  const returnFocus = () => {
    const active = doc?.activeElement as HTMLElement | null
    if (document.activeElement === frame.current && active?.isContentEditable) return active
    const focus = store.get().focus
    const owner = focus && doc ? source(doc, focus.id) : null
    return owner?.querySelector<HTMLElement>('[data-cms-inline]') ?? null
  }
  const collectSlots = (d: Document) => {
    geometry.current = null
    const found: Slot[] = []
    if (latest.current.onTitleChange) for (const title of d.querySelectorAll('[data-cms-entry-field="title"]')) if (!title.querySelector('[data-cms-inline]')) title.replaceChildren()
    for (const element of d.querySelectorAll<HTMLElement>('[data-cms-prop]')) {
      const owner = element.closest<HTMLElement>('[data-cms-document][data-cms-node]')
      if (owner?.dataset.cmsDocument !== token.current || !owner.dataset.cmsNode) continue
      if (!element.querySelector('[data-cms-inline]')) element.replaceChildren()
      found.push({ element, id: owner.dataset.cmsNode, prop: element.dataset.cmsProp!, ...(element.dataset.cmsIndex !== undefined ? { index: Number(element.dataset.cmsIndex) } : {}) })
    }
    for (const element of d.querySelectorAll<HTMLElement>('[data-cms-instance-prop]')) {
      if (element.dataset.cmsInstanceDocument !== token.current || !element.dataset.cmsInstanceNode) continue
      if (!element.querySelector('[data-cms-inline]')) element.replaceChildren()
      found.push({ element, id: element.dataset.cmsInstanceNode, prop: element.dataset.cmsInstanceProp!, instance: { type: element.dataset.cmsInstanceType ?? 'text', value: element.dataset.cmsInstanceValue ?? '' } })
    }
    setSlots(found)
    requestAnimationFrame(measure)
  }
  /** Use inert, server-rendered prototypes until the authoritative render arrives. */
  const optimistic = () => {
    const d = frame.current?.contentDocument
    const root = d?.querySelector<HTMLElement>('[data-cms-editor-root]')
    if (!d || !root || !token.current) return
    const ids = new Set(tree.flatten(store.get().nodes).map(({ node }) => node.id))
    for (const el of d.querySelectorAll<HTMLElement>('[data-cms-document][data-cms-node]')) if (el.dataset.cmsDocument === token.current && !ids.has(el.dataset.cmsNode!)) el.remove()
    const sync = (list: BlockNode[], parent: HTMLElement) => {
      for (let index = 0; index < list.length; index++) {
        const node = list[index]
        let el = source(d, node.id)
        if (!el || el.dataset.cmsType !== node.type) {
          const prototype = d.querySelector<HTMLTemplateElement>(`template[data-cms-prototype="${CSS.escape(node.type)}"]`)
          const fresh = prototype?.content.firstElementChild?.cloneNode(true) as HTMLElement | undefined
          if (!fresh) continue
          fresh.dataset.cmsNode = node.id; fresh.dataset.cmsDocument = token.current
          if (el) el.replaceWith(fresh)
          else {
            const next = list.slice(index + 1).map((n) => source(d, n.id)).find(Boolean)
            const previous = list.slice(0, index).reverse().map((n) => source(d, n.id)).find(Boolean)
            if (next) next.before(fresh)
            else if (previous) previous.after(fresh)
            else parent.appendChild(fresh)
          }
          el = fresh
        }
        if (node.type === 'list') {
          const items: string[] = Array.isArray(node.props.items) && node.props.items.length ? node.props.items : ['']
          const itemSlots = [...el.querySelectorAll<HTMLElement>('[data-cms-prop="items"]')]
          for (let i = itemSlots.length; i < items.length; i++) {
            const li = itemSlots[0]?.cloneNode(false) as HTMLElement | undefined
            if (li) { li.dataset.cmsIndex = String(i); itemSlots[0].parentElement!.appendChild(li) }
          }
          itemSlots.slice(items.length).forEach((item) => item.remove())
        }
        if (node.children && !['repeat', 'pattern'].includes(node.type)) sync(node.children, el)
      }
    }
    // The empty-page label must not stay beside new content: the authoritative render would morph it into the first block and drop its caret.
    if (store.get().nodes.length) root.querySelector(':scope > [data-cms-editor-empty-root]')?.remove()
    sync(store.get().nodes, root)
    collectSlots(d)
  }
  const api = useMemo<WriteApi>(() => ({
    stops: () => documentStops(outline(store.get().nodes, types, () => false)).filter((stop) => slots.some((slot) => slot.id === stop.id && slot.prop === stop.prop && (stop.index === undefined || slot.index === stop.index))),
    settings: (id) => latest.current.onInspect(id), readOnly,
  }), [store, types, slots, readOnly])
  const writeApi = useRef(api); writeApi.current = api
  useLayoutEffect(() => { geometry.current = null; optimistic() }, [nodes, doc])
  useLayoutEffect(() => {
    if (!viewport.current) return
    const fit = () => {
      const el = viewport.current!
      // Each screen draws at a width where the site shows that screen's styles. Desktop takes the room
      // there is, but never less than the site's desktop width: with less room, it is scaled down rather
      // than reflowed (a narrow canvas would show the tablet or phone layout while desktop is styled).
      // A phone draws at the room there is, up to a phone's width.
      const desktop = Math.max(el.clientWidth, desktopWidth)
      const width = breakpoint === 'tablet' ? 820 : breakpoint === 'mobile' ? Math.min(390, el.clientWidth) : desktop
      const scale = Math.min(1, el.clientWidth / width)
      geometry.current = null
      setLayout({ width, scale, height: el.clientHeight / scale, left: Math.max(0, (el.clientWidth - width * scale) / 2) })
    }
    fit(); const observer = new ResizeObserver(fit); observer.observe(viewport.current)
    return () => observer.disconnect()
  }, [breakpoint, desktopWidth])
  useEffect(() => { requestAnimationFrame(measure) }, [selected, selectedIds, hovered, layout, doc])
  runRender.current = async () => {
      const state = rendering.current
      state.queued = false; state.busy = true
      const sequence = request.current
      const capturedStructure = structure()
      const controller = new AbortController(); abort.current = controller
      try {
        const response = await fetch(entryId ? `/__preview/${entryId}` : '/__preview', { method: 'POST', credentials: 'same-origin', signal: controller.signal, headers: { 'Content-Type': 'application/json', Accept: 'text/html', 'X-XSRF-TOKEN': xsrfToken() ?? '', 'X-Cms-Locale': locale() }, body: JSON.stringify({ ...content, collection, field: fieldHandle, standalone, data: { ...content.data, [fieldHandle]: store.get().nodes }, play: false }) })
        const html = await response.text()
        if (!response.ok) throw new Error(response.status === 422 ? html.replace(/<[^>]+>/g, '').trim() : t('Preview failed ({status})', { status: response.status }))
        if (!state.alive || controller.signal.aborted || (sequence !== request.current && capturedStructure !== structure())) return
        const scope = response.headers.get('X-Cms-Editor-Document')
        if (!scope) throw new Error(t('The editor could not load its rendering context.'))
        const next = new DOMParser().parseFromString(html, 'text/html')
        if (!next.querySelector(`[data-cms-editor-root="${scope}"]`)) throw new Error(t('This template has no content outlet. Choose a template that includes the content field.'))
        next.querySelectorAll('script').forEach((script) => { script.type = 'text/cms-off'; script.removeAttribute('src') })
        token.current = scope
        const d = frame.current?.contentDocument
        if (d?.documentElement?.hasAttribute('data-cms-bridge-ready')) {
          morphDocument(d, next); d.documentElement.setAttribute('data-cms-bridge-ready', '')
          collectSlots(d)
        } else if (frame.current) frame.current.srcdoc = next.documentElement.outerHTML
        if (sequence === request.current) setError(null)
      } catch (failure) { if (!controller.signal.aborted && sequence === request.current) { setError(failure instanceof Error ? failure.message : String(failure)); setLoading(false) } }
      finally { state.busy = false; if (state.queued) scheduleRender() }
  }
  useEffect(() => {
    request.current++
    scheduleRender()
  }, [entryId, collection, content, nodes, fieldHandle, standalone, retry])
  useEffect(() => {
    rendering.current.alive = true
    if (rendering.current.queued) scheduleRender()
    return () => { rendering.current.alive = false; clearTimeout(rendering.current.timer); rendering.current.timer = 0; abort.current?.abort(); }
  }, [])
  const choose = (choice: InsertChoice, at: InsertionTarget) => {
    const created = choiceToNodes(choice, types)
    if (!created.length || readOnly || !permits(at.parentId, created.map((node) => node.type))) return
    store.insert(at.parentId, at.index, created); focusFirst(store, created[0], documentStops(outline(store.get().nodes, types, () => false)))
    setChooser(null); showGap(null)
  }
  const startWriting = () => {
    if (latest.current.readOnly || !permits(null)) return
    const node = paragraph(); store.insert(null, 0, [node]); store.focus(node.id, 'start')
  }
  /** The rendered source blocks in reading order, for ↑/↓ on a selected block. */
  const renderedOrder = () => {
    const d = frame.current?.contentDocument
    return d ? indexed.current.filter(({ node }) => { const el = source(d, node.id); return !!el && d.defaultView!.getComputedStyle(el).display !== 'none' }).map(({ node }) => node.id) : []
  }
  const selectBlock = (id: string | undefined) => {
    if (!id) return
    store.select(id, true)
    const d = frame.current?.contentDocument, el = d && source(d, id)
    el?.scrollIntoView({ block: 'nearest' })
  }
  const blockKeys = (event: KeyboardEvent) => {
    const mod = event.metaKey || event.ctrlKey
    const ids = selectionOf(store.get()), id = store.get().selected
    if (event.key === 'Escape') { store.select(null); return }
    // Structural keys belong to block selection; a stray blur from text must not turn arrows into selection moves.
    if (!id || !store.get().blockMode) return
    const order = renderedOrder(), at = order.indexOf(store.get().head ?? id)
    if (['ArrowUp', 'ArrowDown'].includes(event.key)) {
      event.preventDefault()
      const up = event.key === 'ArrowUp'
      if (event.altKey) store.nudgeSelection(up ? -1 : 1)
      else if (event.shiftKey) store.extend(up ? -1 : 1)
      else selectBlock(order[at + (up ? -1 : 1)])
    } else if (event.key === 'Enter') {
      event.preventDefault()
      const node = tree.find(store.get().nodes, id)
      const stops = writeApi.current.stops(), within = node ? new Set(tree.flatten([node]).map((entry) => entry.node.id)) : new Set<string>()
      if (stops.some((stop) => within.has(stop.id))) focusFirst(store, node!, stops)
      else latest.current.onInspect(id)
    } else if (event.key === 'Backspace' || event.key === 'Delete') {
      event.preventDefault()
      const gone = new Set(ids.flatMap((selectedId) => { const node = tree.find(store.get().nodes, selectedId); return node ? tree.flatten([node]).map((entry) => entry.node.id) : [] }))
      const first = order.findIndex((entry) => gone.has(entry))
      const fallback = order.slice(0, Math.max(0, first)).reverse().find((entry) => !gone.has(entry)) ?? order.slice(first + 1).find((entry) => !gone.has(entry))
      store.removeSelection()
      selectBlock(fallback)
    } else if (mod && event.key.toLowerCase() === 'd') {
      event.preventDefault(); store.duplicateSelection()
    }
  }
  const onLoad = () => {
    const d = frame.current?.contentDocument
    if (!d || !token.current) return
    if (!d.querySelector('[data-cms-editor-root]')) { setLoading(false); setError(t('This template has no content outlet. Choose a template that includes the content field.')); return }
    d.documentElement.setAttribute('data-cms-bridge-ready', '')
    const style = d.createElement('style'); style.dataset.cmsBridge = ''; style.textContent = bridgeCss; d.head.appendChild(style)
    setDoc(d); collectSlots(d); setLoading(false)
    d.addEventListener('submit', (event) => event.preventDefault(), true)
    d.addEventListener('dragstart', (event) => event.preventDefault())
    d.addEventListener('focusout', (event) => { if ((event.target as HTMLElement).isContentEditable) setWriting(false); scheduleRender() })
    d.addEventListener('focusin', (event) => {
      const active = event.target as HTMLElement
      if (!active.isContentEditable) return
      setWriting(true)
      const source = active.closest<HTMLElement>('[data-cms-instance-node],[data-cms-document][data-cms-node]')
      store.select(source?.dataset.cmsInstanceNode ?? source?.dataset.cmsNode ?? null)
      store.hover(null)
    })
    d.addEventListener('pointermove', (event) => {
      if (latest.current.readOnly) return
      if (typingRef.current && (event.movementX || event.movementY)) { typingRef.current = false; setTyping(false) }
      store.hover(ownerOf(event.target))
      if (typingRef.current || chooserRef.current) return
      const m = metrics()
      showGap(m && pickHoverTarget(m.blocks, { x: event.clientX, y: event.clientY }, { rootRect: m.rootRect, canInsert: choicesOnce() }))
    })
    // The inserter's outside-press cannot see presses inside the frame.
    d.addEventListener('pointerdown', () => { setChooser(null); showGap(null) })
    d.addEventListener('click', (event) => {
      const el = event.target as HTMLElement
      if (el.closest('[contenteditable="true"]')) { if (el.closest('a')) event.preventDefault(); return }
      event.preventDefault()
      const block = ownerOf(el)
      // Clicking never creates content: it selects the innermost source block, or clears the selection.
      if (!block) { if (!event.shiftKey) store.select(null); return }
      if (event.shiftKey && store.get().selected) store.extendTo(block)
      else latest.current.onInspect(block)
    }, true)
    d.addEventListener('keydown', (event) => {
      if (event.defaultPrevented) return
      const mod = event.metaKey || event.ctrlKey
      // The admin's own shortcuts work from the canvas too: keys here don't reach its window.
      if (mod && ['s', '.', 'j', 'k', '\\'].includes(event.key.toLowerCase())) {
        event.preventDefault(); document.dispatchEvent(new KeyboardEvent('keydown', { key: event.key, code: event.code, metaKey: event.metaKey, ctrlKey: event.ctrlKey, shiftKey: event.shiftKey, bubbles: true })); return
      }
      if (latest.current.readOnly) return
      if (mod && event.key.toLowerCase() === 'z') {
        if ((d.activeElement as HTMLElement | null)?.closest('[data-cms-entry-field]')) return
        event.preventDefault()
        const active = d.activeElement as HTMLElement | null
        const field = active?.closest<HTMLElement>('[data-cms-prop]')
        const instance = active?.closest<HTMLElement>('[data-cms-instance-prop]')
        const node = field?.closest<HTMLElement>('[data-cms-node]')
        active?.blur(); event.shiftKey ? store.redo() : store.undo()
        if (node?.dataset.cmsNode && field?.dataset.cmsProp && tree.find(store.get().nodes, node.dataset.cmsNode)) store.focus(node.dataset.cmsNode, 'end', field.dataset.cmsProp, field.dataset.cmsIndex === undefined ? undefined : Number(field.dataset.cmsIndex))
        else if (instance?.dataset.cmsInstanceNode && tree.find(store.get().nodes, instance.dataset.cmsInstanceNode)) store.focus(instance.dataset.cmsInstanceNode, 'end', `values.${instance.dataset.cmsInstanceProp}`)
        return
      }
      // ⌥⌘T / ⌥⌘Y insert before / after, while writing too (codes: ⌥ changes the character on macOS).
      if (mod && event.altKey && (event.code === 'KeyT' || event.code === 'KeyY')) { event.preventDefault(); openInserter(event.code === 'KeyT' ? 'before' : 'after'); return }
      if ((event.target as HTMLElement).isContentEditable) {
        if (!mod && !event.altKey && (event.key.length === 1 || ['Backspace', 'Delete', 'Enter'].includes(event.key))) { typingRef.current = true; setTyping(true); showGap(null) }
        return
      }
      blockKeys(event)
    })
    // Whole blocks on the clipboard while blocks (not text) are selected.
    const blocksSelected = (event: Event) => store.get().blockMode && !!store.get().selected && !(event.target as HTMLElement).isContentEditable
    const copy = (event: ClipboardEvent, cut: boolean) => {
      if (!blocksSelected(event) || !event.clipboardData) return
      const picked = selectionOf(store.get()).map((id) => tree.find(store.get().nodes, id)).filter(Boolean)
      if (!picked.length) return
      event.preventDefault(); event.clipboardData.setData('text/plain', 'cms-blocks:' + JSON.stringify(picked))
      if (cut && !latest.current.readOnly) store.removeSelection()
    }
    d.addEventListener('copy', (event) => copy(event, false))
    d.addEventListener('cut', (event) => copy(event, true))
    d.addEventListener('paste', (event) => {
      const text = event.clipboardData?.getData('text/plain') ?? ''
      if (!blocksSelected(event) || latest.current.readOnly || !text.startsWith('cms-blocks:')) return
      event.preventDefault()
      let pasted: BlockNode[]
      try { pasted = (JSON.parse(text.slice(12)) as BlockNode[]).map(tree.clone) } catch { return }
      const nodes = store.get().nodes, at = insertionPoint(nodes, selectionOf(store.get()).at(-1)!, pasted.map((node) => node.type), types, allowed)
      if (!at || !pasted.length) return
      store.insert(at.parentId, at.index, pasted)
      store.select(pasted[0].id, true)
      if (pasted.length > 1) store.extendTo(pasted.at(-1)!.id)
    })
    // Scroll-linked chrome commits inside the scroll event, before the frame paints: a rAF + async render lands a frame late.
    // Scrolling moves every block by the same delta, so the cached geometry shifts instead of being re-measured.
    let settle = 0
    d.addEventListener('scroll', () => {
      const g = geometry.current, win = d.defaultView!
      if (g) {
        const dx = win.scrollX - g.scroll.x, dy = win.scrollY - g.scroll.y
        const shift = (rect: Rect): Rect => ({ ...rect, left: rect.left - dx, top: rect.top - dy })
        geometry.current = { blocks: g.blocks.map((block) => ({ ...block, rect: shift(block.rect) })), rootRect: shift(g.rootRect), scroll: { x: win.scrollX, y: win.scrollY } }
      }
      flushSync(() => { showGap(null); measure() })
      // Sticky or fixed blocks do not move with the page: measure everything once scrolling settles.
      clearTimeout(settle); settle = window.setTimeout(() => { geometry.current = null; measure() }, 150)
    }, { passive: true })
    d.defaultView?.addEventListener('resize', () => { geometry.current = null; requestAnimationFrame(measure) })
    d.querySelectorAll('img,link[rel="stylesheet"]').forEach((el) => el.addEventListener('load', () => { geometry.current = null; requestAnimationFrame(measure) }, { once: true }))
    d.fonts.ready.then(() => { geometry.current = null; measure() })
  }
  useEffect(() => bus.on('scroll-to', ({ node }) => {
    const d = frame.current?.contentDocument, target = d && source(d, node)
    if (target) target.scrollIntoView({ block: 'center', behavior: 'smooth' })
  }), [])
  useEffect(() => {
    const d = frame.current?.contentDocument
    if (!d) return
    d.querySelectorAll('[data-cms-reveal]').forEach((el) => el.removeAttribute('data-cms-reveal'))
    for (const id of selectedIds) {
      let el: HTMLElement | null = source(d, id)
      while (el) { if (el.dataset.cmsConditional === 'hidden') el.dataset.cmsReveal = ''; el = el.parentElement }
    }
  }, [selectedIds])
  const blockDrag = useBlockDrag({
    disabled: readOnly,
    frame, scale: layout.scale,
    invalidate: () => { geometry.current = null; measure() },
    target: (point, ids) => {
      const m = metrics()
      if (!m) return null
      if (point.x < m.rootRect.left || point.x > m.rootRect.left + m.rootRect.width || point.y < m.rootRect.top - 12 || point.y > m.rootRect.top + m.rootRect.height + 12) return null
      const sourceNodes = ids.map((id) => tree.find(store.get().nodes, id))
      if (sourceNodes.some((node) => !node)) return null
      const names = sourceNodes.map((node) => node!.type)
      return pickDropTarget(m.blocks, point, { rootRect: m.rootRect, maxDistance: Infinity, excludeIds: new Set(ids), canInsert: (parent) => permits(parent, names) })
    },
    commit: (ids, target) => store.moveTo(ids, target.parentId, target.index),
  })
  /** Whether a place over the canvas (workspace coordinates) has the page's content under it: text, media, controls. */
  const covers = (rect: Rect) => {
    const d = frame.current?.contentDocument
    if (!d) return false
    for (const fy of [0.25, 0.75]) for (let i = 0; i < 6; i++) {
      const x = (rect.left + (rect.width * (i + 0.5)) / 6 - layout.left) / layout.scale, y = (rect.top + rect.height * fy) / layout.scale
      if (contentAt(d, x, y)) return true
    }
    return false
  }
  const frameOffset = useCallback(() => { const rect = frame.current!.getBoundingClientRect(); return { x: rect.left, y: rect.top, scale: layout.scale } }, [layout.scale])
  const boxStyle = (rect: Rect) => ({ left: layout.left + rect.left * layout.scale, top: rect.top * layout.scale, width: rect.width * layout.scale, height: rect.height * layout.scale })
  return <WriteContext.Provider value={api}>
    <div className="flex h-full min-h-0 flex-col" data-live-editor>
      {error && <QueryError compact message={error} onRetry={() => setRetry((value) => value + 1)} />}
      <div ref={viewport} onPointerLeave={() => { store.hover(null); showGap(null) }}
        // Outside the frame (its gutter, the toolbar) only the + under the pointer keeps its boundary.
        onPointerMove={(event) => { if (!(event.target as Element).closest('[data-cms-inserter]')) { showGap(null); store.hover(null) } }}
        className="relative min-h-0 flex-1 overflow-hidden bg-sunken">
        {loading && <div className="absolute inset-0 z-20 grid place-items-center bg-bg"><Spinner /></div>}
        <iframe ref={frame} title={t('Live editor')} sandbox="allow-same-origin" onLoad={onLoad} className="absolute top-0 border-0 bg-white" style={{ width: layout.width, height: layout.height, left: layout.left, transformOrigin: 'top left', transform: `scale(${layout.scale})` }} />
        <div className="pointer-events-none absolute inset-0 overflow-hidden">
          {!readOnly && !blockDrag.drag && !writing && !typing && !chooser && hovered && hoverBox && !selectedIds.includes(hovered) && <div data-cms-hover={hovered} className="absolute z-10 border border-focus/50" style={boxStyle(hoverBox)} />}
          {/* The selection shows while writing too; its toolbar waits for block selection (Esc). */}
          {!blockDrag.drag && boxes.map((rect, index) => <div data-cms-selection key={index} className="absolute z-10 border-solid border-focus" style={{ ...boxStyle(rect), borderWidth: 1.5 }} />)}
          {!readOnly && !blockDrag.drag && blockMode && selected && boxes[0] && (() => {
            const first = tree.locate(nodes, selectedIds[0]), last = tree.locate(nodes, selectedIds.at(-1)!)
            const node = tree.find(nodes, selected)
            if (!first || !last || !node) return null
            const siblings = tree.siblings(nodes, first.parentId)
            const describe = (block: BlockNode) => ({ label: blockName(block, types, components), icon: block.type === 'pattern' ? 'b-diamonds-four' : types.get(block.type)?.icon ?? 'square' })
            const parent = first.parentId ? tree.find(nodes, first.parentId) : null
            const { label, icon } = describe(node)
            return <BlockToolbar id={selected} label={selectedIds.length > 1 ? plural(selectedIds.length, '{count} block', '{count} blocks') : label} icon={icon} parent={parent ? describe(parent) : null}
              box={boxStyle(boxes[0])} viewport={{ width: layout.width * layout.scale + layout.left * 2, height: layout.height * layout.scale }}
              canMoveUp={first.index > 0} canMoveDown={last.index < siblings.length - 1}
              onSelectParent={() => parent && onInspect(parent.id)} onDrag={(event) => { setChooser(null); blockDrag.start(event, selectionOf(store.get()), label) }}
              onMove={(direction) => store.nudgeSelection(direction)} onInsert={(side) => requestAnimationFrame(() => openInserter(side))}
              onDuplicate={() => store.duplicateSelection()} onDelete={() => store.removeSelection()}
              onMakeComponent={node.type !== 'pattern' && can('entries.components.create') ? () => savePattern(store, patternFits(store, types, allowed)) : undefined}
              covers={covers} />
          })()}
          {!readOnly && !blockDrag.drag && gaps.filter((at) => at.inside).map((at) => {
            const picker = { open: !!chooser && targetKey(chooser) === targetKey(at), onOpenChange: (open: boolean) => setChooser(open ? at : null), returnFocus,
              children: <QuickInserter types={[...types.values()]} allowed={(type) => permits(at.parentId, [type.name])} onChoose={(choice) => choose(choice, at)} onClose={() => setChooser(null)} /> }
            if (at.parentId === null) return nodes.length ? null : <DocumentAppender key="root" target={at} position={boxStyle} onWrite={permits(null) ? startWriting : undefined} {...picker} />
            // Empty boxes are often decoration (dots, frames): their + appears only when you point at or select them.
            const top = at.hitRect.top, parent = tree.find(nodes, at.parentId)
            if (parent?.children?.length || top + at.hitRect.height < 0 || top > layout.height || !(picker.open || hovered === at.parentId || selectedIds.includes(at.parentId))) return null
            return <ContainerAppender key={targetKey(at)} target={at} position={boxStyle} {...picker} />
          })}
          {!readOnly && !blockDrag.drag && (() => {
            const at = chooser && !chooser.inside ? chooser : !chooser && !typing ? hoverGap : null
            if (!at) return null
            return <BoundaryInserter key={targetKey(at)} target={at} position={boxStyle} open={chooser === at} onOpenChange={(open) => { setChooser(open ? at : null); if (!open) showGap(null) }} returnFocus={returnFocus}>
              <QuickInserter types={[...types.values()]} allowed={(type) => permits(at.parentId, [type.name])} onChoose={(choice) => choose(choice, at)} onClose={() => setChooser(null)} />
            </BoundaryInserter>
          })()}
          {blockDrag.drag && <LiveDragPreview drag={blockDrag.drag} position={boxStyle} />}
        </div>

      </div>
      {doc && <>
        <LiveSlots slots={slots} />
        {onTitleChange && <LiveTitle doc={doc} content={content} onChange={onTitleChange} readOnly={readOnly} />}
        <FormatToolbar root={doc} offset={frameOffset} />
        <LiveSlash doc={doc} frame={frame.current!} scale={layout.scale} />
      </>}

    </div>
  </WriteContext.Provider>
}

const LiveSlots = memo(function LiveSlots({ slots }: { slots: Slot[] }) {
  const { store } = useEditorContext()
  const nodes = useEditor(store, (state) => state.nodes)
  const listIds = new Set(slots.filter((slot) => !slot.instance && slot.prop === 'items').map((slot) => slot.id))
  const occurrences = new Map<string, number>()
  return <>{slots.filter((slot) => slot.instance || slot.prop !== 'items').map((slot) => {
    const node = tree.find(nodes, slot.id)
    if (!node) return null
    const key = `${slot.instance ? 'instance:' : ''}${slot.id}:${slot.prop}`
    const occurrence = occurrences.get(key) ?? 0
    occurrences.set(key, occurrence + 1)
    return createPortal(slot.instance ? <InstanceSlot node={node} slot={slot} /> : ['text', 'cite', 'caption'].includes(slot.prop)
      // An empty paragraph is a blank line until you write in it; other empty text keeps its label.
      ? node.type === 'paragraph' && slot.prop === 'text'
        ? <EditableText node={node} prop="text" tag="span" placeholder={t('Write, or press “/” for blocks')} quiet />
        : <EditableText node={node} prop={slot.prop} tag="span" placeholder={slot.element.dataset.cmsPlaceholder ?? t('Write something…')} />
      : <LiteralSlot node={node} prop={slot.prop} />, slot.element, `${key}:${occurrence}`)
  })}{[...listIds].map((id) => {
    const node = tree.find(nodes, id)
    return node ? <ListBlock key={id} node={node} renderItem={(index, editable) => { const slot = slots.find((item) => item.id === id && item.prop === 'items' && item.index === index); return slot ? createPortal(editable, slot.element, `${id}:items:${index}`) : null }} /> : null
  })}</>
})
function InstanceSlot({ node, slot }: { node: BlockNode; slot: Slot }) {
  const { store, readOnly } = useEditorContext()
  const focus = useEditor(store, (state) => state.focus?.id === node.id && state.focus.prop === `values.${slot.prop}` ? state.focus : null)
  const value = String((Object.prototype.hasOwnProperty.call(node.props.values ?? {}, slot.prop) ? node.props.values[slot.prop] : slot.instance!.value) ?? '')
  const html = value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>')
  return <InlineEditable tag="span" html={html} focus={focus} plainText singleLine={slot.instance!.type === 'text'} disabled={readOnly} placeholder={slot.element.dataset.cmsPlaceholder ?? t('Write something…')}
    onFocus={() => store.select(node.id)} onChange={(_, element) => store.updateProps(node.id, { values: { ...tree.find(store.get().nodes, node.id)?.props.values, [slot.prop]: element.innerText } }, `instance:${node.id}:${slot.prop}`)}
    onKeyDown={(event, element) => { if (event.key === 'Escape' || (event.key === 'Enter' && slot.instance!.type === 'text')) { event.preventDefault(); element.blur(); store.select(node.id, true) } }} />
}
function LiteralSlot({ node, prop }: { node: BlockNode; prop: string }) {
  const { store, readOnly } = useEditorContext()
  const focus = useEditor(store, (state) => state.focus?.id === node.id && state.focus.prop === prop ? state.focus : null)
  const value = String(node.props[prop] ?? '')
  const html = prop === 'code' ? value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>') : value
  return <InlineEditable tag="span" html={html} disabled={readOnly} focus={focus} singleLine={prop !== 'code'} onChange={(next, el) => store.updateProps(node.id, { [prop]: prop === 'code' ? el.innerText : next }, `text:${node.id}:${prop}`)} onFocus={() => store.select(node.id)} onKeyDown={(event, el) => { if (event.key === 'Escape' || (prop !== 'code' && event.key === 'Enter')) { event.preventDefault(); el.blur(); store.select(node.id, true) } }} />
}
function LiveSlash({ doc, frame, scale }: { doc: Document; frame: HTMLIFrameElement; scale: number }) {
  const slash = useSlash()
  const { store, types, allowed } = useEditorContext()
  const [rect, setRect] = useState<DOMRect | null>(null)
  useLayoutEffect(() => { setRect(slash ? caretRect(doc) : null) }, [slash, doc])
  useSlashDismiss(!!slash, [document, doc])
  if (!slash || !rect || !tree.find(store.get().nodes, slash.id)) return null
  const choose = (choice: InsertChoice) => {
    const created = choiceToNodes(choice, types), nodes = store.get().nodes, loc = tree.locate(nodes, slash.id)
    if (!loc || !created.length) return
    const parent = loc.parentId ? tree.find(nodes, loc.parentId) : null
    const at = created.every((node) => accepts(parent, node.type, types, parent ? [] : allowed)) ? loc : insertionPoint(nodes, slash.id, created.map((node) => node.type), types, allowed)
    if (!at) return
    openSlash(null); store.commit(tree.insert(tree.remove(nodes, slash.id), at.parentId, at.index, created))
    focusFirst(store, created[0], documentStops(outline(store.get().nodes, types, () => false)))
  }
  const origin = frame.getBoundingClientRect()
  const { maxHeight, ...place } = slashPlacement({ left: origin.left + rect.left * scale, top: origin.top + rect.top * scale, bottom: origin.top + rect.bottom * scale })
  return createPortal(<div data-cms-slash className="cms-pop fixed z-[70] overflow-hidden !p-0" style={place}><Inserter types={[...types.values()]} query={slash.query} showSearch={false} compact style={{ maxHeight }} chooseFirst={slash.enter} keyboardDocument={doc} onChoose={choose} onClose={() => openSlash(null)} onNoMatch={() => { openSlash(null) }} allowed={(type) => !!insertionPoint(store.get().nodes, slash.id, type.name, types, allowed)} /></div>, document.body)
}

function LiveTitle({ doc, content, onChange, readOnly }: { doc: Document; content: EntryContent; onChange: (title: string) => void; readOnly: boolean }) {
  const targets = [...doc.querySelectorAll<HTMLElement>('[data-cms-entry-field="title"]')]
  return <>{targets.map((target, index) => {
    const html = content.title.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    return createPortal(<InlineEditable tag="span" html={html} singleLine disabled={readOnly} placeholder={t('Untitled')} onChange={(_, element) => onChange(element.textContent ?? '')} onKeyDown={(event, element) => { if (event.key === 'Enter') { event.preventDefault(); element.blur(); const next = doc.querySelector<HTMLElement>('[data-cms-editor-root] [contenteditable="true"]'); next?.focus() } }} />, target, `title:${index}`)
  })}</>
}
