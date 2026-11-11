import './styles.css'
import { registry } from '@letterncms/sdk'
import { lazy } from 'react'
import UploadAction from './UploadAction'

// The places are views (ImportViews): these are the two pieces they draw with.
registry.components.set('import/upload', UploadAction)
registry.components.set('import/progress', lazy(() => import('./Progress')))
