import { can, invalidate, locale, plural, prefetchAbility, run, t, useAbility, type FieldDef } from '@letterncms/sdk'
import { Badge, Button, DataView, Dialog, EmptyState, Fields, Icon, IconButton, Menu, SelectionBar, confirm, cn, toast, readViewPrefs } from '@letterncms/ui'
import { useState, type ReactNode } from 'react'
import { formatBytes, upload, type MediaItem } from './upload'

type Kind = 'all' | 'image' | 'video' | 'audio' | 'pdf' | 'file'

interface MediaFilters { search: string; kind: Kind; folder: string | null }
const mediaInput = (filters: MediaFilters, page: number, sort: string | null) => ({ search: filters.search || undefined, kind: filters.kind, folder: filters.folder, page, perPage: 60, sort: sort ?? undefined })

/** The route loader and rendered library share the same initial query and saved sort. */
export function loadMediaLibrary() {
  return prefetchAbility('media/list', mediaInput({ search: '', kind: 'all', folder: null }, 1, readViewPrefs('overview.media', 'grid').sort))
}

function filterFields(): FieldDef[] {
  return [
    { handle: 'search', type: 'text', label: t('Search Files'), icon: 'search', size: 'sm', placeholder: t('Search files…'), className: 'w-60' },
    { handle: 'kind', type: 'select', label: t('Kind'), display: 'segmented', required: true, options: { all: t('All'), image: t('Images'), video: t('Videos'), audio: t('Audio'), pdf: 'PDF', file: t('Other') } },
  ]
}

interface Uploading {
  key: string
  name: string
  progress: number
  error?: string
}

/**
 * The media library. In "manage" mode clicking opens details; in "pick" mode
 * clicking selects (for media fields and image blocks).
 */
