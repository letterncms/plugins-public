import './styles.css'
import { can, registry, t } from '@letterncms/sdk'
import { EmptyState } from '@letterncms/ui'
import { lazy } from 'react'
import { ComposerField } from './fields'
import { AssistantPanel } from './Panel'
import { panel } from './store'
import { ChatHistory } from './History'

registry.fields.set('assistant-composer', ComposerField as any)
registry.panels.set('assistant', { id: 'assistant', slot: 'shell.aside', component: AssistantPanel })
registry.widgets.set('assistant-ask', lazy(() => import('./WidgetAsk')))
// The persistent shell component owns streaming; routes provide native navigation.
const ChatScreen = () => can('assistant.use') ? null : <EmptyState icon="lock" title={t('You do not have access to the assistant.')} className="min-h-[50vh]" />
registry.routes.set('/chat/:id', { path: '/chat/:id', component: ChatScreen })
// New Chat is a sidebar item (registered in PHP); the chats follow the sidebar's pinned items.
registry.panels.set('assistant-history', { id: 'assistant-history', slot: 'sidebar.history', component: ChatHistory })

registry.commands.set('assistant', {
  id: 'assistant',
  label: t('New Chat'),
  icon: 'compose',
  group: t('Assistant'),
  keywords: ['ai', 'assistant', 'claude', 'chat', 'help', 'write', 'build'],
  shortcut: 'mod+j',
  acceptsQuery: true,
  perform: (query) => panel.ask(query),
})

window.addEventListener('keydown', (e) => {
  if ((e.metaKey || e.ctrlKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'j' && can('assistant.use')) {
    e.preventDefault()
    panel.newChat()
  }
})
