import { invalidate, run, t, useAbility, type FieldDef, type RecordTimestamps } from '@letterncms/sdk'
import { DataView, EmptyState, Fields, IconButton, SaveBar } from '@letterncms/ui'
import { adding } from './NewRedirect'
import { useEffect, useState } from 'react'

interface Redirect extends RecordTimestamps {
  from: string
  to: string
  status: number
}

function fields(): Record<'from' | 'to' | 'status', FieldDef> {
  return {
    from: { handle: 'from', type: 'text', label: t('From'), size: 'sm', mono: true, placeholder: '/old-url' },
    to: { handle: 'to', type: 'text', label: t('To'), size: 'sm', mono: true, placeholder: t('/new-url or https://…') },
    status: { handle: 'status', type: 'select', label: t('Status'), size: 'sm', required: true, numeric: true, options: { '301': t('301 Permanent'), '302': t('302 Temporary') } },
  }
}

/** The Redirects tab of the SEO settings: its own list, saved from the save bar like the settings beside it. */
export default function Redirects(_: { namespace: string; active: boolean }) {
  const data = useAbility<Redirect[]>('redirects/list', {}, { refetchOnWindowFocus: false })
  const [rows, setRows] = useState<Redirect[] | null>(null)
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState<string | null>(null)
  const { from, to, status } = fields()

  useEffect(() => {
    if (data.data && rows === null) setRows(data.data)
  }, [data.data, rows])

  const dirty = rows !== null && JSON.stringify(rows) !== JSON.stringify(data.data)

  const add = () => setRows((r) => [{ from: '', to: '', status: 301, publishedAt: null, updatedAt: null }, ...(r ?? data.data ?? [])])
  useEffect(() => {
    adding.addEventListener('add', add)
    return () => adding.removeEventListener('add', add)
  })
  const shown = (rows ?? []).map((r, i) => ({ r, i }))
  const set = (i: number, patch: Partial<Redirect>) => setRows((r) => r!.map((x, j) => (j === i ? { ...x, ...patch } : x)))

  const save = async () => {
    if (!dirty) return
    setBusy(true)
    setFailed(null)
    try {
      const saved = await run<Redirect[]>('redirects/save', { redirects: rows!.filter((r) => r.from.trim() && r.to.trim()) })
      setRows(saved)
      invalidate('redirects/')
    } catch (e) {
      setFailed(e instanceof Error ? e.message : String(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
    <SaveBar dirty={dirty} saving={busy} error={failed} onSave={() => void save()} onDiscard={() => { setRows(data.data ?? null); setFailed(null) }} />
    <DataView id="redirects" label={t('Redirects')} items={shown} dates={({ r }) => r} rowKey={({ i }) => String(i)} loading={!rows && data.isLoading} error={data.isError} onRetry={() => void data.refetch()}
      searchText={({ r }) => `${r.from} ${r.to}`}
      columns={[
        { id: 'from', label: t('From'), width: 'minmax(180px,1fr)', render: ({ r, i }) => <Fields bare fields={[from]} values={r} onChange={(h, v) => set(i, { [h]: v })} /> },
        { id: 'to', label: t('To'), width: 'minmax(180px,1fr)', render: ({ r, i }) => <Fields bare fields={[to]} values={r} onChange={(h, v) => set(i, { [h]: v })} /> },
        { id: 'status', label: t('Status'), width: '152px', render: ({ r, i }) => <Fields bare fields={[status]} values={r} onChange={(h, v) => set(i, { [h]: v })} /> },
      ]}
      actions={({ i }) => <IconButton icon="trash" label={t('Delete')} size="xs" onClick={() => setRows(rows!.filter((_, j) => j !== i))} />}
      empty={<EmptyState icon="arrow-up-right" title={t('No redirects')} />}
    />
    </>
  )
}
