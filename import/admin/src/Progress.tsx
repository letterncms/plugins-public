import { CmsError, queryClient, run, t, useAbility, type SurfaceComponentProps } from '@letterncms/sdk'
import { Button, Meter, QueryError, Stat, toast } from '@letterncms/ui'
import { useEffect, useState } from 'react'

interface Run {
  id: string
  path: string
  status: 'running' | 'done' | 'failed' | 'canceled'
  records: number
  done: number
  summary: { create: number; update: number; skip: number; failed: number; media: number; redirects: number; warnings: number }
}

/**
 * An import that is running. While it is on screen it is moved on a turn at a time, each a few
 * seconds of importing; closing the page or reloading it loses nothing, because how far it is
 * lives on the server. When it is finished the page shows what it did.
 */
export default function Progress({ props }: SurfaceComponentProps<{ id: string }>) {
  const { id } = props
  const first = useAbility<Run>('import-runs/get', { id, perPage: 1 }, { refetchOnWindowFocus: false })
  const [state, setState] = useState<Run | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [attempt, setAttempt] = useState(0)
  const [canceling, setCanceling] = useState(false)
  const now = state ?? first.data ?? null

  useEffect(() => {
    let on = true
    setError(null)
    void (async () => {
      let before = -1
      while (on) {
        try {
          const next = await run<Run>('import-runs/step', { id })
          if (!on) return
          setState(next)
          // Nothing moved (another request holds the turn, or it found nothing new yet): ask again in a second.
          if (next.status === 'running' && next.done === before) await new Promise((resolve) => setTimeout(resolve, 1000))
          before = next.done
          if (next.status !== 'running') {
            // The page is a view of the import: asked again, it is the report.
            void queryClient.invalidateQueries()
            return
          }
        } catch (e) {
          if (on) setError(e)
          return
        }
      }
    })()
    return () => {
      on = false
    }
  }, [id, attempt])

  const cancel = async () => {
    setCanceling(true)
    try {
      await run('import-runs/cancel', { id })
      void queryClient.invalidateQueries()
    } catch (e) {
      toast.error(e)
      setCanceling(false)
    }
  }

  if (error) {
    return <QueryError title={t('The import stopped')} message={error instanceof CmsError ? error.message : undefined} onRetry={() => setAttempt((n) => n + 1)} />
  }

  const share = now && now.records > 0 ? now.done / now.records : 0
  const label = now ? t('{done} of {total} entries', { done: now.done.toLocaleString(), total: now.records.toLocaleString() }) : t('Starting…')

  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <div className="flex items-baseline justify-between gap-4 text-sm">
          <span className="font-medium text-ink">{t('Importing')}</span>
          <span className="tabular-nums text-muted" aria-live="polite">{label}</span>
        </div>
        <Meter value={share} label={label} />
      </div>
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <Stat size="sm" label={t('Created')} value={(now?.summary.create ?? 0).toLocaleString()} />
        <Stat size="sm" label={t('Updated')} value={(now?.summary.update ?? 0).toLocaleString()} />
        <Stat size="sm" label={t('Unchanged')} value={(now?.summary.skip ?? 0).toLocaleString()} />
        <Stat size="sm" label={t('Failed')} value={(now?.summary.failed ?? 0).toLocaleString()} />
      </div>
      <Button onClick={() => void cancel()} loading={canceling}>{t('Cancel Import')}</Button>
    </div>
  )
}
