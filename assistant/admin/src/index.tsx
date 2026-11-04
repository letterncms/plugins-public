import './styles.css'
import { registry } from '@letterncms/sdk'
import { lazy } from 'react'
import { ModelPicker } from './ModelPicker'
import './sources'

// Claude answers in the workspace's threads (Cms\Contracts\Agent); in the composer it adds the model picker, and its skills and context to / and @.
registry.panels.set('assistant-model', { id: 'assistant-model', slot: 'workspace.composer', component: ModelPicker })
registry.widgets.set('assistant-ask', lazy(() => import('./WidgetAsk')))
