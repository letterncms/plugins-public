import { can, locale, navigate, t, useAbility } from '@letterncms/sdk'
import { Badge, BarRow, Button, EmptyState, Notice, QueryError, Section, Segmented, Skeleton, Stat, Tooltip, cn } from '@letterncms/ui'
import { keepPreviousData } from '@tanstack/react-query'
import { useState } from 'react'
import { AreaChart } from './Chart'
import { columns, Panel } from './Panel'
import { FilterBar, PeriodPicker } from './Toolbar'
import { change, compact, format, number, percent, useReport, useSummary, type Filter, type Interval } from './data'

interface Status { connected: boolean; property: string | null; problem: 'key' | 'access' | 'unreachable' | null }
interface SearchMetrics { clicks: number; impressions: number; ctr: number; position: number | null }
interface SearchSummary extends SearchMetrics { interval: Interval; property: string; previous: SearchMetrics; series: (SearchMetrics & { date: string })[] }
interface SearchItem extends SearchMetrics { key: string; label: string; path?: string; url?: string; page?: string; kind?: 'top-ten' | 'page-two' }

type SearchMetric = keyof SearchMetrics

const SEARCH_METRICS: { key: SearchMetric; label: () => string; good: 'up' | 'down'; value: (v: number | null) => string }[] = [
  { key: 'clicks', label: () => t('Clicks'), good: 'up', value: (v) => number(v) },
  { key: 'impressions', label: () => t('Impressions'), good: 'up', value: (v) => number(v) },
  { key: 'ctr', label: () => t('Click-Through Rate'), good: 'up', value: (v) => percent(v, 1) },
  { key: 'position', label: () => t('Average Position'), good: 'down', value: (v) => (v === null ? '–' : new Intl.NumberFormat(locale(), { maximumFractionDigits: 1 }).format(v)) },
]

/**
 * The Search tab: how people find the site. Google Search through Search Console (clicks, the searches,
 * the pages, and the searches a page is close to the top for), the visitors AI assistants send, and how
 * fast the pages are for visitors, which Google ranks them by.
 */
export default function Search() {
  return (
    <div className="space-y-10">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <FilterBar />
        <div className="ml-auto"><PeriodPicker /></div>
      </div>
      <GoogleSearch />
      <Assistants />
      <Speed />
    </div>
  )
}

/** The report's page filter, which Search Console can narrow to as well. */
function pageOf(filters: Filter[]): string | undefined {
  return filters.find((f) => f.dimension === 'page' && f.op === 'is' && f.value && !f.value.includes('*'))?.value ?? undefined
}

