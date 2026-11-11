import { invalidate, locale, run, t, useAbility, type SurfaceComponentProps } from '@letterncms/sdk'
import { Badge, Button, Dialog, QueryError, Spinner, formatBytes, toast } from '@letterncms/ui'
import { useEffect } from 'react'

interface Submission {
  id: string
  data: Record<string, unknown>
  files: { field: string; name: string; size: number }[]
  meta: { page?: string }
  status: 'new' | 'read' | 'spam' | 'archived'
  createdAt: string
  labels: Record<string, string>
}

const statusLabel = (status: Submission['status']) => ({ new: t('New'), read: t('Read'), spam: t('Spam'), archived: t('Archived') })[status]

/**
 * One submission, opened from a row of the Submissions tab (FormsViews): its answers and files,
 * replied to, archived or marked as spam. Opening a new one marks it read.
 */
export default function SubmissionDialog({ props, close }: SurfaceComponentProps<{ id: string }>) {
  const query = useAbility<Submission>('form-submissions/get', { id: props.id }, { refetchOnWindowFocus: false })
  const s = query.data
  // Opened, a new one is read: the list and the sidebar's count say so.
  useEffect(() => {
    if (s?.status !== 'new') return
    void run('form-submissions/update', { ids: [s.id], status: 'read' }).then(() => invalidate('form-submissions/', 'forms/list', 'forms/overview')).catch(() => {})
  }, [s?.id])

  const mark = async (status: Submission['status']) => {
    try {
      await run('form-submissions/update', { ids: [props.id], status })
      invalidate('forms/', 'form-submissions/')
      close?.()
    } catch (e) {
      toast.error(e)
    }
  }
  const email = typeof s?.data.email === 'string' ? s.data.email : null

  return (
    <Dialog open onOpenChange={(o) => !o && close?.()} title={t('Submission')} description={s ? new Date(s.createdAt).toLocaleString(locale(), { dateStyle: 'medium', timeStyle: 'short' }) : undefined} size="md" footer={s && (
      <>
        <Badge>{statusLabel(s.status)}</Badge>
        <span className="flex-1" />
        {email && <Button icon="mail" onClick={() => (location.href = `mailto:${email}`)}>{t('Reply')}</Button>}
        <Button onClick={() => void mark('spam')}>{t('Spam')}</Button>
        <Button variant="primary" onClick={() => void mark('archived')}>{t('Archive')}</Button>
      </>
    )}>
      {query.isError ? <QueryError onRetry={() => void query.refetch()} /> : !s ? <div className="grid place-items-center py-6 text-faint"><Spinner /></div> : (
        <dl className="m-0 grid grid-cols-[140px_1fr] gap-x-4 gap-y-3 text-sm max-sm:grid-cols-1 max-sm:gap-y-1">
          {Object.entries(s.data).map(([k, v]) => (
            <div key={k} className="contents">
              <dt className="text-muted">{s.labels[k] ?? k}</dt>
              <dd className="m-0 whitespace-pre-wrap break-words max-sm:mb-2">{Array.isArray(v) ? v.join(', ') : String(v ?? '')}</dd>
            </div>
          ))}
          {s.files.map((f, i) => (
            <div key={i} className="contents">
              <dt className="text-muted">{s.labels[f.field] ?? f.field}</dt>
              <dd className="m-0 max-sm:mb-2"><a href={`/api/admin/forms/files/${s.id}/${i}`} className="underline">{f.name}</a> <span className="text-xs text-faint">{formatBytes(f.size)}</span></dd>
            </div>
          ))}
          {s.meta.page && <div className="contents"><dt className="text-muted">{t('Page')}</dt><dd className="m-0 truncate text-xs">{s.meta.page}</dd></div>}
        </dl>
      )}
    </Dialog>
  )
}
