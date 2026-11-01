import type { InsertChoice } from './Inserter'
import type { EditorStore } from './store'
import * as tree from './tree'
import { newId, type BlockNode, type BlockTypeDef } from './types'

/** Where blocks may go, and what an insert choice becomes. Used by insertion, drag and the outline. */

/** Turn an insert choice into nodes (patterns: copies, or a reference when synced). */
export function choiceToNodes(choice: InsertChoice, types: Map<string, BlockTypeDef>): BlockNode[] {
  if (choice.kind === 'block') return [tree.create(choice.type, {}, types)]
  if (choice.pattern.synced) return [{ id: newId(), type: 'pattern', props: { ref: choice.pattern.handle } }]
  return choice.pattern.blocks.map((b) => tree.clone(b))
}

/** Can `type` live inside `parent` (null = top level)? */
export function accepts(parent: BlockNode | null, child: string, types: Map<string, BlockTypeDef>, allowed: string[] = []): boolean {
  const def = types.get(child)
  if (parent === null) return (allowed.length === 0 || allowed.includes(child)) && !def?.parent
  const p = types.get(parent.type)
  if (!p?.children || (def?.parent && def.parent !== parent.type)) return false
  return Array.isArray(p.children) ? p.children.includes(child) : true
}

/** Can a synced pattern reference take the selected blocks' place? */
export function patternFits(store: EditorStore, types: Map<string, BlockTypeDef>, allowed: string[]): boolean {
  const first = store.selection()[0]
  const parent = first ? tree.parentOf(store.get().nodes, first) : null
  return accepts(parent, 'pattern', types, parent ? [] : allowed)
}

/**
 * Where a block of `type` goes after `afterId`: right after it when its
 * container takes that type, otherwise after the nearest ancestor whose
 * container does (a paragraph typed after a button leaves the button row).
 */
export function insertionPoint(nodes: BlockNode[], afterId: string, type: string | string[], types: Map<string, BlockTypeDef>, allowed: string[] = []): tree.Location | null {
  const permits = (parent: BlockNode | null) => (Array.isArray(type) ? type : [type]).every((name) => accepts(parent, name, types, parent ? [] : allowed))
  let id = afterId
  for (;;) {
    const loc = tree.locate(nodes, id)
    if (!loc) return permits(null) ? { parentId: null, index: nodes.length } : null
    const parent = loc.parentId ? tree.find(nodes, loc.parentId) : null
    if (permits(parent)) return { parentId: loc.parentId, index: loc.index + 1 }
    if (!parent) return null
    id = parent.id
  }
}
