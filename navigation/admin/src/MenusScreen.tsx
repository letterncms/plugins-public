import { can, invalidate, navigate, plural, run, t, useAbility, type RecordTimestamps } from '@letterncms/sdk'
import { Button, DataView, Dialog, EmptyState, Fields, Icon, Page, toast } from '@letterncms/ui'
import { useState } from 'react'

interface MenuRow extends RecordTimestamps {
  handle: string
  title: string
  maxDepth: number
  count: number
}

export default function MenusScreen() {
  const list = useAbility<MenuRow[]>('navigation/list')
  const [open, setOpen] = useState(false)
  const manage = can('navigation.manage')

  return (
    <Page
      width="wide"
      crumbs={[{ label: t('Navigation'), icon: 'list-tree' }]}
      actions={manage && <Button variant="primary" icon="plus" onClick={() => setOpen(true)}>{t('New Menu')}</Button>}
    >
      <DataView id="navigation" label={t('Menus')} items={list.data ?? []} rowKey={(m) => m.handle} rowHref={(m) => `/navigation/${m.handle}`}
        loading={list.isLoading} error={list.isError} onRetry={() => void list.refetch()} searchText={(m) => `${m.title} ${m.handle}`}
        columns={[
          { id: 'title', label: t('Name'), value: (m) => m.title, render: (m) => <span className="flex items-center gap-2"><Icon name="list-tree" size={16} className="shrink-0 text-muted" /><span className="truncate">{m.title}</span></span> },
          { id: 'handle', label: t('Handle'), width: 'minmax(200px,1fr)', value: (m) => m.handle, render: (m) => <span className="font-mono text-xs">{m.handle}</span> },
          // Said in words, so it reads on its own where there are no column headers (a phone).
          { id: 'count', label: t('Links'), width: '100px', align: 'end', value: (m) => m.count, render: (m) => plural(m.count, '{count} link', '{count} links') },
        ]}
        empty={<EmptyState icon="list-tree" title={t('No menus yet')} description={t('Start with the main menu.')} />}
      />
      {open && <NewMenu onClose={() => setOpen(false)} taken={(list.data ?? []).map((m) => m.handle)} />}
    </Page>
  )
}

function NewMenu({ onClose, taken }: { onClose: () => void; taken: string[] }) {
  const [title, setTitle] = useState('')
  const [busy, setBusy] = useState(false)
  const handle = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 64)
  const exists = taken.includes(handle)

  const create = async () => {
    setBusy(true)
    try {
      await run('navigation/save', { handle, title, items: [] })
      invalidate('navigation/')
      navigate(`/navigation/${handle}`)
    } catch (e) {
      toast.error(e)
      setBusy(false)
    }
  }

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      size="sm"
      title={t('New Menu')}
      footer={<><Button onClick={onClose}>{t('Cancel')}</Button><Button variant="primary" disabled={!handle || exists} loading={busy} onClick={create}>{t('Create')}</Button></>}
    >
      <form onSubmit={(e) => { e.preventDefault(); if (handle && !exists) void create() }}>
        <Fields
          fields={[{ handle: 'title', type: 'text', label: t('Name'), autofocus: true, placeholder: t('Footer'), instructions: exists ? t('A menu called “{handle}” already exists.', { handle }) : handle ? t('Handle: {handle}', { handle }) : t('For example Main, Footer or Legal.') }]}
          values={{ title }}
          onChange={(_, v) => setTitle(v)}
        />
      </form>
    </Dialog>
  )
}
