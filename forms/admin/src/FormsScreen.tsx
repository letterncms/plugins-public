import { can, invalidate, navigate, run, setQuery, t, useAbility, useLocation, type RecordTimestamps } from '@letterncms/sdk'
import { Badge, Button, DataView, Dialog, EmptyState, Fields, Icon, IconButton, Menu, Page, ScreenTabs, StatusDot, toast, useScreenTabs } from '@letterncms/ui'
import { useEffect, useState } from 'react'

interface FormRow extends RecordTimestamps {
  handle: string
  title: string
  fields: number
  enabled: boolean
  new: number
  total: number
}

export default function FormsScreen() {
  const list = useAbility<FormRow[]>('forms/list')
  // `?new=1` opens the new-form dialog: the way in from a Form block with no forms to show.
  const { query } = useLocation()
  const [open, setOpen] = useState(() => query.new === '1' && can('forms.manage'))
  useEffect(() => { if (query.new) setQuery({ new: null }) }, [query.new])
  const [tab, setTab] = useScreenTabs(['forms'])

  return (
    <Page
      width="wide"
      crumbs={[{ label: t('Forms'), icon: 'form' }]}
      actions={tab === 'forms' && can('forms.manage') && <Button variant="primary" icon="plus" onClick={() => setOpen(true)}>{t('New Form')}</Button>}
    >
      <ScreenTabs value={tab} onChange={setTab} tabs={[{ value: 'forms', label: t('Forms') }]}>
      <DataView id="forms" label={t('Forms')} items={list.data ?? []} rowKey={(f) => f.handle} rowHref={(f) => `/forms/${f.handle}`}
        loading={list.isLoading} error={list.isError} onRetry={() => void list.refetch()} searchText={(f) => `${f.title} ${f.handle}`}
        columns={[
          { id: 'title', label: t('Name'), value: (f) => f.title, render: (f) => <span className="flex items-center gap-2"><Icon name="form" size={16} className="shrink-0 text-muted" /><span className="truncate">{f.title}</span></span> },
          { id: 'status', label: t('Status'), width: '120px', value: (f) => Number(f.enabled), render: (f) => <StatusDot status={f.enabled ? 'success' : 'neutral'} label={f.enabled ? t('On') : t('Off')} /> },
          { id: 'fields', label: t('Fields'), width: '90px', align: 'end', value: (f) => f.fields, render: (f) => f.fields },
          { id: 'total', label: t('Submissions'), width: '120px', align: 'end', value: (f) => f.total, render: (f) => f.total },
          { id: 'new', label: t('Unread'), width: '90px', align: 'end', value: (f) => f.new, render: (f) => f.new > 0 ? <Badge tone="info">{f.new}</Badge> : '—' },
        ]}
        actions={(f) => <Menu trigger={<IconButton icon="more" label={t('Actions for {name}', { name: f.title })} size="xs" />} items={[
          { label: t('Fields'), icon: 'field', onSelect: () => navigate(`/forms/${f.handle}`) },
          { label: t('Submissions'), icon: 'inbox', onSelect: () => navigate(`/forms/${f.handle}?tab=submissions`) },
          can('forms.manage') && { label: t('Settings'), icon: 'settings', onSelect: () => navigate(`/forms/${f.handle}?tab=settings`) },
        ]} />}
        empty={<EmptyState icon="form" title={t('No forms yet')} description={t('Build one with the same editor you write pages with.')} />}
      />
      </ScreenTabs>
      {open && <NewForm onClose={() => setOpen(false)} />}
    </Page>
  )
}

function NewForm({ onClose }: { onClose: () => void }) {
  const [title, setTitle] = useState('')
  const [busy, setBusy] = useState(false)
  const handle = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')

  const create = async () => {
    setBusy(true)
    try {
      await run('forms/save', {
        handle,
        title,
        fields: [
          { type: 'form/text', props: { label: t('Name'), name: 'name', required: true, autocomplete: 'name' } },
          { type: 'form/email', props: { label: t('Email'), name: 'email', required: true } },
          { type: 'form/textarea', props: { label: t('Message'), name: 'message', required: true } },
        ],
      })
      invalidate('forms/')
      navigate(`/forms/${handle}`)
    } catch (e) {
      toast.error(e)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} size="sm" title={t('New Form')} description={t('Starts with name, email and message.')} footer={<><Button onClick={onClose}>{t('Cancel')}</Button><Button variant="primary" disabled={!handle} loading={busy} onClick={create}>{t('Create')}</Button></>}>
      <Fields fields={[{ handle: 'title', type: 'text', label: t('Name'), autofocus: true, placeholder: t('Quote Request'), instructions: handle ? t('Handle: {handle}', { handle }) : undefined }]} values={{ title }} onChange={(_, v) => setTitle(v)} />
    </Dialog>
  )
}
