import './styles.css'
import { prefetchAbility, registry } from '@letterncms/sdk'
import { lazy } from 'react'

registry.routes.set('/analytics', { path: '/analytics', component: lazy(() => import('./AnalyticsScreen')), load: (_, { query }) => prefetchAbility('analytics/summary', { period: ['7d', '30d', '90d'].includes(query.period) ? query.period : '30d' }) })
registry.widgets.set('analytics-overview', lazy(() => import('./WidgetOverview')))
