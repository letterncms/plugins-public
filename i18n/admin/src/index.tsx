import './styles.css'
import { prefetchAbility, registry } from '@letterncms/sdk'
import { lazy } from 'react'
import { LanguageMenu } from './LanguageMenu'

registry.routes.set('/languages', { path: '/languages', component: lazy(() => import('./LanguagesScreen')), load: () => prefetchAbility('languages/list') })
registry.widgets.set('i18n-coverage', lazy(() => import('./WidgetCoverage')))
registry.panels.set('i18n-languages', { id: 'i18n-languages', slot: 'entry.toolbar', component: LanguageMenu, order: 5 })
