import { useRef, useSyncExternalStore } from 'react'
import * as tree from './tree'
import type { BlockNode, FocusRequest } from './types'

export interface State {
  nodes: BlockNode[]
  /** Selected block (inspector + document outline). */
  selected: string | null
  /** Other end of a run of selected siblings (Shift+↑↓, Shift+click), or null for a single block. */
  head: string | null
  /** Block-selection mode (Esc): keyboard acts on blocks, not text. */
  blockMode: boolean
  focus: FocusRequest | null
  breakpoint: 'base' | 'tablet' | 'mobile'
  /** Style state being edited in the Design panel: '' (default), hover, focus-visible, active, is-open… */
  state: string
  hovered: string | null
}

type Listener = () => void

/**
 * One block editor state: the block tree,
 * selection, caret requests and undo/redo history (typing is coalesced).
 */
export class EditorStore {
  private state: State
  private listeners = new Set<Listener>()
  private past: BlockNode[][] = []
  private future: BlockNode[][] = []
  private lastCommit = 0
  private lastGroup = ''

  constructor(nodes: BlockNode[], private onChange: (nodes: BlockNode[]) => void, private readOnly = false) {
    this.state = { nodes, selected: null, head: null, blockMode: false, focus: null, breakpoint: 'base', state: '', hovered: null }
  }

  setReadOnly(readOnly: boolean) { this.readOnly = readOnly }

  get = () => this.state

  subscribe = (l: Listener) => {
    this.listeners.add(l)
    return () => this.listeners.delete(l)
  }

  private set(patch: Partial<State>) {
    this.state = { ...this.state, ...patch }
    this.listeners.forEach((l) => l())
  }

  /** Replace the tree from outside (e.g. restoring a revision) without touching history. */
  reset(nodes: BlockNode[]) {
    if (nodes === this.state.nodes) return
    this.set({ nodes })
  }

  /**
   * Change the tree. `group` coalesces rapid edits (typing in one block) into
   * one undo step.
   */
  commit(next: BlockNode[], group = '') {
    if (this.readOnly) return
    const now = Date.now()
    if (!(group && group === this.lastGroup && now - this.lastCommit < 800)) {
      this.past.push(this.state.nodes)
      if (this.past.length > 200) this.past.shift()
    }
    this.future = []
    this.lastCommit = now
    this.lastGroup = group
    this.set({ nodes: next })
    this.onChange(next)
  }

  undo = () => {
    if (this.readOnly) return
    const prev = this.past.pop()
    if (!prev) return
    this.future.push(this.state.nodes)
    this.lastGroup = ''
    this.set({ nodes: prev })
    this.onChange(prev)
  }

  redo = () => {
    if (this.readOnly) return
    const next = this.future.pop()
    if (!next) return
    this.past.push(this.state.nodes)
    this.lastGroup = ''
    this.set({ nodes: next })
    this.onChange(next)
  }

  canUndo = () => !this.readOnly && this.past.length > 0
  canRedo = () => !this.readOnly && this.future.length > 0

  select = (id: string | null, blockMode = false) => this.set({ selected: id, head: null, blockMode })

  /** Ids of the selected blocks in document order: one block, or a run of siblings. */
  selection = (): string[] => selectionOf(this.state)

  /** Extend the selection to a sibling of the selected block (anything else starts a new selection). */
  extendTo(id: string) {
    const { selected, nodes, blockMode } = this.state
    const from = selected ? tree.locate(nodes, selected) : null
    const to = tree.locate(nodes, id)
    if (!selected || !from || !to || from.parentId !== to.parentId) return this.select(id, blockMode)
    this.set({ head: id === selected ? null : id })
  }

  /** Shift+↑/↓: grow or shrink the run of selected siblings. */
  extend(delta: -1 | 1) {
    const { selected, head, nodes } = this.state
    const loc = selected ? tree.locate(nodes, selected) : null
    if (!selected || !loc) return
    const siblings = tree.siblings(nodes, loc.parentId)
    const at = siblings.findIndex((n) => n.id === (head ?? selected)) + delta
    if (at >= 0 && at < siblings.length) this.extendTo(siblings[at].id)
  }

