import { type FieldProps } from '@letterncms/sdk'
import { IconButton, InspectorPanel, QueryError, Spinner } from '@letterncms/ui'
import { useRef, useState } from 'react'
import { EditorProvider, useCatalog } from './core/context'
import { useBlockSession } from './core/useBlockSession'
import type { BlockNode } from './core/types'
import { LiveEditor } from './LiveEditor'
import { Inspector } from './builder/Inspector'
import { useEditor } from './core/store'
import * as tree from './core/tree'
import { t } from '@letterncms/sdk'

/** Every blocks field uses the same document editor and selection/history model. */
export function BlocksField({ field, value, onChange, context, disabled }: FieldProps<BlockNode[]>) {
  const { catalog, types, isLoading, error, retry } = useCatalog()
  const store = useBlockSession(value, onChange, !!disabled)
  const [inspected, setInspected] = useState<string | null>(null)
  const nodes = useEditor(store, (state) => state.nodes)
  const contextRef = useRef(context)
  contextRef.current = context

  if (isLoading) return <div className="flex h-24 items-center justify-center text-faint"><Spinner /></div>
  if (!catalog) return <QueryError message={error?.message} onRetry={retry} />

  const ctx = {
    store,
    types,
    catalog,
    entryId: context.entry?.id ?? null,
    fieldHandle: field.handle,
    allowed: (field.allowed as string[] | undefined) ?? [],
    getValues: () => contextRef.current.values ?? {},
    title: context.title ?? context.entry?.title ?? '',
    readOnly: !!disabled,
  }

  return (
    <EditorProvider value={ctx}>
      <div className="h-[480px] min-w-0 overflow-hidden rounded-sm border border-border">
        <LiveEditor collection={context.collection} entryId={context.entry?.id} content={{ title: context.title ?? context.entry?.title ?? '', slug: context.entry?.slug ?? '', authorId: context.entry?.authorId ?? null, data: { ...(context.values ?? context.entry?.data ?? {}), [field.handle]: value ?? [] } }} onInspect={setInspected} standalone />
      </div>
      <InspectorPanel docked={false} open={!!inspected} onOpenChange={(open) => !open && setInspected(null)} title={t('Block Settings')}>
        <div className="flex h-10 shrink-0 items-center justify-end border-b border-border px-2"><IconButton icon="close" label={t('Close Panel')} size="xs" onClick={() => setInspected(null)} /></div>
        {inspected && tree.find(nodes, inspected) && <Inspector node={tree.find(nodes, inspected)!} onClose={() => setInspected(null)} />}
      </InspectorPanel>
    </EditorProvider>
  )
}
