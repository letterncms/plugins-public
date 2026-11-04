import { EditorProvider, EditorStore, WriteEditor, useCatalog, type BlockNode } from '@letterncms/blocks'
import { blockNavigation, can, invalidate, locale, navigate, plural, run, t, timeAgo, useAbility, useManifest, useTab, type FieldDef, type RecordTimestamps } from '@letterncms/sdk'
import { Badge, Button, DataView, Dialog, EmptyState, Fields, Page, SelectionBar, Spinner, Tabs, confirm, toast } from '@letterncms/ui'
import { useEffect, useMemo, useRef, useState } from 'react'

interface Form {
  handle: string
  title: string
  fields: BlockNode[]
  settings: Record<string, any>
  locales?: Record<string, FormWords>
}

/** A form's wording in one language: only what a visitor reads. */
interface FormWords {
  settings?: Record<string, string>
  fields?: Record<string, Record<string, string>>
}

interface Submission extends RecordTimestamps {
  id: string
  data: Record<string, any>
  files: { field: string; name: string; size: number }[]
  meta: { page?: string }
  status: 'new' | 'read' | 'spam' | 'archived'
  createdAt: string
}

function settingsFields(): FieldDef[] {
  return [
    { handle: 'submit', type: 'text', label: t('Button Label'), width: 'half' },
    { handle: 'enabled', type: 'toggle', label: t('Accept Submissions'), width: 'half' },
    { handle: 'success', type: 'textarea', label: t('Success Message'), rows: 2 },
    { handle: 'redirect', type: 'url', component: 'link', label: t('Or Redirect To'), placeholder: t('A Thank-You Page') },
    { handle: 'notify', type: 'tags', label: t('Email Submissions To'), placeholder: 'name@example.com' },
    { handle: 'subject', type: 'text', label: t('Email Subject'), instructions: t('Use {form} or any {field_name}.') },
    { handle: 'reply_to_field', type: 'text', label: t('Reply-to Field'), instructions: t('The field that holds the sender’s email.'), width: 'half' },
    { handle: 'store', type: 'toggle', label: t('Keep Submissions in {brand}'), width: 'half' },
    { handle: 'confirmation', type: 'toggle', label: t('Send the Person a Confirmation Email') },
    { handle: 'confirmation_subject', type: 'text', label: t('Confirmation Subject'), if: { confirmation: true } },
    { handle: 'confirmation_message', type: 'richtext', label: t('Confirmation Message'), if: { confirmation: true } },
    { handle: 'honeypot', type: 'toggle', label: t('Hidden Spam Trap'), width: 'half' },
    { handle: 'min_seconds', type: 'number', label: t('Minimum Seconds to Fill In'), min: 0, max: 60, width: 'half' },
    { handle: 'webhook', type: 'url', label: t('Webhook'), instructions: t('Each submission is POSTed as JSON to this https:// URL (Zapier, Make, n8n, your CRM).') },
  ]
}

/** Fields of a form tree with the words they show a visitor, nested ones included. */
function wordedFields(nodes: BlockNode[]): { name: string; label: string; words: string[]; props: Record<string, any> }[] {
  return nodes.flatMap((node) => {
    const props = (node.props ?? {}) as Record<string, any>
    const own = typeof props.name === 'string' && node.type !== 'form/hidden'
      ? [{ name: props.name, label: String(props.label ?? props.name), words: ['label', 'help', 'placeholder', 'options', 'text'].filter((key) => typeof props[key] === 'string' && props[key] !== ''), props }]
      : []
    return [...own, ...wordedFields(node.children ?? [])]
  })
}

