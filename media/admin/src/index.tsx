import './styles.css'
import { registry } from '@letterncms/sdk'
import { lazy } from 'react'
import { UploadField } from './fields'
import { MediaField } from './MediaField'
import { loadMediaLibrary } from './Library'

registry.fields.set('media', MediaField as any)
registry.fields.set('media-upload', UploadField as any)
registry.routes.set('/media', { path: '/media', component: lazy(() => import('./MediaScreen')), load: loadMediaLibrary })
registry.widgets.set('media-recent', lazy(() => import('./WidgetRecent')))

export { MediaField }
export { Library } from './Library'
export { upload } from './upload'
export type { MediaItem } from './upload'