function GoogleSearch() {
  const report = useReport()
  const status = useAbility<Status>('analytics/search', { report: 'status' }, { staleTime: 60_000 })
  const page = pageOf(report.filters)
  const input = { ...report.range, ...(page ? { page } : {}) }
  const connected = status.data?.connected === true
  const summary = useAbility<SearchSummary>('analytics/search', { ...input, report: 'summary' }, { enabled: connected, placeholderData: keepPreviousData })
  const [metric, setMetric] = useState<SearchMetric>('clicks')
  const [list, setList] = useState<'queries' | 'pages' | 'opportunities'>('queries')
  const settings = can('settings.manage') && <Button size="sm" onClick={() => navigate('/analytics?tab=settings')}>{t('Open Settings')}</Button>

  if (status.isError) return <Section title={t('Google Search')}><QueryError compact title={t('Couldn’t reach Search Console')} onRetry={() => status.refetch()} retrying={status.isFetching} /></Section>
  if (!status.data) return <Section title={t('Google Search')}><Skeleton className="h-48" /></Section>
  if (!connected) {
    const problem = status.data.problem
    return (
      <Section title={t('Google Search')}>
        {problem === null ? (
          <EmptyState compact icon="search" title={t('Search Console isn’t connected')} action={can('settings.manage') && <Button size="sm" onClick={() => navigate('/analytics?tab=settings')}>{t('Connect Search Console')}</Button>} />
        ) : (
          <Notice tone={problem === 'unreachable' ? 'warning' : 'danger'} title={{
            key: t('Google doesn’t accept the Search Console key.'),
            access: t('The service account can’t read this site’s Search Console property.'),
            unreachable: t('Search Console can’t be reached right now.'),
          }[problem]} action={settings} />
        )}
      </Section>
    )
  }

  const s = summary.data
  const chosen = SEARCH_METRICS.find((m) => m.key === metric)!
  return (
    <Section title={t('Google Search')} actions={s && <span className="truncate text-xs text-faint">{s.property}</span>}>
      {summary.isError ? (
        <QueryError compact title={t('Couldn’t load Google Search')} onRetry={() => summary.refetch()} retrying={summary.isFetching} />
      ) : (
        <>
          <div className="grid grid-cols-2 gap-x-4 gap-y-5 md:grid-cols-4">
            {SEARCH_METRICS.map((m) => !s ? (
              <div key={m.key}><p className="m-0 text-xs text-muted">{m.label()}</p><Skeleton className="mt-2 h-7 w-20" /></div>
            ) : (
              <Button key={m.key} variant="ghost" aria-pressed={metric === m.key} onClick={() => setMetric(m.key)} className={cn('flex h-auto! w-full justify-start rounded-none! border-t-2 px-0! pb-1 pt-2 text-left font-normal hover:bg-transparent active:bg-transparent', metric === m.key ? 'border-ink' : 'border-border')}>
                <Stat label={m.label()} value={m.value(s[m.key])} delta={change(s[m.key], s.previous[m.key])} good={m.good} />
              </Button>
            ))}
          </div>
          <div className="mt-6">
            {!s ? <Skeleton className="h-[200px] w-full rounded-md" /> : s.series.length > 1 && (
              <AreaChart key={metric} height={200} interval={s.interval} points={s.series.map((d) => ({ date: d.date, value: d[metric] ?? 0 }))} format={(v) => chosen.value(v)} label={chosen.label()} whole={metric === 'clicks' || metric === 'impressions'} pending={summary.isPlaceholderData} />
            )}
          </div>
          <div className="mt-8">
            <div className="mb-3"><Segmented size="xs" aria-label={t('Google Search')} value={list} onChange={setList} options={[{ value: 'queries', label: t('Searches') }, ...(page ? [] : [{ value: 'pages' as const, label: t('Pages') }, { value: 'opportunities' as const, label: t('Close to the Top') }])]} /></div>
            <SearchList report={page && list !== 'queries' ? 'queries' : list} input={input} />
          </div>
        </>
      )}
    </Section>
  )
}

