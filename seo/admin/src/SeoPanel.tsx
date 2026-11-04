import { t, useAbility, useManifest, type Entry, type FieldDef } from '@letterncms/sdk'
import { Button, Dialog, Fields, Icon, QueryError, Spinner, cn, fieldControlId } from '@letterncms/ui'
import { useEffect, useState, type ReactNode } from 'react'

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

/** Entry sidebar: the SEO fields, a search preview, and a score that opens to what to improve. */
export function SeoPanel({ entry, collection, values, title, setValue, editable, errors }: PanelProps) {
  const manifest = useManifest()
  const field = collection.resolvedFields.find((f) => f.handle === 'seo')
  const seo = (values.seo ?? {}) as Record<string, any>
  const [md, setMd] = useState(false)
  const [expanded, setExpanded] = useState(false)
  const analysis = useAbility<Analysis>('seo/analyze', { id: entry?.id ?? '' }, { enabled: !!entry, staleTime: 0 })

  // Checks describe persisted content and refresh after a confirmed save, never on a guessed delay.
  useEffect(() => { if (entry) void analysis.refetch() }, [entry?.version])

  const shownTitle = (seo.title as string) || title || analysis.data?.meta.title || ''
  const description = (seo.description as string) || analysis.data?.meta.description || ''
  const host = manifest.site.url.replace(/^https?:\/\//, '').replace(/\/$/, '')

  const checks = analysis.data?.checks ?? []
  const open = checks.filter((c) => c.status !== 'pass')
  const fields = ((field?.fields as FieldDef[]) ?? [])

  return (
    <div>
      {/* What search engines read comes first: the fields, as rows like every inspector. */}
      {field && (
        <section className="border-b border-border px-4 py-3">
          {fields.map((f) => (
            <Row key={f.handle} field={f} error={errors[`seo.${f.handle}`]}>
              <Fields
                bare
                context={{ path: [field.handle], entry: entry ?? undefined, collection: entry?.collection }}
                disabled={!editable}
                errors={errors}
                // What a field falls back to shows as its placeholder, so it needs no explaining.
                fields={[{ ...f, instructions: undefined, width: 'full', size: 'sm', rows: f.type === 'textarea' ? 3 : f.rows, placeholder: (f.handle === 'title' ? title || analysis.data?.meta.title : f.handle === 'description' ? analysis.data?.meta.description : undefined) || f.placeholder }]}
                values={seo}
                onChange={(h, v) => setValue('seo', { ...seo, [h]: v })}
              />
            </Row>
          ))}
        </section>
      )}

      <section className="border-b border-border px-4 pb-4">
        <h3 className="m-0 flex h-10 items-center text-xs font-semibold text-ink">{t('Search Preview')}</h3>
        <div className="rounded-md bg-bg px-3 py-2.5 shadow-[0_0_0_1px_var(--cms-border)]">
          <div className="truncate text-xs text-muted">{host}{entry?.uri && entry.uri !== '/' ? entry.uri.replace(/\//g, ' › ').replace(/^ › /, ' › ') : ''}</div>
          <div className="mt-0.5 line-clamp-2 text-[15px] leading-5 text-[#1a0dab] dark:text-[#8ab4f8]">{shownTitle}</div>
          <div className="mt-1 line-clamp-2 text-xs leading-[18px] text-muted">{description || <span className="text-faint">{t('No description: the first paragraph is used.')}</span>}</div>
        </div>
      </section>

      <section className="border-b border-border px-4 pb-3 last:border-b-0">
        {/* A score in one row; open it for what to improve. Passing checks need no reading. */}
        <button
          type="button"
          disabled={!analysis.data || open.length === 0}
          onClick={() => setExpanded(!expanded)}
          aria-expanded={open.length > 0 ? expanded : undefined}
          className="group/score -mx-2 flex h-10 w-[calc(100%+16px)] items-center gap-2 rounded-md px-2 text-left enabled:hover:bg-hover"
        >
          <span className="min-w-0 flex-1 truncate text-xs font-semibold text-ink">{t('Score')}</span>
          {analysis.data && (
            <span className="shrink-0 text-xs text-muted">
              {open.length ? t('{count} to improve', { count: open.length }) : t('All checks pass')}
            </span>
          )}
          {analysis.isLoading && <Spinner size={14} />}
          {analysis.data && <Score value={analysis.data.score} />}
          {open.length > 0 && <Icon name="chevron-down" size={14} className={cn('shrink-0 text-faint transition-transform duration-120', !expanded && '-rotate-90')} />}
        </button>
        {!entry && <p className="m-0 text-xs text-muted">{t('Checks are available after the first save.')}</p>}
        {analysis.isError && <QueryError compact message={t('Couldn’t load SEO checks.')} onRetry={() => void analysis.refetch()} />}
        {expanded && open.length > 0 && (
          <ul className="m-0 mt-1 list-none space-y-3 p-0 pb-1">
            {open.map((c) => (
              <li key={c.id} className="flex gap-2.5">
                <span className={cn('mt-[7px] size-1.5 shrink-0 rounded-full', c.status === 'warn' ? 'bg-warning' : 'bg-danger')} role="img" aria-label={c.status === 'warn' ? t('Could Be Better') : t('Needs Fixing')} />
                <span className="min-w-0">
                  <span className="block text-sm text-text">{c.label}</span>
                  <span className="block text-xs text-muted">{c.message}</span>
                </span>
              </li>
            ))}
            <li className="pl-4 text-xs text-faint">{t('Based on the last save.')}</li>
          </ul>
        )}
        {entry && (
          <Button size="sm" variant="ghost" icon="markdown" className="-ml-2 mt-1" onClick={() => setMd(true)}>{t('What AI Crawlers Read')}</Button>
        )}
      </section>
      {md && entry && <MarkdownDialog id={entry.id} onClose={() => setMd(false)} />}
    </div>
  )
}

/** One field: its label above it, the control the panel's width; a switch is its own row, as in every inspector. */
function Row({ field, error, children }: { field: FieldDef; error?: string; children: ReactNode }) {
  if (field.type === 'toggle') return <div className="flex min-h-7 items-center py-1">{children}</div>
  return (
    <div className="pb-2 last:pb-0" data-error={error ? '' : undefined}>
      <label htmlFor={fieldControlId(field, ['seo'])} className="flex h-7 min-w-0 items-center text-xs text-muted"><span className="truncate">{field.label}</span></label>
      {children}
    </div>
  )
}

function Score({ value }: { value: number }) {
  const tone = value >= 85 ? 'text-success' : value >= 60 ? 'text-warning' : 'text-danger'
  const r = 6
  const c = 2 * Math.PI * r
  return (
    <span className="flex shrink-0 items-center gap-1.5 text-sm font-medium tabular-nums text-ink">
      <svg width="16" height="16" viewBox="0 0 16 16" className={cn('-rotate-90', tone)} aria-hidden>
        <circle cx="8" cy="8" r={r} fill="none" stroke="currentColor" strokeOpacity="0.2" strokeWidth="2" />
        <circle cx="8" cy="8" r={r} fill="none" stroke="currentColor" strokeWidth="2" strokeDasharray={c} strokeDashoffset={c * (1 - value / 100)} strokeLinecap="round" />
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
