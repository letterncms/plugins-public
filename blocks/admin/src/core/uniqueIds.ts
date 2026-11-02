import type { BlockNode } from './types'

/**
 * Every block on the canvas is found by its id, and the server gives a block it
 * can't tell apart a new one. So a document written by hand with an id used twice
 * gets a new id here first; otherwise the canvas would take that block for one
 * that was removed. Untouched documents come back as they are.
 */
export function uniqueIds(nodes: BlockNode[], newId: () => string): BlockNode[] {
  const seen = new Set<string>()
  const walk = (list: BlockNode[]): BlockNode[] => {
    let changed = false
    const out = list.map((node) => {
      const id = seen.has(node.id) ? newId() : node.id
      seen.add(id)
      const children = node.children && walk(node.children)
      if (id === node.id && children === node.children) return node
      changed = true
      return { ...node, id, children }
    })
    return changed ? out : list
  }
  return walk(nodes)
}