  /** Remove every selected block. */
  removeSelection() {
    const ids = this.selection()
    this.commit(ids.reduce((doc, id) => tree.remove(doc, id), this.state.nodes))
    this.set({ selected: null, head: null })
  }

  /** Put `replacement` where the selected blocks were (e.g. a synced pattern reference). */
  replaceSelection(replacement: BlockNode[]) {
    const ids = this.selection()
    const loc = ids[0] ? tree.locate(this.state.nodes, ids[0]) : null
    if (!loc) return
    const without = ids.reduce((doc, id) => tree.remove(doc, id), this.state.nodes)
    this.commit(tree.insert(without, loc.parentId, loc.index, replacement))
    this.set({ selected: replacement[0]?.id ?? null, head: null })
  }
  hover = (id: string | null) => this.state.hovered !== id && this.set({ hovered: id })
  setBreakpoint = (breakpoint: State['breakpoint']) => this.set({ breakpoint })
  setStyleState = (state: string) => this.set({ state })

  /** The style variant key the Design panel writes to: "base", "tablet", "hover", "mobile:hover"… */
  variant = (): string => variantKey(this.state.breakpoint, this.state.state)

  focus(id: string, at: FocusRequest['at'] = 'end', prop?: string, index?: number) {
    this.set({ focus: { id, at, prop, index, nonce: Math.random() }, selected: id, blockMode: false })
  }

  clearFocus() {
    if (this.state.focus) this.state = { ...this.state, focus: null }
  }

  // Tree operations

  updateProps(id: string, props: Record<string, any>, group = '') {
    this.commit(tree.update(this.state.nodes, id, (n) => ({ ...n, props: { ...n.props, ...props } })), group || `props:${id}`)
  }

  updateNode(id: string, patch: Partial<BlockNode>, group = '') {
    this.commit(tree.update(this.state.nodes, id, (n) => ({ ...n, ...patch })), group)
  }

  insert(parentId: string | null, index: number, nodes: BlockNode[]) {
    this.commit(tree.insert(this.state.nodes, parentId, index, nodes))
  }

  insertAfter(id: string | null, nodes: BlockNode[]) {
    if (!id) return this.insert(null, this.state.nodes.length, nodes)
    const loc = tree.locate(this.state.nodes, id)
    if (!loc) return this.insert(null, this.state.nodes.length, nodes)
    this.insert(loc.parentId, loc.index + 1, nodes)
  }

  remove(id: string) {
    this.commit(tree.remove(this.state.nodes, id))
    if (this.state.selected === id) this.set({ selected: null, head: null })
  }

  move(id: string, parentId: string | null, index: number) {
    this.commit(tree.move(this.state.nodes, id, parentId, index))
  }

  /**
   * Move blocks (in document order) to `index` among `parentId`'s current
   * children — the index counts the moved blocks where they are now.
   */
  moveTo(ids: string[], parentId: string | null, index: number) {
    const doc = this.state.nodes
    if (parentId && ids.some((id) => id === parentId || tree.ancestors(doc, parentId).some((a) => a.id === id))) return
    const moving = ids.map((id) => tree.find(doc, id)).filter((n): n is BlockNode => !!n)
    const before = ids.filter((id) => {
      const loc = tree.locate(doc, id)
      return loc && loc.parentId === parentId && loc.index < index
    }).length
    const next = tree.insert(ids.reduce((d, id) => tree.remove(d, id), doc), parentId, index - before, moving)
    if (JSON.stringify(next) !== JSON.stringify(doc)) this.commit(next)
  }

  /** Move up/down among siblings. */
  nudge(id: string, delta: -1 | 1) {
    const loc = tree.locate(this.state.nodes, id)
    if (!loc) return
    const count = tree.siblings(this.state.nodes, loc.parentId).length
    const target = loc.index + delta
    if (target < 0 || target >= count) return
    this.move(id, loc.parentId, delta > 0 ? target + 1 : target)
  }

