import './styles.css'
import { registry } from '@letterncms/sdk'
import { lazy } from 'react'

registry.widgets.set('analytics-overview', lazy(() => import('./WidgetOverview')))
// The place is a view (AnalyticsViews); its headline numbers and chart are drawn here.
registry.components.set('analytics/overview', lazy(() => import('./Overview')))