export function Library({ mode = 'manage', accept = '*', multiple = false, selected = [], onSelectionChange, autoUpload = false, frame }: { mode?: 'manage' | 'pick'; accept?: string; multiple?: boolean; selected?: string[]; onSelectionChange?: (ids: string[], items: MediaItem[]) => void; autoUpload?: boolean; frame?: (parts: { upload: ReactNode; total: number | null; body: ReactNode }) => ReactNode }) {
  const [filters, setFilters] = useState<MediaFilters>({ search: '', kind: accept.startsWith('image') ? 'image' : accept.startsWith('video') ? 'video' : accept === 'application/pdf' ? 'pdf' : 'all', folder: null })
  const { kind, folder } = filters
  const [page, setPage] = useState(1)
  const [sort, setSort] = useState(() => readViewPrefs('overview.media', 'grid').sort)
  const [chosen, setChosen] = useState<Set<string>>(new Set())
  const [deleting, setDeleting] = useState(false)
  const [uploads, setUploads] = useState<Uploading[]>([])
  const [open, setOpen] = useState<MediaItem | null>(null)
  const list = useAbility<{ items: MediaItem[]; total: number; page: number; pages: number; folders: string[] }>('media/list', mediaInput(filters, page, sort), { placeholderData: (p) => p })
  const items = list.data?.items ?? []
  const allow = can('media.upload')
  const filter = (handle: string, value: unknown) => { setPage(1); setChosen(new Set()); setFilters((f) => ({ ...f, [handle]: value })) }
  // The same upload button in the page toolbar, picker and standard empty state.
  const uploader = (field: Partial<FieldDef>): FieldDef[] => [{ handle: 'files', type: 'json', component: 'media-upload', label: t('Upload'), multiple: mode === 'manage' || multiple, accept: accept === '*' ? undefined : accept, ...field }]

  const send = async (files: FileList | File[]) => {
    const batch = Array.from(files).map((f) => ({ file: f, key: `${f.name}-${Math.random().toString(36).slice(2)}` }))
    setUploads((u) => [...u, ...batch.map((b) => ({ key: b.key, name: b.file.name, progress: 0 }))])
    const done: MediaItem[] = []
    await Promise.all(
      batch.map(async ({ file, key }) => {
        try {
          const item = await upload(file, folder ?? '', (p) => setUploads((u) => u.map((x) => (x.key === key ? { ...x, progress: p } : x))))
          done.push(item)
          setUploads((u) => u.filter((x) => x.key !== key))
        } catch (e) {
          setUploads((u) => u.map((x) => (x.key === key ? { ...x, error: e instanceof Error ? e.message : String(e) } : x)))
        }
      }),
    )
    invalidate('media/')
    if (done.length && mode === 'pick') {
      const ids = multiple ? [...selected, ...done.map((d) => d.id)] : [done[0].id]
      onSelectionChange?.(ids, done)
    }
    if (done.length) toast.success(plural(done.length, '{count} file uploaded', '{count} files uploaded'))
  }

  const toggle = (item: MediaItem) => {
    if (mode === 'manage') return setOpen(item)
    const next = multiple ? (selected.includes(item.id) ? selected.filter((i) => i !== item.id) : [...selected, item.id]) : [item.id]
    onSelectionChange?.(next, [item])
  }

  // A screen can place the upload button and the count in its own top bar (the Media screen does).
  const uploadButton = (autofocus = false) => allow ? <Fields layout="inline" bare fields={uploader({ variant: mode === 'manage' ? 'primary' : 'secondary', autofocus })} values={{}} onChange={(_, files) => void send(files)} /> : null

  const select = (ids: string[], on: boolean, records = items) => {
    if (mode === 'pick') {
      const next = multiple ? [...new Set(on ? [...selected, ...ids] : selected.filter((id) => !ids.includes(id)))] : on ? ids.slice(0, 1) : []
      onSelectionChange?.(next, records.filter((item) => next.includes(item.id)))
    } else setChosen((current) => { const next = new Set(current); ids.forEach((id) => on ? next.add(id) : next.delete(id)); return next })
  }
  const deleteItems = async (ids: string[]) => {
    if (deleting || !await confirm({ title: plural(ids.length, 'Delete {count} file?', 'Delete {count} files?'), description: t('Pages that use it will show nothing in its place.'), confirm: t('Delete'), danger: true })) return
    setDeleting(true)
    try {
      const results = await Promise.allSettled(ids.map((id) => run('media/delete', { id })))
      const failed = ids.filter((_, i) => results[i].status === 'rejected')
      setChosen(new Set(failed))
      const failure = results.find((result) => result.status === 'rejected')
      if (failure?.status === 'rejected') toast.error(failure.reason)
      if (open && ids.includes(open.id) && !failed.includes(open.id)) setOpen(null)
      invalidate('media/')
    } finally { setDeleting(false) }
  }
  const preview = (item: MediaItem) => item.thumb ? <img src={item.thumb} alt={item.alt ?? ''} loading="lazy" className="absolute inset-0 size-full object-cover" style={{ objectPosition: `${item.focal.x}% ${item.focal.y}%` }} /> : <div className="grid size-full place-items-center text-faint"><Icon name={item.kind === 'video' ? 'video' : item.kind === 'audio' ? 'file' : 'file-text'} size={24} /></div>
  const filtered = !!filters.search || kind !== 'all' || folder !== null
  const body = (
    <div className="min-w-0">
      <DataView id="media" label={t('Media')} items={items} source={{ ability: 'media/list', input: mediaInput(filters, 1, sort), perPage: 60 }} rowKey={(item) => item.id} onRowClick={toggle} defaultView="grid" image={preview}
        pagination={list.data && { page: list.data.page, pages: list.data.pages, total: list.data.total, perPage: 60, onPage: setPage }}
        loading={list.isLoading} stale={list.isPlaceholderData} error={list.isError} onRetry={() => void list.refetch()}
        sort={sort} dateSort={{ published: 'created_at', updated: 'updated_at' }} onSort={(sort) => { setPage(1); setSort(sort) }}
        selection={{ ids: mode === 'manage' ? chosen : new Set(selected), onSelect: select }}
        selected={(item) => open?.id === item.id}
        toolbar={<>
          <Fields layout="inline" bare className="flex min-w-0 flex-wrap items-center gap-2" fields={[...filterFields(), ...(list.data?.folders.length ? [{ handle: 'folder', type: 'select', label: t('Folder'), size: 'sm' as const, placeholder: t('All Folders'), options: list.data.folders.map((f) => ({ value: f, label: f, icon: 'folder' })), className: 'w-44' }] : [])]} values={filters} onChange={filter} />
          {!frame && uploadButton(autoUpload)}
        </>}
        columns={[
          { id: 'name', label: t('Name'), sort: 'name', render: (item) => item.name },
          { id: 'type', label: t('Type'), width: '160px', render: (item) => item.mime },
          { id: 'size', label: t('Size'), width: '100px', align: 'end', sort: 'size', render: (item) => formatBytes(item.size) },
          { id: 'folder', label: t('Folder'), width: '160px', sort: 'folder', render: (item) => item.folder || '—' },
          { id: 'alt', label: t('Alt Text'), hiddenByDefault: true, width: '120px', render: (item) => item.kind === 'image' ? item.alt ? <Badge tone="success">{t('Added')}</Badge> : <Badge tone="warning">{t('No alt text')}</Badge> : '—' },
          { id: 'created', label: t('Uploaded'), width: '120px', sort: 'created_at', render: (item) => new Date(item.createdAt).toLocaleDateString(locale(), { dateStyle: 'medium' }) },
        ]}
        actions={(item) => <Menu trigger={<IconButton icon="more" label={t('Actions for {name}', { name: item.name })} size="xs" />} items={[
          { label: mode === 'pick' ? t('Select') : t('Open'), icon: 'edit', onSelect: () => toggle(item) },
          { label: t('Copy URL'), icon: 'copy', onSelect: () => void navigator.clipboard.writeText(new URL(item.url, location.origin).href).then(() => toast.success(t('URL copied')), toast.error) },
          mode === 'manage' && can('media.delete') && { label: t('Delete'), icon: 'trash', danger: true, disabled: deleting, onSelect: () => void deleteItems([item.id]) },
        ]} />}
        empty={filtered ? <EmptyState icon="search" title={t('No matches')} description={t('Nothing matches these filters.')} action={<Button onClick={() => { setFilters({ search: '', kind: 'all', folder: null }); setPage(1) }}>{t('Clear Filters')}</Button>} /> : <EmptyState icon="media" title={t('No files yet')} action={uploadButton()} />}
      />
      {uploads.length > 0 && <div className="my-4 space-y-2" aria-live="polite">{uploads.map((u) => <div key={u.key} className="flex items-center gap-3 text-xs"><span className="min-w-0 flex-1 truncate">{u.name}</span>{u.error ? <span role="alert" className="text-danger">{u.error}</span> : <progress value={u.progress} max={1} aria-label={u.name} className="h-1 w-32" />}</div>)}</div>}

      {mode === 'manage' && <SelectionBar count={chosen.size} onClear={() => setChosen(new Set())}>{can('media.delete') && <Button size="sm" variant="ghost" loading={deleting} onClick={() => void deleteItems([...chosen])}>{t('Delete')}</Button>}</SelectionBar>}

      {mode === 'manage' && open && <Dialog open onOpenChange={(next) => !next && setOpen(null)} title={open.name}><Details key={open.id} item={items.find((i) => i.id === open.id) ?? open} onClose={() => setOpen(null)} /></Dialog>}
    </div>
  )

  return frame ? frame({ upload: uploadButton(autoUpload), total: list.data?.total ?? null, body }) : body
}

