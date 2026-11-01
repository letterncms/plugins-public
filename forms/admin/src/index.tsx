import './styles.css'
import { prefetchAbility, registry } from '@letterncms/sdk'
import { lazy } from 'react'
import { registerFieldViews } from './FieldView'
import { LabelField } from './fields'

registerFieldViews()
registry.fields.set('forms-label', LabelField as any)
registry.widgets.set('forms-submissions', lazy(() => import('./WidgetSubmissions')))
registry.routes.set('/forms', { path: '/forms', component: lazy(() => import('./FormsScreen')), load: () => prefetchAbility('forms/list') })
registry.routes.set('/forms/:handle', { path: '/forms/:handle', component: lazy(() => import('./FormBuilder').then((m) => ({ default: m.FormBuilder }))), load: ({ handle }) => prefetchAbility('forms/get', { handle }) })
