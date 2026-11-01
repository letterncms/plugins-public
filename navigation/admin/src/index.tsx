import './styles.css'
import { prefetchAbility, registry } from '@letterncms/sdk'
import { lazy } from 'react'
import { LabelField } from './fields'

registry.fields.set('navigation-label', LabelField as any)

registry.routes.set('/navigation', { path: '/navigation', component: lazy(() => import('./MenusScreen')), load: () => prefetchAbility('navigation/list') })
registry.routes.set('/navigation/:handle', { path: '/navigation/:handle', component: lazy(() => import('./MenuEditor')), load: ({ handle }) => prefetchAbility('navigation/get', { handle }) })
registry.widgets.set('navigation-menus', lazy(() => import('./WidgetMenus')))
