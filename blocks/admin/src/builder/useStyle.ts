import { useEditorContext } from '../core/context'
import { useEditor, variantChain, variantKey } from '../core/store'
import type { BlockNode, StyleMap } from '../core/types'

export type ValueStatus = 'own' | 'inherited' | 'none'

/**
 * Read and write one node's styles at the variant being edited (breakpoint ×
 * state). Values cascade like CSS: a phone hover value falls back to hover,
 * then phone, tablet and all screens.
 */
export function useStyle(node: BlockNode) {
  const { store, catalog } = useEditorContext()
  const breakpoint = useEditor(store, (s) => s.breakpoint)
  const state = useEditor(store, (s) => s.state)
  const variant = variantKey(breakpoint, state)
  const chain = variantChain(breakpoint, state)
  const style: StyleMap = node.style ?? {}
  const aliases = catalog.aliases ?? {}

  const lookup = (key: string): { value: string | undefined; from: string | null } => {
    for (const v of chain) {
      const layer = style[v]
      if (!layer) continue
      for (const k of [key, ...Object.entries(aliases).filter(([, to]) => to === key).map(([from]) => from)]) {
        if (layer[k] !== undefined) return { value: String(layer[k]), from: v }
      }
    }
    return { value: undefined, from: null }
  }

  const value = (key: string) => lookup(key).value
  const status = (key: string): ValueStatus => {
    const { from } = lookup(key)
    return from === variant ? 'own' : from ? 'inherited' : 'none'
  }

  const write = (patch: Record<string, string | number | null | undefined>, group?: string) => {
    const layer: Record<string, string | number> = { ...(style[variant] ?? {}) }
    for (const [key, v] of Object.entries(patch)) {
      for (const [alias, to] of Object.entries(aliases)) if (to === key) delete layer[alias]
      if (v === null || v === undefined || v === '') delete layer[key]
      else layer[key] = v
    }
    const next: StyleMap = { ...style, [variant]: layer }
    if (Object.keys(layer).length === 0) delete next[variant]
    store.updateNode(node.id, { style: Object.keys(next).length ? next : undefined }, group ?? `style:${node.id}:${Object.keys(patch).join(',')}`)
  }

  const set = (key: string, v: string | number | null | undefined) => write({ [key]: v })

  /** Keys set at this variant (for the CSS section and "clear all"). */
  const own = () => Object.keys(style[variant] ?? {})

  return { variant, breakpoint, state, style, value, status, set, write, own }
}

export type StyleApi = ReturnType<typeof useStyle>