/** The form in the site's other languages: empty fields fall back to the form's own wording. */
function FormLanguages({ fields, settings, locales, disabled, onChange }: { fields: BlockNode[]; settings: Record<string, any>; locales: Record<string, FormWords>; disabled: boolean; onChange: (next: Record<string, FormWords>) => void }) {
  const site = useManifest().site
  const languages: string[] = site.locales.filter((code: string) => code !== site.defaultLocale)
  const [code, setCode] = useState(languages[0])
  const name = (c: string) => new Intl.DisplayNames([c], { type: 'language' }).of(c) ?? c
  const words = locales[code] ?? {}
  const worded = wordedFields(fields)
  const defs: FieldDef[] = [
    { handle: 's:submit', type: 'text', label: t('Button Label'), placeholder: settings.submit, width: 'half' },
    { handle: 's:success', type: 'textarea', label: t('Success Message'), placeholder: settings.success, rows: 2 },
    ...(settings.confirmation
      ? [
          { handle: 's:confirmation_subject', type: 'text', label: t('Confirmation Subject'), placeholder: settings.confirmation_subject } as FieldDef,
          { handle: 's:confirmation_message', type: 'richtext', label: t('Confirmation Message') } as FieldDef,
        ]
      : []),
    ...worded.flatMap((field) => field.words.map((key): FieldDef => ({
      handle: `f:${field.name}:${key}`,
      type: key === 'options' ? 'textarea' : 'text',
      label: `${field.label} · ${({ label: t('Label'), help: t('Help Text'), placeholder: t('Placeholder'), options: t('Options'), text: t('Statement') } as Record<string, string>)[key]}`,
      placeholder: field.props[key],
      instructions: key === 'options' ? t('Keep each value: value|Label.') : undefined,
    }))),
  ]
  const values = Object.fromEntries(defs.map((d) => {
    const [kind, a, b] = d.handle.split(':')
    return [d.handle, (kind === 's' ? words.settings?.[a] : words.fields?.[a]?.[b]) ?? '']
  }))

  return (
    <div className="max-w-[640px]">
      {languages.length > 1 && <Tabs value={code} onChange={setCode} tabs={languages.map((c) => ({ value: c, label: name(c) }))} className="mb-6" />}
      <Fields
        fields={defs}
        values={values}
        disabled={disabled}
        onChange={(handle, value) => {
          const [kind, a, b] = handle.split(':')
          onChange({
            ...locales,
            [code]: kind === 's'
              ? { ...words, settings: { ...words.settings, [a]: value } }
              : { ...words, fields: { ...words.fields, [a]: { ...words.fields?.[a], [b]: value } } },
          })
        }}
      />
    </div>
  )
}

const nameField = (): FieldDef => ({ handle: 'title', type: 'text', label: t('Form Name') })
const filterFields = (): FieldDef[] => [
  { handle: 'search', type: 'text', label: t('Search Submissions'), icon: 'search', size: 'sm', placeholder: t('Search…'), className: 'w-full sm:w-56' },
  { handle: 'status', type: 'select', label: t('Show'), display: 'segmented', required: true, options: { all: t('Inbox'), new: t('Unread'), archived: t('Archived'), spam: t('Spam') } },
]
const statusLabel = (status: Submission['status']) => ({ new: t('New'), read: t('Read'), spam: t('Spam'), archived: t('Archived') })[status]

const ALLOWED_TOP = ['paragraph', 'heading', 'columns', 'divider', 'callout']

export function FormBuilder({ params }: { params: Record<string, string> }) {
  const form = useAbility<Form>('forms/get', { handle: params.handle }, { refetchOnWindowFocus: false })
  if (form.isLoading) return <div className="grid h-full place-items-center text-faint"><Spinner /></div>
  if (!form.data) return <Page crumbs={[{ label: t('Forms'), to: '/forms', icon: 'form' }, { label: t('Not Found') }]}><EmptyState title={t('Form not found')} /></Page>
  return <Builder key={form.data.handle} form={form.data} />
}

