import { events, t, type EntryPanelProps } from '@letterncms/sdk'
import { IconButton, Spinner, Tooltip } from '@letterncms/ui'
import { lazy, Suspense, useEffect, useState } from 'react'

const Preview = lazy(() => import('./RenderedPreview').then((module) => ({ default: module.RenderedPreview })))

/** One site-owned preview action for the complete working snapshot, including unsaved entries. */
export function PreviewButton({ entry, content, collection }: EntryPanelProps) {
  const [open, setOpen] = useState(false)
  useEffect(() => events.on('entry.preview', () => setOpen(true)), [])
  if (!collection.route && !collection.body) return null
  return <>
    <Tooltip content={t('Preview')}><IconButton icon="eye" label={t('Preview')} onClick={() => setOpen(true)} /></Tooltip>
    {open && <Suspense fallback={<Spinner />}><Preview entry={entry} collection={collection.handle} content={content} onClose={() => setOpen(false)} /></Suspense>}
  </>
}
