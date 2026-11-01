import { can, invalidate, Link, plural, t, useAbility } from '@letterncms/sdk'
import { Fields, Icon, Skeleton, toast, Widget } from '@letterncms/ui'
import { useState } from 'react'
import { upload, type MediaItem } from './upload'

const kindIcon: Record<MediaItem['kind'], string> = { image: 'image', video: 'video', audio: 'play', pdf: 'file-text', file: 'file' }

/** Home-screen widget: the latest uploads as thumbnails — three across in the side column, six in the main one. */
export default function WidgetRecent() {
  const list = useAbility<{ items: MediaItem[]; total: number }>('media/list', { perPage: 6 })
  const [busy, setBusy] = useState(false)

  const send = async (files: File[] | null) => {
    if (!files?.length) return
    setBusy(true)
    const pending = toast.loading(plural(files.length, 'Uploading {count} file…', 'Uploading {count} files…'))
    const results = await Promise.allSettled(files.map((file) => upload(file)))
    const failed = results.find((r): r is PromiseRejectedResult => r.status === 'rejected')
    invalidate('media/')
    setBusy(false)
    if (failed) toast.error(failed.reason, { id: pending })
    else toast.success(plural(files.length, 'Uploaded', '{count} files uploaded'), { id: pending })
  }

  return (
    <Widget
      query={list}
      action={{ label: t('All Media'), to: '/media' }}
      skeleton={
        <div className="grid grid-cols-3 gap-2 @md:grid-cols-6">
          {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="aspect-square rounded-sm" />)}
        </div>
      }
      empty={
        list.data?.items.length === 0 && {
          icon: 'media',
          title: t('No uploads yet'),
          action: can('media.upload') && <Fields bare fields={[{ handle: 'files', type: 'json', component: 'media-upload', label: t('Upload'), multiple: true }]} values={{}} onChange={(_, files) => void send(files)} disabled={busy} />,
        }
      }
    >
      <div className="grid grid-cols-3 gap-2 @md:grid-cols-6">
        {list.data?.items.map((item) => (
          <Link key={item.id} to="/media" title={item.name} className="group relative block aspect-square overflow-hidden rounded-sm border border-border bg-surface">
            {item.thumb ? (
              <img src={item.thumb} alt={item.alt ?? ''} loading="lazy" className="size-full object-cover transition-opacity duration-120 group-hover:opacity-85" />
            ) : (
              <span className="flex size-full flex-col items-center justify-center gap-1 px-1.5 text-faint transition-colors duration-120 group-hover:bg-hover">
                <Icon name={kindIcon[item.kind] ?? 'file'} size={18} />
                <span className="w-full truncate text-center text-2xs">{item.filename}</span>
              </span>
            )}
          </Link>
        ))}
      </div>
    </Widget>
  )
}
