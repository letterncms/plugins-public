import type { BlockNode, BlockTypeDef } from '../core/types'

/**
 * An editable flow over the block tree. Containers remain selectable rows and
 * keep their children in source order; every row points at the original nodes.
 * Inline text, lists, actions and columns have specialized views. Other blocks
 * use their registered view or the shared inspector, without losing content.
 */
export type Row =
  | { kind: 'text'; node: BlockNode; parent: string | null; hidden: boolean }
  | { kind: 'list'; node: BlockNode; parent: string | null; hidden: boolean }
  | { kind: 'actions'; nodes: BlockNode[]; parent: string | null; hidden: boolean }
  | { kind: 'columns'; node: BlockNode; columns: { node: BlockNode; rows: Row[] }[]; parent: string | null; hidden: boolean }
  | { kind: 'container'; node: BlockNode; rows: Row[]; parent: string | null; hidden: boolean }
  | { kind: 'block'; node: BlockNode; parent: string | null; hidden: boolean }

export interface Section {
  id: string
  /** The label of a top-level container, when one was provided. */
  label: string | null
  rows: Row[]
  container: boolean
}

/** A place the caret can be: a text prop, or one list item. */
export interface Stop {
  id: string
  prop: string
  index?: number
  parent: string | null
}

export const TEXT_TYPES = ['paragraph', 'heading', 'quote', 'callout']

interface Ctx {
  types: Map<string, BlockTypeDef>
  /** Has a plugin view of its own (e.g. form fields). */
  custom: (type: string) => boolean
}

export function rowNodes(row: Row): BlockNode[] {
  return row.kind === 'actions' ? row.nodes : [row.node]
}

export function rowId(row: Row): string {
  return rowNodes(row)[0].id
}

export function outline(nodes: BlockNode[], types: Map<string, BlockTypeDef>, custom: (type: string) => boolean): Section[] {
  const ctx: Ctx = { types, custom }
  const sections: Section[] = []
  for (const node of nodes) {
    const row = rowFor(node, null, false, ctx)
    const container = row.kind === 'container' || row.kind === 'columns'
    const last = sections.at(-1)
    // Loose top-level content shares a section; containers keep their own boundary.
    if (!container && last && !last.container) append(last.rows, row)
    else sections.push({ id: node.id, label: container ? node.label?.trim() || null : null, rows: [row], container })
  }
  return sections
}

/** Caret stops in reading order, through every nested layout. */
export function stops(sections: Section[]): Stop[] {
  const out: Stop[] = []
  const walk = (rows: Row[]) => {
    for (const row of rows) {
      if (row.kind === 'text') {
        out.push({ id: row.node.id, prop: 'text', parent: row.parent })
        if (row.node.type === 'quote') out.push({ id: row.node.id, prop: 'cite', parent: row.parent })
      } else if (row.kind === 'list') {
        const items: unknown[] = Array.isArray(row.node.props.items) && row.node.props.items.length ? row.node.props.items : ['']
        items.forEach((_, index) => out.push({ id: row.node.id, prop: 'items', index, parent: row.parent }))
      } else if (row.kind === 'columns') {
        for (const column of row.columns) walk(column.rows)
      } else if (row.kind === 'container') {
        walk(row.rows)
      }
    }
  }
  for (const section of sections) walk(section.rows)
  return out
}

/** Selectable rows in depth-first reading order, including their containers. */
export function allRows(sections: Section[]): Row[] {
  const out: Row[] = []
  const walk = (rows: Row[]) => {
    for (const row of rows) {
      out.push(row)
      if (row.kind === 'columns') for (const column of row.columns) walk(column.rows)
      else if (row.kind === 'container') walk(row.rows)
    }
  }
  for (const section of sections) walk(section.rows)
  return out
}

function rowFor(node: BlockNode, parent: string | null, inheritedHidden: boolean, ctx: Ctx): Row {
  const hidden = inheritedHidden || !!node.hidden
  const base = { parent, hidden }
  const type = ctx.types.get(node.type)
  const custom = ctx.custom(node.type)
  const hasChildren = (node.children?.length ?? 0) > 0

  if (node.type === 'columns' && type && !custom) {
    const columns = (node.children ?? []).map((column) => ({ node: column, rows: rowsOf(column.children ?? [], column.id, hidden || !!column.hidden, ctx) }))
    return { kind: 'columns', node, columns, ...base }
  }
  // Leaf links keep their inline action view. A link wrapping blocks retains its tree.
  if (!custom && type && !hasChildren && (node.type === 'button' || node.type === 'link')) {
    return { kind: 'actions', nodes: [node], ...base }
  }
  // Existing children stay accessible even if their plugin is missing or changed its definition.
  if (type?.children || hasChildren) {
    return { kind: 'container', node, rows: rowsOf(node.children ?? [], node.id, hidden, ctx), ...base }
  }
  if (!type || custom) return { kind: 'block', node, ...base }
  if (TEXT_TYPES.includes(node.type)) return { kind: node.bind?.text ? 'block' : 'text', node, ...base }
  if (node.type === 'list') return { kind: node.bind?.items ? 'block' : 'list', node, ...base }
  return { kind: 'block', node, ...base }
}

function rowsOf(nodes: BlockNode[], parent: string | null, hidden: boolean, ctx: Ctx): Row[] {
  const out: Row[] = []
  for (const node of nodes) append(out, rowFor(node, parent, hidden, ctx))
  return out
}

/** Join neighboring leaf actions only when their parent and visibility agree. */
function append(out: Row[], row: Row) {
  const last = out.at(-1)
  if (row.kind === 'actions' && last?.kind === 'actions' && last.parent === row.parent && last.hidden === row.hidden) last.nodes.push(...row.nodes)
  else out.push(row)
}
