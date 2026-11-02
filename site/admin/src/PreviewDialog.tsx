import { events, type EntryPanelProps } from '@letterncms/sdk'
import { Spinner } from '@letterncms/ui'
import { lazy, Suspense, useEffect, useState } from 'react'

const Preview = lazy(() => import('./RenderedPreview').then((module) => ({ default: module.RenderedPreview })))

/** The one site-owned preview of the complete working snapshot, including unsaved entries. It opens on `entry.preview`. */
export function PreviewDialog({ entry, content, collection }: EntryPanelProps) {
  const [open, setOpen] = useState(false)
  useEffect(() => events.on('entry.preview', () => setOpen(true)), [])
  if (!open) return null
  return <Suspense fallback={<Spinner />}><Preview entry={entry} collection={collection.handle} content={content} onClose={() => setOpen(false)} /></Suspense>
}
