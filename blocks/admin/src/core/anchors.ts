import { slugify } from '@letterncms/ui'
import { useEditorContext } from './context'
import { useEditor } from './store'
import * as tree from './tree'

/** Sections of this page links can point at: anchors set on elements and every heading. */
export function useAnchors(): { id: string; label: string }[] {
  const { store } = useEditorContext()
  const nodes = useEditor(store, (s) => s.nodes)
  const used = new Map<string, number>()
  const out: { id: string; label: string }[] = []
  for (const { node } of tree.flatten(nodes)) {
    const text = String(node.props?.text ?? '').replace(/<[^>]+>/g, '').trim()
    let id = (node.props?.anchor as string | undefined) || node.anchor || ''
    if (!id && node.type === 'heading' && text) {
      const base = slugify(text) || 'section'
      const n = (used.get(base) ?? 0) + 1
      used.set(base, n)
      id = n === 1 ? base : `${base}-${n}`
    }
    if (id) out.push({ id, label: node.label || text || id })
  }
  return out
}
