import { can, invalidate, locale, plural, run, setQuery, t, useAbility, useLocation, type FieldDef } from '@letterncms/sdk'
import { Badge, Button, DataView, Dialog, EmptyState, Fields, Icon, IconButton, Menu, NoMatches, SelectionBar, confirm, cn, copyText, toast, readViewPrefs } from '@letterncms/ui'
import { useEffect, useState } from 'react'
import { formatBytes, libraryFolder, uploadAll, type MediaItem } from './upload'

const KINDS = ['all', 'image', 'video', 'audio', 'pdf', 'file'] as const
type Kind = (typeof KINDS)[number]

interface MediaFilters { search: string; kind: Kind; folder: string | null }
const mediaInput = (filters: MediaFilters, page: number, sort: string | null) => ({ search: filters.search || undefined, kind: filters.kind, folder: filters.folder, page, perPage: 60, sort: sort ?? undefined })

function filterFields(): FieldDef[] {
  return [
    { handle: 'search', type: 'text', label: t('Search Files'), icon: 'search', size: 'sm', placeholder: t('Search files…'), className: 'w-full sm:w-60' },
    { handle: 'kind', type: 'select', label: t('Kind'), display: 'segmented', required: true, options: { all: t('All'), image: t('Images'), video: t('Videos'), audio: t('Audio'), pdf: 'PDF', file: t('Other') } },
  ]
}

/**
 * The media library. In "manage" mode clicking opens details; in "pick" mode
 * clicking selects (for media fields and image blocks). `bar`: the page's bar has the
 * Upload button (the Media place, a view), so the library has none of its own.
 */