function SearchList({ report: kind, input }: { report: 'queries' | 'pages' | 'opportunities'; input: Record<string, unknown> }) {
  const report = useReport()
  const query = useAbility<{ items: SearchItem[] }>('analytics/search', { ...input, report: kind, limit: 10 }, { placeholderData: keepPreviousData })
  const items = query.data?.items ?? []
  const top = Math.max(1, ...items.map((i) => (kind === 'opportunities' ? i.impressions : i.clicks)))

  if (query.isError) return <QueryError compact title={t('Couldn’t load Google Search')} onRetry={() => query.refetch()} retrying={query.isFetching} />
  if (!query.data) return <div className="space-y-1.5">{Array.from({ length: 5 }, (_, i) => <Skeleton key={i} className="h-8" />)}</div>
  if (!items.length) return <EmptyState compact icon="search" title={kind === 'opportunities' ? t('No searches close to the top') : t('No searches in this period')} />

  return (
    <div className={cn('transition-opacity duration-move', query.isPlaceholderData && 'opacity-60')}>
      <div className="mb-1 flex items-center gap-3 text-2xs font-medium text-faint">
        <span className="flex-1 pl-2">{kind === 'pages' ? t('Page') : t('Search Term')}</span>
        <span className="w-16 text-right">{kind === 'opportunities' ? t('Impressions') : t('Clicks')}</span>
        <span className="hidden w-16 text-right @md:inline-block md:inline-block">{t('Position')}</span>
      </div>
      <ul className="m-0 list-none space-y-0.5 p-0">
        {items.map((item) => (
          <li key={item.key} className="min-w-0">
            <BarRow
              share={(kind === 'opportunities' ? item.impressions : item.clicks) / top}
              label={
                <span className="flex min-w-0 items-baseline gap-2">
                  <span className="truncate">{item.label}</span>
                  {kind === 'opportunities' && <span className="hidden truncate text-2xs text-faint md:inline">{item.page}</span>}
                  {kind === 'opportunities' && <Badge tone={item.kind === 'top-ten' ? 'success' : 'neutral'}>{item.kind === 'top-ten' ? t('Page One') : t('Page Two')}</Badge>}
                </span>
              }
              value={
                <span className="flex items-center gap-3">
                  <Tooltip content={t('{clicks} clicks of {impressions} impressions ({ctr})', { clicks: number(item.clicks), impressions: number(item.impressions), ctr: percent(item.ctr, 1) })}>
                    <span className="inline-block w-16 text-right">{compact(kind === 'opportunities' ? item.impressions : item.clicks)}</span>
                  </Tooltip>
                  <span className="hidden w-16 text-right md:inline-block">{item.position === null ? '–' : new Intl.NumberFormat(locale(), { maximumFractionDigits: 1 }).format(item.position)}</span>
                </span>
              }
              to={kind === 'pages' && item.path ? report.linkWith({ dimension: 'page', op: 'is', value: item.path }) : undefined}
              href={kind !== 'pages' && item.url ? item.url : undefined}
            />
          </li>
        ))}
      </ul>
    </div>
  )
}

/** Visitors AI assistants sent: ChatGPT, Perplexity, Claude, Gemini, Copilot… and the pages they sent them to. */
function Assistants() {
  const report = useReport()
  const ai = { ...report.input, filters: [...report.filters.filter((f) => f.dimension !== 'channel'), { dimension: 'channel', op: 'is', value: 'ai' }] }
  const summary = useSummary(ai, { refetch: 120_000 })
  const s = summary.data
  const c = columns()

  if (s?.mode === 'counts') return null

  return (
    <Section
      title={t('AI Assistants')}
      actions={s && <Stat size="sm" label={t('Visitors')} value={format('visitors', s.visitors)} delta={change(s.visitors, s.previous.visitors)} className="text-right" />}
    >
      {summary.isError ? (
        <QueryError compact title={t('Couldn’t load the AI assistants')} onRetry={() => summary.refetch()} retrying={summary.isFetching} />
      ) : (
        <div className="grid min-w-0 gap-8 md:grid-cols-2">
          <Panel title={t('Assistants')} input={ai} tabs={[{ dimension: 'sources', label: t('Assistants'), name: t('Assistant'), filter: 'source', columns: [c.visitors, c.share] }]} />
          <Panel title={t('Landing Pages')} input={ai} tabs={[{ dimension: 'entries', label: t('Landing Pages'), name: t('Page'), filter: 'entry', columns: [c.visitors, c.bounce] }]} />
        </div>
      )}
    </Section>
  )
}

interface Vital { lcp: number | null; lcpRating: Rating; inp: number | null; inpRating: Rating; cls: number | null; clsRating: Rating; rating: Rating; samples: number }
type Rating = 'good' | 'needs-improvement' | 'poor' | null

const ratingTone = { good: 'success', 'needs-improvement': 'warning', poor: 'danger' } as const
const ratingLabel = () => ({ good: t('Good'), 'needs-improvement': t('Needs Improvement'), poor: t('Poor') })

