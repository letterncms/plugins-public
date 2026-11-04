import './styles.css'
import { registry } from '@letterncms/sdk'
import { lazy } from 'react'
import { LanguageMenu } from './LanguageMenu'

registry.widgets.set('i18n-coverage', lazy(() => import('./WidgetCoverage')))
registry.panels.set('i18n-languages', { id: 'i18n-languages', slot: 'entry.toolbar', component: LanguageMenu, order: 5 })
// The Languages page is a view (I18nPlugin::view): adding a language is its one piece of its own.
registry.components.set('i18n/add-language', lazy(() => import('./AddLanguage')))