function Details({ item, onClose }: { item: MediaItem; onClose: () => void }) {
  const [draft, setDraft] = useState({ name: item.name, alt: item.alt ?? '', caption: item.caption ?? '', folder: item.folder })
  const [focal, setFocal] = useState(item.focal)
  const [busy, setBusy] = useState(false)
  const dirty = draft.name !== item.name || draft.alt !== (item.alt ?? '') || draft.caption !== (item.caption ?? '') || draft.folder !== item.folder || focal.x !== item.focal.x || focal.y !== item.focal.y
  const editable = can('media.edit')

  const save = async () => {
    setBusy(true)
    try {
      await run('media/update', { id: item.id, ...draft, focal })
      invalidate('media/')
      toast.success(t('Saved'))
    } catch (e) {
      toast.error(e)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex min-w-0 flex-col">


      {item.kind === 'image' ? (
        <div
          className={cn('relative overflow-hidden rounded-md bg-sunken', editable && 'cursor-crosshair')}
          onClick={(e) => {
            if (!editable) return
            const r = e.currentTarget.getBoundingClientRect()
            setFocal({ x: Math.round(((e.clientX - r.left) / r.width) * 100), y: Math.round(((e.clientY - r.top) / r.height) * 100) })
          }}
          title={t('Click to set the focal point. Crops keep it in view.')}
        >
          <img src={item.thumb ?? item.url} alt="" className="block w-full" />
          <span className="pointer-events-none absolute size-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow-[0_0_0_1px_rgb(0_0_0/0.4)]" style={{ left: `${focal.x}%`, top: `${focal.y}%` }} />
        </div>
      ) : item.kind === 'video' ? (
        <video src={item.url} controls className="w-full rounded-md" />
      ) : (
        <div className="grid h-32 place-items-center rounded-md bg-sunken text-muted"><Icon name="file-text" size={28} /></div>
      )}

      <dl className="my-4 grid grid-cols-[80px_1fr] gap-y-1 text-xs">
        <dt className="text-faint">{t('Type')}</dt><dd className="m-0 truncate">{item.mime}</dd>
        <dt className="text-faint">{t('Size')}</dt><dd className="m-0">{formatBytes(item.size)}</dd>
        {item.width && <><dt className="text-faint">{t('Dimensions')}</dt><dd className="m-0">{item.width} × {item.height}</dd></>}
        <dt className="text-faint">{t('URL')}</dt>
        <dd className="m-0 flex items-center gap-1">
          <span className="truncate font-mono">{item.url}</span>
          <IconButton icon="copy" label={t('Copy URL')} size="xs" onClick={() => navigator.clipboard.writeText(location.origin + item.url).then(() => toast.success(t('URL copied')))} />
        </dd>
      </dl>

      <Fields
        className="gap-y-4!"
        disabled={!editable}
        fields={[
          { handle: 'name', type: 'text', label: t('Name'), size: 'sm' },
          ...(item.kind === 'image' ? [{ handle: 'alt', type: 'textarea', label: t('Alt Text'), rows: 2, instructions: draft.alt ? plural(draft.alt.length, '{count} character', '{count} characters') : t('Describe what the image shows, for screen readers and search.') }] : []),
          { handle: 'caption', type: 'textarea', label: t('Caption'), rows: 2 },
          { handle: 'folder', type: 'text', label: t('Folder'), size: 'sm', mono: true, placeholder: 'team/2026' },
        ]}
        values={draft}
        onChange={(h, v) => setDraft({ ...draft, [h]: v ?? '' })}
      />

      <div className="mt-5 flex items-center justify-between border-t border-border pt-4">
        {can('media.delete') ? (
          <Button
            size="sm"
            variant="ghost"
            className="text-danger"
            icon="trash"
            onClick={async () => {
              if (!(await confirm({ title: t('Delete “{name}”?', { name: item.name }), description: t('Pages that use it will show nothing in its place.'), confirm: t('Delete'), danger: true }))) return
              try {
                await run('media/delete', { id: item.id })
                invalidate('media/')
                onClose()
              } catch (error) { toast.error(error) }
            }}
          >
            {t('Delete')}
          </Button>
        ) : <span />}
        {editable && <Button size="sm" variant="primary" disabled={!dirty} loading={busy} onClick={save}>{t('Save')}</Button>}
      </div>
    </div>
  )
}
