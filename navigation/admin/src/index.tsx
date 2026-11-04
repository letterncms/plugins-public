import './styles.css'
import { registry } from '@letterncms/sdk'
import { lazy } from 'react'
import { LabelField } from './fields'

registry.fields.set('navigation-label', LabelField as any)
registry.widgets.set('navigation-menus', lazy(() => import('./WidgetMenus')))
// The places are views (NavigationPlugin::places); the menu's tree is drawn here.
registry.components.set('navigation/menu', lazy(() => import('./MenuEditor')))
