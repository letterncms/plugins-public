import './styles.css'
import { can, registry } from '@letterncms/sdk'
import { lazy } from 'react'
import { PagePanel } from './PagePanel'

registry.widgets.set('analytics-overview', lazy(() => import('./WidgetOverview')))
registry.widgets.set('analytics-now', lazy(() => import('./WidgetNow')))
registry.widgets.set('analytics-pages', lazy(() => import('./WidgetPages')))
registry.widgets.set('analytics-search', lazy(() => import('./WidgetSearch')))
// The place is a view (AnalyticsViews); each tab's report is drawn here.
registry.components.set('analytics/overview', lazy(() => import('./Overview')))
registry.components.set('analytics/journeys', lazy(() => import('./Journeys')))
registry.components.set('analytics/search', lazy(() => import('./Search')))
// How a page did, beside it in the editor: pages of collections that have addresses.
registry.panels.set('analytics', { slot: 'entry.sidebar', label: 'Analytics', icon: 'chart', component: PagePanel, order: 30, when: (p) => !!p.collection?.route && !!p.entry && can('analytics.view') })
