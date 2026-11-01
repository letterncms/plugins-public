import './styles.css'
import { registry } from '@letterncms/sdk'
import { lazy } from 'react'

registry.widgets.set('brevo-stats', lazy(() => import('./WidgetStats')))
