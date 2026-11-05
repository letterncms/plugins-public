import './styles.css'
import { registry } from '@letterncms/sdk'
import { lazy } from 'react'
import { registerFieldViews } from './FieldView'
import { LabelField } from './fields'

registerFieldViews()
registry.fields.set('forms-label', LabelField)
registry.widgets.set('forms-submissions', lazy(() => import('./WidgetSubmissions')))
// The places are views (FormsViews): these are the pieces they draw with.
registry.components.set('forms/builder', lazy(() => import('./FormBuilder')))
registry.components.set('forms/submission', lazy(() => import('./Submission')))
