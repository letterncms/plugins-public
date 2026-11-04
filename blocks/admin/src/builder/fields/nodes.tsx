import { t, type FieldProps } from '@letterncms/sdk'
import { useEditorContext } from '../../core/context'
import { useEditor } from '../../core/store'
import * as tree from '../../core/tree'
import { RemoveButton } from '../controls'
import { SubField } from './controls'

/** `blocks-node` — core select over the document's elements (animation targets, play and load actions). Options: `exclude` (an id), placeholder. */
export function NodeField({ field, ...props }: FieldProps<string | null>) {
  const { store, types } = useEditorContext()
  const nodes = useEditor(store, (s) => s.nodes)
  const options = tree.flatten(nodes)
    .filter(({ node }) => node.id !== field.exclude)
    .map(({ node, depth }) => ({ value: node.id, label: `${'  '.repeat(depth)}${node.label || tree.summary(node, 28) || types.get(node.type)?.title || node.type}` }))
  return <SubField {...props} field={{ ...field, type: 'select', component: undefined, size: 'sm', options, placeholder: field.placeholder ?? t('Choose an Element') }} />
}

/** `blocks-nodes` — a list of elements: change or remove each, `add` (a label) appends `addValue`. */
export function NodesField({ field, value, onChange, disabled, context }: FieldProps<string[] | null>) {
  const list = value ?? []
  const item = { handle: field.handle, type: 'select', label: field.label, exclude: field.exclude }
  return (
    <div className="min-w-0 flex-1 space-y-1">
      {list.map((id, i) => (
        <div key={i} className="flex items-center gap-1">
          <NodeField field={{ ...item, handle: `${field.handle}-${i}` }} value={id} onChange={(next) => onChange(next ? list.map((x, j) => (j === i ? next : x)) : list.filter((_, j) => j !== i))} disabled={disabled} context={context} />
          <RemoveButton onClick={() => onChange(list.filter((_, j) => j !== i))} />
        </div>
      ))}
      <button type="button" onClick={() => onChange([...list, field.addValue as string])} className="text-xs text-muted hover:text-text">
        + {(field.add as string) ?? t('Element')}
      </button>
    </div>
  )
}

export function nodeLabel(node: { label?: string; type: string; props?: Record<string, any> } | null | undefined, title?: string): string {
  if (!node) return t('Missing element')
  return node.label || tree.summary(node as any, 28) || title || node.type
}
