import { useAbility } from '@letterncms/sdk'
import { createContext, useContext, useMemo, type ReactNode } from 'react'
import type { EditorStore } from './store'
import type { BlockNode, BlockTypeDef, Catalog } from './types'

/** What the editor is editing: an entry's blocks field, a template or a component. */
export interface DocRef {
  kind: 'entry' | 'template' | 'pattern'
  /** Entry id or template/pattern handle. */
  id: string
  /** Collections whose entries a template frames (for data hints and the sample entry). */
  collections?: string[]
  /** Component properties (for data hints inside a component). */
  props?: { handle: string; label?: string; type?: string }[]
}

export interface EditorContextValue {
  store: EditorStore
  types: Map<string, BlockTypeDef>
  catalog: Catalog
  /** Entry being edited (for previews); null for unsaved entries and patterns. */
  entryId: string | null
  fieldHandle: string
  /** Allowed block types for this field (empty = all). */
  allowed: string[]
  getValues: () => Record<string, any>
  title: string
  doc?: DocRef
  /** The editor is read-only (no permission to edit). */
  readOnly?: boolean
}

const Ctx = createContext<EditorContextValue | null>(null)

export function EditorProvider({ value, children }: { value: EditorContextValue; children: ReactNode }) {
  value.store.setReadOnly(!!value.readOnly)
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

export function useEditorContext(): EditorContextValue {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('Block editor context missing')
  return ctx
}

export function useCatalog() {
  const q = useAbility<Catalog>('blocks/types', {}, { staleTime: 300_000 })
  const types = useMemo(() => new Map((q.data?.blocks ?? []).map((b) => [b.name, b])), [q.data])
  return { catalog: q.data, types, isLoading: q.isLoading, error: q.error, retry: () => void q.refetch() }
}

/** Components' titles by handle: an instance is named after its component (Header, Footer). */
export function useComponentTitles(): Map<string, string> {
  const q = useAbility<{ handle: string; title: string }[]>('patterns/list', {}, { staleTime: 60_000 })
  return useMemo(() => new Map((q.data ?? []).map((p) => [p.handle, p.title])), [q.data])
}

/** What a block is called: its layer name, its component's title, or its type's. */
export function blockName(node: BlockNode, types: Map<string, BlockTypeDef>, components: Map<string, string>): string {
  return node.label || (node.type === 'pattern' ? components.get(String(node.props?.ref ?? '')) : undefined) || types.get(node.type)?.title || node.type
}

export interface ThemeToken {
  path: string
  group: string
  name: string
  value: unknown
  dark?: unknown
}

export interface DesignInfo {
  tokens: ThemeToken[]
  breakpoints: Record<string, string>
  templates: Record<string, string>
  name: string
  fonts: { family: string; src: string; weight?: string; style?: string }[]
  stylesheet: string
}

/** The site's design system (empty when the site plugin is off). */
export function useTokens() {
  const q = useAbility<DesignInfo>('design/get', {}, { staleTime: 60_000, retry: false })
  return useMemo(() => {
    const byGroup = new Map<string, ThemeToken[]>()
    for (const t of q.data?.tokens ?? []) byGroup.set(t.group, [...(byGroup.get(t.group) ?? []), t])
    const byPath = new Map((q.data?.tokens ?? []).map((t) => [t.path, t]))
    return { tokens: q.data?.tokens ?? [], byGroup, byPath, breakpoints: q.data?.breakpoints ?? { md: '768px', lg: '1024px' }, templates: q.data?.templates ?? {}, available: !!q.data }
  }, [q.data])
}
