import './styles.css'
import { registry, t } from '@letterncms/sdk'
import { lazy } from 'react'
import { CommentsPanel } from './CommentsPanel'
import { ComposerField } from './fields'
import { PresenceBar } from './PresenceBar'

registry.fields.set('collab-composer', ComposerField as any)
registry.panels.set('collab-presence', { id: 'collab-presence', slot: 'entry.toolbar', component: PresenceBar, order: 1 })
registry.panels.set('collab-comments', { id: 'collab-comments', slot: 'entry.sidebar', label: t('Comments'), icon: 'comment', component: CommentsPanel, order: 30, when: (p: any) => !!p.entry })
registry.widgets.set('comments-inbox', lazy(() => import('./WidgetComments')))
