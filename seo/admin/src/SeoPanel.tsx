import { t, useAbility, useManifest, type Entry, type FieldDef } from '@letterncms/sdk'
import { Button, Dialog, Fields, Icon, QueryError, Spinner, cn } from '@letterncms/ui'
import { useEffect, useState } from 'react'

interface Analysis {
  score: number
  checks: { id: string; status: 'pass' | 'warn' | 'fail'; label: string; message: string }[]
  meta: { title: string; description: string; canonical: string; image: string | null }
}

interface PanelProps {
  entry: Entry | null
  collection: { resolvedFields: FieldDef[]; route?: string }
  values: Record<string, any>
  title: string
  setValue: (handle: string, value: any) => void
  editable: boolean
  errors: Record<string, string>
}

/** Entry sidebar: search preview, SEO fields and SEO/GEO checks. */
export function SeoPanel({ entry, collection, values, title, setValue, editable, errors }: PanelProps) {
  const manifest = useManifest()
  const field = collection.resolvedFields.find((f) => f.handle === 'seo')
  const seo = (values.seo ?? {}) as Record<string, any>
  const [md, setMd] = useState(false)
  const analysis = useAbility<Analysis>('seo/analyze', { id: entry?.id ?? '' }, { enabled: !!entry, staleTime: 0 })

  // Checks describe persisted content and refresh after a confirmed save, never on a guessed delay.
  useEffect(() => { if (entry) void analysis.refetch() }, [entry?.version])

  const shownTitle = (seo.title as string) || title || analysis.data?.meta.title || ''
  const description = (seo.description as string) || analysis.data?.meta.description || ''
  const host = manifest.site.url.replace(/^https?:\/\//, '').replace(/\/$/, '')

  return (
    <div className="space-y-6 p-4">
      <section>
        <div className="cms-caps mb-2">{t('Search Preview')}</div>
        <div className="rounded-md border border-border p-3">
          <div className="truncate text-xs text-muted">{host}{entry?.uri && entry.uri !== '/' ? entry.uri.replace(/\//g, ' › ').replace(/^ › /, ' › ') : ''}</div>
          <div className="mt-0.5 line-clamp-2 text-[15px] leading-5 text-[#1a0dab] dark:text-[#8ab4f8]">{shownTitle}</div>
          <div className="mt-1 line-clamp-2 text-xs leading-[18px] text-muted">{description || <span className="text-faint">{t('No description: the first paragraph is used.')}</span>}</div>
        </div>
      </section>

      {!entry && <p className="m-0 text-xs text-muted">{t('Checks are available after the first save.')}</p>}
      {analysis.isError && <QueryError compact message={t('Couldn’t load SEO checks.')} onRetry={() => void analysis.refetch()} />}
      {analysis.data && (
        <section>
          <div className="mb-2 flex items-center justify-between">
            <span className="cms-caps">{t('Checks · saved content')}</span>
            <Score value={analysis.data.score} />
          </div>
          <ul className="m-0 list-none space-y-2 p-0">
            {analysis.data.checks.map((c) => (
              <li key={c.id} className="flex gap-2 text-xs">
                <span className={cn('mt-0.5 grid size-4 shrink-0 place-items-center rounded-full', c.status === 'pass' ? 'bg-success/15 text-success' : c.status === 'warn' ? 'bg-warning/15 text-warning' : 'bg-danger/15 text-danger')}>
                  <Icon name={c.status === 'pass' ? 'check' : c.status === 'warn' ? 'minus' : 'close'} size={10} />
                </span>
                <span>
                  <span className="font-medium text-text">{c.label}</span>
                  <span className="block text-faint">{c.message}</span>
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}
      {analysis.isLoading && <div className="flex justify-center text-faint"><Spinner /></div>}

      {field && (
        <section>
          <div className="cms-caps mb-3">{t('Search & Social')}</div>
          <Fields context={{ path: [field.handle], entry: entry ?? undefined, collection: entry?.collection }} disabled={!editable} errors={errors} fields={((field.fields as FieldDef[]) ?? []).map((f) => ({ ...f, width: 'full' }))} values={seo} onChange={(h, v) => setValue('seo', { ...seo, [h]: v })} />
        </section>
      )}

      {entry && <Button size="sm" icon="markdown" className="w-full" onClick={() => setMd(true)}>{t('What AI Crawlers Read')}</Button>}
      {md && entry && <MarkdownDialog id={entry.id} onClose={() => setMd(false)} />}
    </div>
  )
}

function Score({ value }: { value: number }) {
  const tone = value >= 85 ? 'text-success' : value >= 60 ? 'text-warning' : 'text-danger'
  const r = 9
  const c = 2 * Math.PI * r
  return (
    <span className={cn('flex items-center gap-1.5 text-xs font-semibold', tone)}>
      <svg width="22" height="22" viewBox="0 0 22 22" className="-rotate-90">
        <circle cx="11" cy="11" r={r} fill="none" stroke="currentColor" strokeOpacity="0.18" strokeWidth="2.5" />
        <circle cx="11" cy="11" r={r} fill="none" stroke="currentColor" strokeWidth="2.5" strokeDasharray={c} strokeDashoffset={c * (1 - value / 100)} strokeLinecap="round" />
      </svg>
      {value}
    </span>
  )
}

function MarkdownDialog({ id, onClose }: { id: string; onClose: () => void }) {
  const md = useAbility<{ markdown: string }>('seo/markdown', { id })
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} size="lg" title={t('Markdown Version')} description={t('Published at /page.md and listed in /llms.txt for AI crawlers and agents.')}>
      {md.isError ? <QueryError message={t('Couldn’t load the Markdown version.')} onRetry={() => void md.refetch()} /> : md.data ? <pre className="m-0 max-h-[60vh] overflow-auto whitespace-pre-wrap rounded-md bg-sunken p-4 font-mono text-xs leading-5">{md.data.markdown}</pre> : <Spinner />}
    </Dialog>
  )
}
