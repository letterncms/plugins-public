import { newId, type BlockNode, type BlockTypeDef } from './types'

/** Immutable helpers over the block tree. Every function returns a new array. */

export interface Location {
  parentId: string | null
  index: number
}

export function find(nodes: BlockNode[], id: string): BlockNode | null {
  for (const n of nodes) {
    if (n.id === id) return n
    if (n.children) {
      const found = find(n.children, id)
      if (found) return found
    }
  }
  return null
}

export function locate(nodes: BlockNode[], id: string, parentId: string | null = null): Location | null {
  for (let i = 0; i < nodes.length; i++) {
    if (nodes[i].id === id) return { parentId, index: i }
    const children = nodes[i].children
    if (children) {
      const found = locate(children, id, nodes[i].id)
      if (found) return found
    }
  }
  return null
}

export function siblings(nodes: BlockNode[], parentId: string | null): BlockNode[] {
  return parentId === null ? nodes : (find(nodes, parentId)?.children ?? [])
}

export function parentOf(nodes: BlockNode[], id: string): BlockNode | null {
  const loc = locate(nodes, id)
  return loc?.parentId ? find(nodes, loc.parentId) : null
}

export function ancestors(nodes: BlockNode[], id: string): BlockNode[] {
  const out: BlockNode[] = []
  let loc = locate(nodes, id)
  while (loc?.parentId) {
    const parent = find(nodes, loc.parentId)
    if (!parent) break
    out.unshift(parent)
    loc = locate(nodes, parent.id)
  }
  return out
}

export function update(nodes: BlockNode[], id: string, fn: (n: BlockNode) => BlockNode): BlockNode[] {
  return nodes.map((n) => (n.id === id ? fn(n) : n.children ? { ...n, children: update(n.children, id, fn) } : n))
}

export function remove(nodes: BlockNode[], id: string): BlockNode[] {
  return nodes.filter((n) => n.id !== id).map((n) => (n.children ? { ...n, children: remove(n.children, id) } : n))
}

export function insert(nodes: BlockNode[], parentId: string | null, index: number, items: BlockNode[]): BlockNode[] {
  if (parentId === null) {
    const copy = nodes.slice()
    copy.splice(Math.max(0, Math.min(index, copy.length)), 0, ...items)
    return copy
  }
  return update(nodes, parentId, (p) => {
    const children = (p.children ?? []).slice()
    children.splice(Math.max(0, Math.min(index, children.length)), 0, ...items)
    return { ...p, children }
  })
}

export function move(nodes: BlockNode[], id: string, parentId: string | null, index: number): BlockNode[] {
  const node = find(nodes, id)
  const from = locate(nodes, id)
  if (!node || !from) return nodes
  // Cannot move into own subtree.
  if (parentId && (parentId === id || find(node.children ?? [], parentId))) return nodes
  const adjusted = from.parentId === parentId && from.index < index ? index - 1 : index
  return insert(remove(nodes, id), parentId, adjusted, [node])
}

/** Deep copy with fresh ids. */
export function clone(node: BlockNode): BlockNode {
  return { ...structuredClone(node), id: newId(), children: node.children?.map(clone) }
}

/** Depth-first list of all nodes with depth (for layers & keyboard navigation). */
export function flatten(nodes: BlockNode[], depth = 0, out: { node: BlockNode; depth: number; parentId: string | null }[] = [], parentId: string | null = null) {
  for (const n of nodes) {
    out.push({ node: n, depth, parentId })
    if (n.children) flatten(n.children, depth + 1, out, n.id)
  }
  return out
}

/** Build a node of a type with defaults, prop overrides and template children. */
export function create(type: BlockTypeDef, props: Record<string, any> = {}, catalog?: Map<string, BlockTypeDef>): BlockNode {
  const defaults: Record<string, any> = { ...(type.defaults ?? {}) }
  for (const f of type.fields ?? []) if (f.default !== undefined && defaults[f.handle] === undefined) defaults[f.handle] = f.default
  const node: BlockNode = { id: newId(), type: type.name, props: { ...defaults, ...props } }
  if (type.children) {
    node.children = (type.template ?? []).map((t) => fromTemplate(t, catalog))
  }
  return node
}

function fromTemplate(t: Partial<BlockNode>, catalog?: Map<string, BlockTypeDef>): BlockNode {
  const def = catalog?.get(t.type ?? '')
  const base = def ? create(def, {}, catalog) : { id: newId(), type: t.type ?? 'paragraph', props: {} }
  return {
    ...base,
    props: { ...base.props, ...(t.props ?? {}) },
    style: t.style,
    children: t.children ? t.children.map((c) => fromTemplate(c, catalog)) : base.children,
  }
}

/** Does this text prop (inline HTML) have visible content? */
export function isEmptyHtml(html: unknown): boolean {
  if (typeof html !== 'string') return true
  return html.replace(/<br\s*\/?>/g, '').replace(/&nbsp;/g, ' ').replace(/<[^>]+>/g, '').trim() === ''
}

/** Plain text of a node for labels. */
export function summary(node: BlockNode, max = 60): string {
  const p = node.props ?? {}
  // The first of these that says something: an image without a caption is named by its alt text.
  const clean = (raw: unknown) => (typeof raw === 'string' ? raw : '').replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').trim()
  const text = [p.text, p.label, p.title, p.question, Array.isArray(p.items) ? p.items[0] : '', p.alt, p.url, p.collection, p.ref].map(clean).find(Boolean) ?? ''
  return text.length > max ? text.slice(0, max - 1) + '…' : text
}
