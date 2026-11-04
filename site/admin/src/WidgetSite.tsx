import { can, plural, run, t, timeAgo, useAbility, useManifest } from '@letterncms/sdk'
import { Button, Icon, Meter, Skeleton, Stat, Widget, compactNumber, cn, toast } from '@letterncms/ui'
import { useEffect, useRef, useState } from 'react'

interface Status {
  state: 'idle' | 'queued' | 'running' | 'done'
  progress: number
  message: string
  pending: number
  finishedAt: string | null
  pages: number
  errors: string[]
  enabled: boolean
}

type Tone = 'success' | 'warning' | 'danger' | 'neutral'

/**
 * Home-screen widget: is the live site up to date, and a way to publish it.
 * Polling the build status also advances queued work, so it polls fast only while publishing.
 */
export default function WidgetSite() {
  const manifest = useManifest()
  const [requested, setRequested] = useState(false)
  const status = useAbility<Status>('site/build-status', {}, { refetchInterval: (q) => (q.state.data && working(q.state.data) ? 1500 : 60_000) })
  const s = status.data
  const busy = requested || s?.state === 'running'
  // Set when this widget asked for a publish: only then does finishing it get a toast.
  const asked = useRef(false)

  useEffect(() => {
    if (!s || !asked.current || s.state !== 'done') return
    asked.current = false
    setRequested(false)
    if (s.errors.length) toast.error(plural(s.errors.length, 'Published, but {count} page failed', 'Published, but {count} pages failed'))
    else toast.success(t('Site published'), { description: plural(s.pages, '{count} page is live.', '{count} pages are live.') })
  }, [s?.state, s?.finishedAt])

  const publish = async () => {
    setRequested(true)
    try {
      await run('site/build')
      asked.current = true
      void status.refetch()
    } catch (e) {
      setRequested(false)
      toast.error(e)
    }
  }

  const host = manifest.site.url.replace(/^https?:\/\//, '').replace(/\/$/, '')
  const [tone, label] = s ? summary(s) : ['neutral' as Tone, '']
  const mayPublish = !!s?.enabled && can('site.build')
  // Beside the status in the wide column, full width under it in the narrow one.
  const publishButton = (wide: boolean) =>
    mayPublish && (
      <div className={wide ? 'hidden shrink-0 @md:block' : '@md:hidden'}>
        {/* The signal color only while there is something to put live. */}
        <Button variant={tone === 'success' && !busy ? 'secondary' : 'publish'} icon="rocket" loading={busy} onClick={publish} className={wide ? undefined : 'w-full'}>
          {t('Publish Site')}
        </Button>
      </div>
    )

  return (
    <Widget query={status} skeleton={<SiteSkeleton />}>
      {s && (
        <div className="space-y-4">
          <div className="flex items-start gap-3">
            <div className="min-w-0 flex-1">
              <p className="m-0 flex items-center gap-2 text-sm font-medium text-text">
                <span className={cn('size-2 shrink-0 rounded-full', { success: 'bg-success', warning: 'bg-warning ring-3 ring-warning/20', danger: 'bg-danger', neutral: 'bg-faint' }[tone])} />
                {label}
              </p>
              {s.state === 'queued' || s.state === 'running' ? (
                <p className="m-0 mt-0.5 truncate pl-4 text-xs text-muted">{s.message || t('Changes')}</p>
              ) : (
                <a href={manifest.site.url} target="_blank" rel="noreferrer" className="group mt-0.5 ml-4 inline-flex max-w-[calc(100%-1rem)] items-center gap-1 text-xs text-muted no-underline hover:text-text">
                  <span className="truncate">{host}</span>
                  <Icon name="arrow-up-right" size={11} className="shrink-0 text-faint group-hover:text-text" />
                </a>
              )}
            </div>
            {publishButton(true)}
          </div>

          {s.state === 'running' && <Meter value={Math.max(0.04, s.progress)} label={t('Publishing Progress')} />}

          {s.enabled ? (
            <div className="grid grid-cols-2 gap-4 @md:grid-cols-4">
              <Stat size="sm" label={t('Pages')} value={compactNumber(s.pages)} />
              <Stat size="sm" label={t('Last Published')} value={s.finishedAt ? timeAgo(s.finishedAt) : t('Never')} />
            </div>
          ) : (
            <p className="m-0 text-xs text-muted">{t('Pages render on request.')}</p>
          )}

          {s.errors.length > 0 && !busy && (
            <div className="border-t border-border pt-3">
              <p className="m-0 mb-1 flex items-center gap-1.5 text-xs font-medium text-danger">
                <Icon name="warning" size={12} />
                {plural(s.errors.length, '{count} page failed', '{count} pages failed')}
              </p>
              {s.errors.slice(0, 2).map((e) => (
                <p key={e} title={e} className="m-0 truncate font-mono text-2xs text-muted">{e.replace(/^uri:/, '').replace(/#[\w-]+:/, ':')}</p>
              ))}
            </div>
          )}

          {publishButton(false)}
        </div>
      )}
    </Widget>
  )
}

function working(s: Status): boolean {
  return s.state === 'queued' || s.state === 'running'
}

function summary(s: Status): [Tone, string] {
  if (!s.enabled) return ['success', t('Live')]
  if (s.state === 'running') return ['warning', t('Publishing… {percent}', { percent: `${Math.round(s.progress * 100)}%` })]
  if (s.state === 'queued') return ['warning', t('Unpublished Changes')]
  if (s.errors.length) return ['danger', t('Published with errors')]
  if (!s.finishedAt) return ['neutral', t('Not published yet')]
  return ['success', t('Up to date')]
}

function SiteSkeleton() {
  return (
    <div className="space-y-4">
      <div className="space-y-1.5">
        <Skeleton className="h-3.5 w-28" />
        <Skeleton className="h-3 w-36" />
      </div>
      <div className="grid grid-cols-2 gap-4">
        <Skeleton className="h-9" />
        <Skeleton className="h-9" />
      </div>
    </div>
  )
}
