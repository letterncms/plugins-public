import { run } from '@letterncms/sdk'
import { toast } from '@letterncms/ui'
import { insertionPoint } from '../core/insert'
import type { EditorStore } from '../core/store'
import * as tree from '../core/tree'
import type { BlockNode, BlockTypeDef } from '../core/types'

/** Paste multi-line text: parsed as Block Markdown into blocks after (or instead of) the current block. */
export async function pasteMarkdown(store: EditorStore, current: BlockNode, text: string, types: Map<string, BlockTypeDef>, allowed: string[]) {
  try {
    const nodes = await run<BlockNode[]>('blocks/from-markdown', { markdown: text })
    if (nodes.length === 0) return
    const { nodes: doc } = store.get()
    const empty = current.type === 'paragraph' && tree.isEmptyHtml(current.props.text)
    const after = insertionPoint(doc, current.id, nodes[0].type, types, allowed)
    if (!after) return
    // An empty paragraph is replaced; otherwise the blocks follow the current one.
    const base = empty ? tree.remove(doc, current.id) : doc
    const index = empty && tree.locate(doc, current.id)?.parentId === after.parentId ? after.index - 1 : after.index
    store.commit(tree.insert(base, after.parentId, index, nodes))
    const last = nodes[nodes.length - 1]
    if (last.type === 'list') store.focus(last.id, 'end', 'items', Math.max(0, (last.props.items?.length ?? 1) - 1))
    else store.focus(last.id, 'end')
  } catch (e) {
    toast.error(e)
  }
}
