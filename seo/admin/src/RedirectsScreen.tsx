import { invalidate, run, t, useAbility, type FieldDef, type RecordTimestamps } from '@letterncms/sdk'
import { Button, DataView, EmptyState, Fields, IconButton, Page, toast } from '@letterncms/ui'
import { useEffect, useState } from 'react'

interface Redirect extends RecordTimestamps {
  from: string
  to: string
  status: number
}

function fields(): Record<'filter' | 'from' | 'to' | 'status', FieldDef> {
  return {
    filter: { handle: 'filter', type: 'text', label: t('Filter'), icon: 'search', size: 'sm', placeholder: t('Filter…'), className: 'w-64' },
    from: { handle: 'from', type: 'text', label: t('From'), size: 'sm', mono: true, placeholder: '/old-url' },
    to: { handle: 'to', type: 'text', label: t('To'), size: 'sm', mono: true, placeholder: t('/new-url or https://…') },
    status: { handle: 'status', type: 'select', label: t('Status'), size: 'sm', required: true, numeric: true, options: { '301': t('301 Permanent'), '302': t('302 Temporary') } },
  }
}

export default function RedirectsScreen() {
  const data = useAbility<Redirect[]>('redirects/list', {}, { refetchOnWindowFocus: false })
  const [rows, setRows] = useState<Redirect[] | null>(null)
  const [busy, setBusy] = useState(false)
  const { from, to, status } = fields()

  useEffect(() => {
    if (data.data && rows === null) setRows(data.data)
  }, [data.data, rows])

  const dirty = rows !== null && JSON.stringify(rows) !== JSON.stringify(data.data)
  const add = () => setRows((r) => [{ from: '', to: '', status: 301, publishedAt: null, updatedAt: null }, ...(r ?? [])])
  const shown = (rows ?? []).map((r, i) => ({ r, i }))
  const set = (i: number, patch: Partial<Redirect>) => setRows((r) => r!.map((x, j) => (j === i ? { ...x, ...patch } : x)))

  const save = async () => {
    setBusy(true)
    try {
      const saved = await run<Redirect[]>('redirects/save', { redirects: rows!.filter((r) => r.from.trim() && r.to.trim()) })
      setRows(saved)
      invalidate('redirects/')
      toast.success(t('Redirects saved'))
    } catch (e) {
      toast.error(e)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Page
      width="wide"
      crumbs={[{ label: t('Redirects'), icon: 'arrow-up-right' }]}
      actions={<><Button icon="plus" onClick={add}>{t('New Redirect')}</Button><Button variant="primary" disabled={!dirty} loading={busy} onClick={save}>{t('Save')}</Button></>}
    >
      <DataView id="redirects" label={t('Redirects')} items={shown} dates={({ r }) => r} rowKey={({ i }) => String(i)} loading={!rows && data.isLoading} error={data.isError} onRetry={() => void data.refetch()}
        searchText={({ r }) => `${r.from} ${r.to}`}
        columns={[
          { id: 'from', label: t('From'), width: 'minmax(220px,1fr)', render: ({ r, i }) => <Fields bare fields={[from]} values={r} onChange={(h, v) => set(i, { [h]: v })} /> },
          { id: 'to', label: t('To'), width: 'minmax(220px,1fr)', render: ({ r, i }) => <Fields bare fields={[to]} values={r} onChange={(h, v) => set(i, { [h]: v })} /> },
          { id: 'status', label: t('Status'), width: '152px', render: ({ r, i }) => <Fields bare fields={[status]} values={r} onChange={(h, v) => set(i, { [h]: v })} /> },
        ]}
        actions={({ i }) => <IconButton icon="trash" label={t('Delete')} size="xs" onClick={() => setRows(rows!.filter((_, j) => j !== i))} />}
        empty={<EmptyState icon="arrow-up-right" title={t('No redirects')} description={t('Send visitors from an old URL to a new one when you move or remove a page.')} action={<Button icon="plus" onClick={add}>{t('New Redirect')}</Button>} />}
      />
    </Page>
  )
}
