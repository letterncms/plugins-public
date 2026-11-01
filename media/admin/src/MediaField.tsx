import { t, useAbility, type FieldProps } from '@letterncms/sdk'
import { Button, Dialog, Icon, IconButton, cn } from '@letterncms/ui'
import { useState } from 'react'
import { Library } from './Library'
import type { MediaItem } from './upload'

/** Media field: thumbnails of the chosen files + a picker dialog with the full library. */
export function MediaField({ field, value, onChange, disabled }: FieldProps<string[]>) {
  const ids = (Array.isArray(value) ? value : typeof value === 'string' && value ? [value] : []).filter(Boolean)
  const max = (field.max as number) || Infinity
  const accept = (field.accept as string) || 'image/*'
  const [open, setOpen] = useState(false)
  const [picked, setPicked] = useState<string[]>(ids)
  const lookup = useAbility<{ items: MediaItem[] }>('media/lookup', { ids: ids.filter((i) => !i.startsWith('/') && !i.startsWith('http')) }, { enabled: ids.length > 0 })
  const byId = new Map((lookup.data?.items ?? []).map((m) => [m.id, m]))

  return (
    <div>
      <div className={cn('flex flex-wrap gap-2', ids.length === 0 && 'hidden')}>
        {ids.map((id) => {
          const m = byId.get(id)
          const isUrl = id.startsWith('/') || id.startsWith('http')
          return (
            <div key={id} className="group relative size-24 overflow-hidden rounded-md border border-border bg-sunken">
              {m?.thumb || isUrl ? <img src={m?.thumb ?? id} alt={m?.alt ?? ''} className="h-full w-full object-cover" /> : <div className="grid h-full place-items-center text-muted"><Icon name="file" size={20} /></div>}
              {!disabled && (
                <IconButton icon="close" label={t('Remove')} size="xs" variant="secondary" className="absolute right-1 top-1 opacity-0 group-hover:opacity-100" onClick={() => onChange(ids.filter((x) => x !== id))} />
              )}
              {m && m.kind === 'image' && !m.alt && <span className="absolute bottom-1 left-1 rounded-xs bg-warning px-1 text-2xs font-medium text-white" title={t('No alt text')}>ALT</span>}
            </div>
          )
        })}
      </div>
      {ids.length < max && (
        <Button size="sm" icon={accept.startsWith('image') ? 'image' : 'upload'} disabled={disabled} className={ids.length ? 'mt-2' : ''} onClick={() => { setPicked(ids); setOpen(true) }}>
          {ids.length ? t('Add More') : max === 1 ? (accept.startsWith('image') ? t('Choose Image') : t('Choose File')) : t('Choose Files')}
        </Button>
      )}
      <Dialog
        open={open}
        onOpenChange={setOpen}
        size="xl"
        title={max === 1 ? (accept.startsWith('image') ? t('Choose Image') : t('Choose File')) : t('Choose Files')}
        className="!top-[6vh] !max-h-[88vh]"
        footer={
          <>
            <span className="mr-auto text-xs text-faint">{t('{count} selected', { count: picked.length })}</span>
            <Button onClick={() => setOpen(false)}>{t('Cancel')}</Button>
            <Button variant="primary" disabled={picked.length === 0} onClick={() => { onChange(picked.slice(0, max === Infinity ? undefined : max)); setOpen(false) }}>{picked.length > 1 ? t('Use {count} Files', { count: picked.length }) : t('Use File')}</Button>
          </>
        }
      >
        <div className="min-h-[40vh]">
          <Library
            mode="pick"
            accept={accept}
            multiple={max > 1}
            selected={picked}
            onSelectionChange={(next) => {
              setPicked(next)
              if (max === 1 && next.length === 1 && next[0] !== ids[0]) {
                onChange(next)
                setOpen(false)
              }
            }}
          />
        </div>
      </Dialog>
    </div>
  )
}