/** Core Web Vitals as visitors met them: Google's thresholds, the 75th percentile. */
function Speed() {
  const report = useReport()
  const vitals = useAbility<{ site: Vital; pages: (Vital & { key: string; label: string; path: string })[] }>('analytics/vitals', { ...report.input, limit: 8 }, { placeholderData: keepPreviousData })
  const summary = useSummary(report.input)
  const v = vitals.data
  const seconds = (ms: number | null) => (ms === null ? '–' : ms >= 1000 ? t('{count} s', { count: new Intl.NumberFormat(locale(), { maximumFractionDigits: 1 }).format(ms / 1000) }) : t('{count} ms', { count: number(ms) }))
  const shift = (value: number | null) => (value === null ? '–' : new Intl.NumberFormat(locale(), { maximumFractionDigits: 2 }).format(value))

  if (summary.data?.mode === 'counts') return null

  const slowest = Math.max(0, ...(v?.pages ?? []).map((p) => p.lcp ?? 0))
  const metrics = v && [
    { label: t('Largest Contentful Paint'), short: t('Loading'), value: seconds(v.site.lcp), rating: v.site.lcpRating },
    { label: t('Interaction to Next Paint'), short: t('Responding'), value: seconds(v.site.inp), rating: v.site.inpRating },
    { label: t('Cumulative Layout Shift'), short: t('Steadiness'), value: shift(v.site.cls), rating: v.site.clsRating },
  ]

  return (
    <Section title={t('Page Speed')}>
      {vitals.isError ? (
        <QueryError compact title={t('Couldn’t load the page speed')} onRetry={() => vitals.refetch()} retrying={vitals.isFetching} />
      ) : !v ? (
        <Skeleton className="h-40" />
      ) : v.site.samples === 0 ? (
        <EmptyState compact icon="gauge" title={t('No speed measured in this period')} />
      ) : (
        <div className={cn('transition-opacity duration-move', vitals.isPlaceholderData && 'opacity-60')}>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            {metrics!.map((m) => (
              <Tooltip key={m.label} content={m.label}>
                <div className="min-w-0">
                  <Stat label={m.short} value={m.value} />
                  {m.rating && <Badge tone={ratingTone[m.rating]} className="mt-2">{ratingLabel()[m.rating]}</Badge>}
                </div>
              </Tooltip>
            ))}
          </div>
          <div className="mb-1 mt-8 flex items-center gap-3 text-2xs font-medium text-faint">
            <span className="flex-1 pl-2">{t('Page')}</span>
            <span className="w-16 text-right">{t('Loading')}</span>
            <span className="hidden w-20 text-right md:inline-block">{t('Responding')}</span>
            <span className="hidden w-20 text-right md:inline-block">{t('Steadiness')}</span>
            <span className="w-36 pr-1 text-right">{t('Rating')}</span>
          </div>
          <ul className="m-0 list-none space-y-0.5 p-0">
            {v.pages.map((p) => (
              <li key={p.key} className="min-w-0">
                <BarRow
                  // The bar is how long the page takes to show, against the slowest.
                  share={slowest > 0 ? (p.lcp ?? 0) / slowest : 0}
                  label={<span className="truncate">{p.label}</span>}
                  to={report.linkWith({ dimension: 'page', op: 'is', value: p.path })}
                  value={
                    <span className="flex items-center gap-3">
                      <span className="w-16 text-right">{seconds(p.lcp)}</span>
                      <span className="hidden w-20 text-right md:inline-block">{seconds(p.inp)}</span>
                      <span className="hidden w-20 text-right md:inline-block">{shift(p.cls)}</span>
                      <span className="flex w-36 justify-end">{p.rating ? <Badge tone={ratingTone[p.rating]}>{ratingLabel()[p.rating]}</Badge> : <Tooltip content={t('Fewer than 10 visits measured')}><span>{t('{count} measured', { count: p.samples })}</span></Tooltip>}</span>
                    </span>
                  }
                />
              </li>
            ))}
          </ul>
        </div>
      )}
    </Section>
  )
}
