import { t } from '@letterncms/sdk'
import { useEditorContext } from '../../core/context'
import { useEditor } from '../../core/store'
import * as tree from '../../core/tree'
import type { BlockNode } from '../../core/types'
import { useStyle } from '../useStyle'
import { Border } from './Border'
import { Css } from './Css'
import { Effects } from './Effects'
import { Fill } from './Fill'
import { InParent, Layout } from './Layout'
import { Position } from './Position'
import { Size } from './Size'
import { Spacing } from './Spacing'
import { StateBar } from './StateBar'
import { Transform } from './Transform'
import { Transition } from './Transition'
import { Typography } from './Typography'

/** The Design tab: every CSS property a block can take, grouped like Framer's inspector. */
export function DesignPanel({ node }: { node: BlockNode }) {
  const { types, store } = useEditorContext()
  const type = types.get(node.type)
  const s = useStyle(node)
  const parent = useEditor(store, (st) => tree.parentOf(st.nodes, node.id))
  const parentDisplay = parent ? (parent.style?.base?.display as string | undefined) ?? (parent.type === 'stack' || parent.type === 'columns' || parent.type === 'grid' ? 'flex' : undefined) : undefined
  const groups = new Set(type?.styles?.length ? type.styles : ['layout', 'position', 'size', 'spacing', 'typography', 'fill', 'border', 'effects', 'transform', 'transition'])
  const container = !!type?.children || node.type === 'repeat'

  if (node.type === 'pattern') {
    return <p className="m-0 p-4 text-xs text-muted">{t('Edit the component to change its shared design.')}</p>
  }

  return (
    <div>
      <StateBar node={node} />
      {groups.has('layout') && container && <Layout s={s} />}
      <InParent s={s} parentDisplay={parentDisplay} />
      {groups.has('position') && <Position s={s} />}
      {groups.has('size') && <Size s={s} media={['image', 'video', 'embed'].includes(node.type)} />}
      {groups.has('spacing') && <Spacing s={s} />}
      {groups.has('typography') && <Typography s={s} />}
      {groups.has('fill') && <Fill s={s} />}
      {groups.has('border') && <Border s={s} />}
      {groups.has('effects') && <Effects s={s} />}
      {groups.has('transform') && <Transform s={s} />}
      {groups.has('transition') && <Transition s={s} />}
      <Css s={s} />
    </div>
  )
}