function Builder({ form }: { form: Form }) {
  const [tab, setTab] = useTab(['fields', 'settings', 'languages', 'submissions'])
  const multilingual = useManifest().site.locales.length > 1
  const [locales, setLocales] = useState<Record<string, FormWords>>(form.locales ?? {})
  const { catalog, types } = useCatalog()
  const [title, setTitle] = useState(form.title)
  const [settings, setSettings] = useState(form.settings)
  const [dirty, setDirty] = useState(false)
  const [busy, setBusy] = useState(false)
  const fields = useRef<BlockNode[]>(form.fields)
  const store = useMemo(() => new EditorStore(form.fields, (n) => { fields.current = n; setDirty(true) }), [])
  const manage = can('forms.manage')
  const allowed = useMemo(() => [...[...types.keys()].filter((type) => type.startsWith('form/') && type !== 'forms/form'), ...ALLOWED_TOP], [types])

  useEffect(() => blockNavigation(() => (dirty ? t('Discard unsaved changes to this form?') : null)), [dirty])

  const save = async () => {
    setBusy(true)
    try {
      await run('forms/save', { handle: form.handle, title, fields: fields.current, settings, locales })
      setDirty(false)
      invalidate('forms/')
      toast.success(t('Form saved'))
    } catch (e) {
      toast.error(e)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Page
      width="default"
      crumbs={[{ label: t('Forms'), to: '/forms', icon: 'form' }, { label: title || form.handle }]}
      actions={
        manage && (
          <>
            <Button
              variant="ghost"
              icon="trash"
              onClick={async () => {
                if (!(await confirm({ title: t('Delete “{title}”?', { title: title || form.handle }), description: t('Submissions are kept. Pages that embed it will show nothing.'), confirm: t('Delete'), danger: true }))) return
                await run('forms/delete', { handle: form.handle }).catch(toast.error)
                setDirty(false)
                invalidate('forms/')
                navigate('/forms')
              }}
            >
              {t('Delete')}
            </Button>
            <Button variant="primary" disabled={!dirty} loading={busy} onClick={save}>{t('Save')}</Button>
          </>
        )
      }
    >
      <Fields bare fields={[nameField()]} values={{ title }} disabled={!manage} onChange={(_, v) => { setTitle(v); setDirty(true) }} className="mb-6 [&_input]:!h-auto [&_input]:!border-0 [&_input]:!p-0 [&_input]:!text-2xl [&_input]:!font-semibold [&_input]:!shadow-none" />
      <Tabs value={tab} onChange={setTab} tabs={[{ value: 'fields', label: t('Fields') }, { value: 'settings', label: t('Settings') }, ...(multilingual ? [{ value: 'languages' as const, label: t('Languages') }] : []), { value: 'submissions', label: t('Submissions') }]} className="mb-8" />

      {tab === 'fields' && catalog && (
        <div className="max-w-[640px] pl-14">
          <EditorProvider value={{ store, types, catalog, entryId: null, fieldHandle: 'fields', allowed, getValues: () => ({}), title }}>
            <WriteEditor />
          </EditorProvider>
          <p className="mt-2 text-xs text-faint">{t('Type / to add fields. Use Columns to put fields side by side.')}</p>
        </div>
      )}

      {tab === 'settings' && (
        <div className="max-w-[640px]">
          <Fields fields={settingsFields()} values={settings} disabled={!manage} onChange={(h, v) => { setSettings({ ...settings, [h]: v }); setDirty(true) }} />
        </div>
      )}

      {tab === 'languages' && multilingual && (
        <FormLanguages fields={fields.current} settings={settings} locales={locales} disabled={!manage} onChange={(next) => { setLocales(next); setDirty(true) }} />
      )}

      {tab === 'submissions' && <Submissions form={form} />}
    </Page>
  )
}

function Submissions({ form }: { form: Form }) {
  const [status, setStatus] = useState<'all' | 'new' | 'spam' | 'archived'>('all')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [open, setOpen] = useState<Submission | null>(null)
  const list = useAbility<{ items: Submission[]; total: number; page: number; pages: number }>('forms/submissions', { form: form.handle, status, search: search || undefined, page, perPage: 100 })
  const items = list.data?.items ?? []
  const columns = useMemo(() => {
    const names = new Set<string>()
    items.forEach((s) => Object.keys(s.data).forEach((k) => names.add(k)))
    return [...names].slice(0, 3)
  }, [items])

  const mark = async (ids: string[], to: Submission['status']) => {
    await run('forms/submission-update', { ids, status: to }).catch(toast.error)
    setSelected(new Set())
    invalidate('forms/')
  }

  const exportCsv = async () => {
    const res = await run<{ filename: string; csv: string }>('forms/export', { form: form.handle })
    const url = URL.createObjectURL(new Blob([res.csv], { type: 'text/csv' }))
    const a = Object.assign(document.createElement('a'), { href: url, download: res.filename })
    a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div>
      <DataView id={`submissions.${form.handle}`} label={t('Submissions')} items={items} source={{ ability: 'forms/submissions', input: { form: form.handle, status, search: search || undefined }, perPage: 100 }} rowKey={(s) => s.id}
        pagination={list.data && { page: list.data.page, pages: list.data.pages, total: list.data.total, perPage: 100, onPage: setPage }}
        onRowClick={(s) => { setOpen(s); if (s.status === 'new') void mark([s.id], 'read') }}
        loading={list.isLoading} error={list.isError} onRetry={() => void list.refetch()}
        selection={{ ids: selected, onSelect: (ids, on) => setSelected((current) => { const next = new Set(current); ids.forEach((id) => on ? next.add(id) : next.delete(id)); return next }) }}
        toolbar={<>
          <Fields layout="inline" bare className="flex flex-wrap items-center gap-2 max-sm:w-full" fields={filterFields()} values={{ search, status }} onChange={(h, v) => { setPage(1); setSelected(new Set()); h === 'search' ? setSearch(v) : setStatus(v) }} />
          <Button size="sm" icon="download" onClick={exportCsv}>{t('Export CSV')}</Button>
        </>}
        columns={[
          ...columns.map((c) => ({ id: c, label: c, render: (s: Submission) => <span className={s.status === 'new' ? 'font-medium text-text' : 'font-normal'}>{String(Array.isArray(s.data[c]) ? s.data[c].join(', ') : s.data[c] ?? '—')}</span> })),
          { id: '_status', label: t('Status'), width: '100px', render: (s) => <Badge tone={s.status === 'new' ? 'info' : 'neutral'}>{statusLabel(s.status)}</Badge> },
          { id: '_date', label: t('Received'), width: '120px', align: 'end', render: (s) => timeAgo(s.createdAt) },
        ]}
        empty={<EmptyState icon="inbox" title={t('No submissions')} description={status === 'spam' ? t('Nothing caught as spam.') : undefined} />}
      />

      <SelectionBar count={selected.size} onClear={() => setSelected(new Set())}>
        {(['read', 'archived', 'spam'] as const).map((s) => <Button key={s} size="sm" variant="ghost" onClick={() => mark([...selected], s)}>{s === 'read' ? t('Mark Read') : s === 'archived' ? t('Archive') : t('Spam')}</Button>)}
        {can('forms.manage') && <Button size="sm" variant="ghost" onClick={async () => {
          if (await confirm({ title: plural(selected.size, 'Delete {count} submission?', 'Delete {count} submissions?'), description: t('This cannot be undone.'), confirm: t('Delete'), danger: true })) {
            try { await run('forms/submission-delete', { ids: [...selected] }); setSelected(new Set()); invalidate('forms/') } catch (error) { toast.error(error) }
          }
        }}>{t('Delete')}</Button>}
      </SelectionBar>

      {open && (
        <Dialog open onOpenChange={() => setOpen(null)} title={t('Submission')} description={new Date(open.createdAt).toLocaleString(locale(), { dateStyle: 'medium', timeStyle: 'short' })} size="md" footer={
          <>
            <Badge>{statusLabel(open.status)}</Badge>
            <span className="flex-1" />
            {open.data.email && <Button icon="mail" onClick={() => (location.href = `mailto:${open.data.email}`)}>{t('Reply')}</Button>}
            <Button onClick={() => { void mark([open.id], 'spam'); setOpen(null) }}>{t('Spam')}</Button>
            <Button variant="primary" onClick={() => { void mark([open.id], 'archived'); setOpen(null) }}>{t('Archive')}</Button>
          </>
        }>
          <dl className="m-0 grid grid-cols-[140px_1fr] gap-x-4 gap-y-3 text-sm">
            {Object.entries(open.data).map(([k, v]) => (
              <div key={k} className="contents">
                <dt className="text-muted">{k}</dt>
                <dd className="m-0 whitespace-pre-wrap break-words">{Array.isArray(v) ? v.join(', ') : String(v)}</dd>
              </div>
            ))}
            {open.files.map((f, i) => (
              <div key={i} className="contents">
                <dt className="text-muted">{f.field}</dt>
                <dd className="m-0"><a href={`/api/admin/forms/files/${open.id}/${i}`} className="underline">{f.name}</a> <span className="text-xs text-faint">{new Intl.NumberFormat(locale(), { style: 'unit', unit: 'kilobyte', maximumFractionDigits: 0 }).format(f.size / 1024)}</span></dd>
              </div>
            ))}
            {open.meta.page && <><dt className="text-muted">{t('Page')}</dt><dd className="m-0 truncate text-xs">{open.meta.page}</dd></>}
          </dl>
        </Dialog>
      )}
    </div>
  )
}
