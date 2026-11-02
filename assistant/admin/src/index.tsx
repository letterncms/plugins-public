import './styles.css'
import { can, registry, t } from '@letterncms/sdk'
import { EmptyState } from '@letterncms/ui'
import { lazy } from 'react'
import { ComposerField } from './fields'
import { Launcher } from './Launcher'
import { AssistantPanel } from './Panel'
import { available, panel } from './store'

registry.fields.set('assistant-composer', ComposerField as any)
registry.panels.set('assistant', { id: 'assistant', slot: 'shell.dock', component: AssistantPanel })
registry.widgets.set('assistant-ask', lazy(() => import('./WidgetAsk')))
registry.panels.set('assistant-launcher', { id: 'assistant-launcher', slot: 'sidebar.footer', component: Launcher })
// A chat's own URL is the dock at full size, over the page it came from. The dock owns streaming, so the route draws nothing.
const ChatScreen = () => can('assistant.use') ? null : <EmptyState icon="lock" title={t('You do not have access to the assistant.')} className="min-h-[50vh]" />
registry.routes.set('/chat/:id', { path: '/chat/:id', component: ChatScreen, overlay: true })

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
  if ((e.metaKey || e.ctrlKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'j' && available()) {
    e.preventDefault()
    panel.toggle()
  }
})
