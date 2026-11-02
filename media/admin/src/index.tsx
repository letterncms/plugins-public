import './styles.css'
import { can, registry, run, t } from '@letterncms/sdk'
import { lazy } from 'react'
import { UploadField } from './fields'
import { MediaField } from './MediaField'
import { loadMediaLibrary } from './Library'

registry.fields.set('media', MediaField as any)
registry.fields.set('media-upload', UploadField as any)
registry.routes.set('/media', { path: '/media', component: lazy(() => import('./MediaScreen')), load: loadMediaLibrary })
registry.widgets.set('media-recent', lazy(() => import('./WidgetRecent')))

// @ in a chat: a file from the library, for the assistant to use or to change.
registry.composer.set('media', {
  id: 'media', trigger: '@', label: t('Media'), order: 20,
  search: async (query) => {
    if (!can('media.view')) return []
    const { items } = await run<{ items: { id: string; name: string; url: string; kind?: string; alt?: string | null }[] }>('media/list', { search: query, perPage: 5 })
    return items.map((item) => ({ id: `media:${item.id}`, kind: 'file', label: item.name, icon: item.kind === 'image' ? 'image' : 'file', to: '/media', read: () => item }))
  },
})

export { MediaField }
export { Library } from './Library'
export { upload } from './upload'
export type { MediaItem } from './upload'