export function Library({ mode = 'manage', accept = '*', multiple = false, selected = [], onSelectionChange, autoUpload = false, bar = false }: { mode?: 'manage' | 'pick'; accept?: string; multiple?: boolean; selected?: string[]; onSelectionChange?: (ids: string[], items: MediaItem[]) => void; autoUpload?: boolean; bar?: boolean }) {
  const { query } = useLocation()
  // On the Media place the filters are in the address too: its count reads them (MediaPlugin::places), and a link keeps them.
  const [filters, setFilters] = useState<MediaFilters>(() => bar
    ? { search: query.search ?? '', kind: (KINDS as readonly string[]).includes(query.kind ?? '') ? query.kind as Kind : 'all', folder: query.folder || null }
    : { search: '', kind: accept.startsWith('image') ? 'image' : accept.startsWith('video') ? 'video' : accept === 'application/pdf' ? 'pdf' : 'all', folder: null })
  const { kind, folder } = filters
  useEffect(() => {
    if (bar) setQuery({ search: filters.search || null, kind: filters.kind === 'all' ? null : filters.kind, folder: filters.folder })
  }, [bar, filters])
  // The page's Upload puts files in the folder on view.
  useEffect(() => {
    if (!bar) return
    libraryFolder.current = folder ?? ''
    return () => { libraryFolder.current = '' }
  }, [bar, folder])
  const [page, setPage] = useState(1)
  const [sort, setSort] = useState(() => readViewPrefs('overview.media', 'grid').sort)
  const [chosen, setChosen] = useState<Set<string>>(new Set())
  const [deleting, setDeleting] = useState(false)
  const [open, setOpen] = useState<MediaItem | null>(null)
  const list = useAbility<{ items: MediaItem[]; total: number; page: number; pages: number; folders: string[] }>('media/list', mediaInput(filters, page, sort), { placeholderData: (p) => p })
  const items = list.data?.items ?? []
  const allow = can('media.upload')
  const filter = (handle: string, value: unknown) => { setPage(1); setChosen(new Set()); setFilters((f) => ({ ...f, [handle]: value })) }
  // The upload button, in the page's top bar or the picker's toolbar: the one way to add files.
  const uploader = (field: Partial<FieldDef>): FieldDef[] => [{ handle: 'files', type: 'json', component: 'media-upload', label: t('Upload'), multiple: mode === 'manage' || multiple, accept: accept === '*' ? undefined : accept, ...field }]

  const send = async (files: FileList | File[]) => {
    const done = await uploadAll(files, folder ?? '')
    if (done.length && mode === 'pick') {
      const ids = multiple ? [...selected, ...done.map((d) => d.id)] : [done[0].id]
      onSelectionChange?.(ids, done)
    }
  }

  const toggle = (item: MediaItem) => {
    if (mode === 'manage') return setOpen(item)
    const next = multiple ? (selected.includes(item.id) ? selected.filter((i) => i !== item.id) : [...selected, item.id]) : [item.id]
    onSelectionChange?.(next, [item])
  }

  const uploadButton = (autofocus = false) => allow ? <Fields layout="inline" bare fields={uploader({ variant: mode === 'manage' ? 'primary' : 'secondary', autofocus })} values={{}} onChange={(_, files) => void send(files)} /> : null

  const select = (ids: string[], on: boolean, records = items) => {
    if (mode === 'pick') {
      const next = multiple ? [...new Set(on ? [...selected, ...ids] : selected.filter((id) => !ids.includes(id)))] : on ? ids.slice(0, 1) : []
      onSelectionChange?.(next, records.filter((item) => next.includes(item.id)))
    } else setChosen((current) => { const next = new Set(current); ids.forEach((id) => on ? next.add(id) : next.delete(id)); return next })
  }
  const deleteItems = async (ids: string[]) => {
    const one = ids.length === 1 ? items.find((item) => item.id === ids[0]) : undefined
    if (deleting || !await confirm({ title: one ? t('Delete “{name}”?', { name: one.name }) : plural(ids.length, 'Delete {count} file?', 'Delete {count} files?'), description: t('Pages that use it will show nothing in its place.'), confirm: t('Delete'), danger: true })) return
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
  // An empty library is its empty state and the way to fill it (the top bar's Upload, or the picker's own): no filters, display or count yet.
  const blank = !filtered && !list.isError && list.data?.total === 0
  const body = (
    <div className="min-w-0">
      <DataView id="media" label={t('Media')} items={items} source={{ ability: 'media/list', input: mediaInput(filters, 1, sort), perPage: 60 }} rowKey={(item) => item.id} onRowClick={toggle} defaultView="grid" image={preview}
        pagination={list.data && { page: list.data.page, pages: list.data.pages, total: list.data.total, perPage: 60, onPage: setPage }}
        loading={list.isLoading} stale={list.isPlaceholderData} error={list.isError} onRetry={() => void list.refetch()}
        // Uploaded is a column of its own; a file is never "published".
        dates={(item) => ({ publishedAt: null, updatedAt: item.updatedAt })}
        sort={sort} dateSort={{ updated: 'updated_at' }} onSort={(sort) => { setPage(1); setSort(sort) }}
        selection={{ ids: mode === 'manage' ? chosen : new Set(selected), onSelect: select }}
        selected={(item) => open?.id === item.id}
        toolbar={blank ? undefined : <>
          <Fields layout="inline" bare className="flex min-w-0 flex-wrap items-center gap-2 max-sm:w-full" fields={[...filterFields(), ...(list.data?.folders.length ? [{ handle: 'folder', type: 'select', label: t('Folder'), size: 'sm' as const, placeholder: t('All Folders'), options: list.data.folders.map((f) => ({ value: f, label: f, icon: 'folder' })), className: 'w-44' }] : [])]} values={filters} onChange={filter} />
          {!bar && uploadButton(autoUpload)}
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
          { label: t('Copy URL'), icon: 'copy', onSelect: () => void copyText(new URL(item.url, location.origin).href, { copied: t('URL copied'), failed: t('Couldn’t copy the URL') }) },
          mode === 'manage' && can('media.delete') && { label: t('Delete'), icon: 'trash', danger: true, disabled: deleting, onSelect: () => void deleteItems([item.id]) },
        ]} />}
        empty={filtered ? <NoMatches onClear={() => { setFilters({ search: '', kind: 'all', folder: null }); setPage(1) }} /> : <EmptyState icon="media" title={t('No files yet')} description={allow ? t('Images, videos and documents for your pages.') : undefined} action={bar ? undefined : uploadButton()} />}
      />

      {/* Opened to upload (?upload=1): the picker, with the page's own button in its bar. */}
      {bar && autoUpload && <span className="hidden">{uploadButton(true)}</span>}

      {mode === 'manage' && <SelectionBar count={chosen.size} onClear={() => setChosen(new Set())}>{can('media.delete') && <Button size="sm" variant="ghost" loading={deleting} onClick={() => void deleteItems([...chosen])}>{t('Delete')}</Button>}</SelectionBar>}

      {mode === 'manage' && open && <Dialog open onOpenChange={(next) => !next && setOpen(null)} title={open.name}><Details key={open.id} item={items.find((i) => i.id === open.id) ?? open} onClose={() => setOpen(null)} /></Dialog>}
    </div>
  )

  return body
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
          <IconButton icon="copy" label={t('Copy URL')} size="xs" onClick={() => void copyText(new URL(item.url, location.origin).href, { copied: t('URL copied'), failed: t('Couldn’t copy the URL') })} />
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