  /** Move the selected sibling range as one operation, preserving order and undo. */
  nudgeSelection(delta: -1 | 1) {
    const ids = this.selection()
    const first = ids[0] ? tree.locate(this.state.nodes, ids[0]) : null
    const last = ids.length ? tree.locate(this.state.nodes, ids.at(-1)!) : null
    if (!first || !last || first.parentId !== last.parentId) return
    const count = tree.siblings(this.state.nodes, first.parentId).length
    if ((delta < 0 && first.index === 0) || (delta > 0 && last.index === count - 1)) return
    this.moveTo(ids, first.parentId, delta < 0 ? first.index - 1 : last.index + 2)
  }

  duplicate(id: string) {
    const node = tree.find(this.state.nodes, id)
    if (!node) return
    const copy = tree.clone(node)
    this.insertAfter(id, [copy])
    this.select(copy.id)
  }

  /** Copies of the selected sibling run, right after it; the copies become the selection. */
  duplicateSelection() {
    const ids = this.selection(), doc = this.state.nodes
    const copies = ids.map((id) => tree.find(doc, id)).filter((n): n is BlockNode => !!n).map(tree.clone)
    const loc = ids.length ? tree.locate(doc, ids.at(-1)!) : null
    if (!loc || !copies.length) return
    this.insert(loc.parentId, loc.index + 1, copies)
    this.select(copies[0].id, true)
    if (copies.length > 1) this.extendTo(copies.at(-1)!.id)
  }

  /** Wrap a block in a new container node. */
  wrap(id: string, container: BlockNode) {
    const node = tree.find(this.state.nodes, id)
    const loc = tree.locate(this.state.nodes, id)
    if (!node || !loc) return
    const wrapped = { ...container, children: [node] }
    this.commit(tree.insert(tree.remove(this.state.nodes, id), loc.parentId, loc.index, [wrapped]))
    this.select(wrapped.id)
  }

  /** Replace a container with its children. */
  unwrap(id: string) {
    const node = tree.find(this.state.nodes, id)
    const loc = tree.locate(this.state.nodes, id)
    if (!node || !loc || !node.children) return
    this.commit(tree.insert(tree.remove(this.state.nodes, id), loc.parentId, loc.index, node.children))
  }
}

export function variantKey(breakpoint: string, state: string): string {
  if (breakpoint === 'base') return state || 'base'
  return state ? `${breakpoint}:${state}` : breakpoint
}

/** Variants a value is inherited through, most specific first (desktop-first breakpoints). */
export function variantChain(breakpoint: string, state: string): string[] {
  const bps = breakpoint === 'mobile' ? ['mobile', 'tablet', 'base'] : breakpoint === 'tablet' ? ['tablet', 'base'] : ['base']
  const chain: string[] = []
  if (state) for (const bp of bps) chain.push(variantKey(bp, state))
  chain.push(...bps)
  return chain
}

/** The selected run of siblings, in document order. */
export function selectionOf(s: Pick<State, 'nodes' | 'selected' | 'head'>): string[] {
  if (!s.selected) return []
  if (!s.head) return [s.selected]
  const loc = tree.locate(s.nodes, s.selected)
  if (!loc) return [s.selected]
  const siblings = tree.siblings(s.nodes, loc.parentId)
  const a = siblings.findIndex((n) => n.id === s.selected)
  const b = siblings.findIndex((n) => n.id === s.head)
  if (b < 0) return [s.selected]
  return siblings.slice(Math.min(a, b), Math.max(a, b) + 1).map((n) => n.id)
}

function shallowEqual(a: unknown, b: unknown): boolean {
  if (Object.is(a, b)) return true
  if (Array.isArray(a) && Array.isArray(b)) return a.length === b.length && a.every((v, i) => Object.is(v, b[i]))
  if (a && b && typeof a === 'object' && typeof b === 'object') {
    const ka = Object.keys(a)
    const kb = Object.keys(b)
    return ka.length === kb.length && ka.every((k) => Object.is((a as any)[k], (b as any)[k]))
  }
  return false
}

/** Subscribe to a slice of editor state. Results are shallow-compared so derived arrays stay stable. */
export function useEditor<T>(store: EditorStore, selector: (s: State) => T): T {
  const cache = useRef<{ value: T } | null>(null)
  return useSyncExternalStore(store.subscribe, () => {
    const next = selector(store.get())
    if (cache.current && shallowEqual(cache.current.value, next)) return cache.current.value
    cache.current = { value: next }
    return next
  })
}
