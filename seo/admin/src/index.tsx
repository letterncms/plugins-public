import './styles.css'
import { prefetchAbility, registry } from '@letterncms/sdk'
import { lazy } from 'react'
import { SeoPanel } from './SeoPanel'

registry.panels.set('seo', { id: 'seo', slot: 'entry.sidebar', fieldHandles: ['seo'], label: 'SEO', component: SeoPanel, order: 20, when: (p: any) => !!p.collection?.route })
registry.routes.set('/redirects', { path: '/redirects', component: lazy(() => import('./RedirectsScreen')), load: () => prefetchAbility('redirects/list') })
registry.widgets.set('seo-health', lazy(() => import('./WidgetHealth')))
