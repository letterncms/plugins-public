import './styles.css'
import { registry, t } from '@letterncms/sdk'
import { lazy } from 'react'
import { BlocksField } from './BlocksField'
import { registerFields } from './builder/fields'
import { registerBuilderIcons } from './builder/icons'
import { IconField } from './builder/content/IconField'
import { EaseField } from './builder/motion/EaseField'

registerBuilderIcons()
registry.fields.set('blocks', BlocksField as any)
registerFields()
registry.fields.set('blocks-icon', IconField as any)
registry.fields.set('blocks-easing', EaseField as any)

// Every explicit blocks body mounts the same workspace.
registry.panels.set('blocks-editor', {
  id: 'blocks',
  slot: 'entry.editor',
  label: t('Block Editor'),
  icon: 'layout',
  component: lazy(() => import('./BlocksWorkspace').then((m) => ({ default: m.BlocksWorkspace }))),
  when: (props: { bodyField?: { type: string } }) => props.bodyField?.type === 'blocks',
})
registry.widgets.set('blocks-design', lazy(() => import('./WidgetDesign')))

export { BlocksField }
export { EditorStore } from './core/store'
export { EditorProvider, useCatalog, useTokens } from './core/context'
export { WriteEditor, blockViews } from './write/WriteEditor'
export { InlineEditable } from './core/InlineEditable'
export { useEditor } from './core/store'
export { useEditorContext } from './core/context'
export { Inspector } from './builder/Inspector'
export * as blockTree from './core/tree'
export type { BlockNode, BlockTypeDef } from './core/types'
