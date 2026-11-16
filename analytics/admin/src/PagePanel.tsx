import { can, navigate, t, useAbility, type EntryPanelProps } from '@letterncms/sdk'
import { BarRow, Button, EmptyState, QueryError, Skeleton, Sparkline, Stat } from '@letterncms/ui'
import { keepPreviousData } from '@tanstack/react-query'
import { compact, format, number, percent, useBreakdown, useSummary, valueLabel, change } from './data'

interface SearchSummary { clicks: number; impressions: number; ctr: number; position: number | null }

/**
 * The Analytics tab of the page editor's side panel: how the page did in the last 30 days. Its visitors
 * and views, how long people read it and how far, where they came from, and what Google says about it.
 */
export function PagePanel({ entry }: Pick<EntryPanelProps, 'entry'>) {
  const uri = entry?.uri ?? null
  const live = entry && entry.status === 'published' && uri
  const filters = uri ? [{ dimension: 'page', op: 'is', value: uri }] : []
  const input = { period: '30d', filters }
  const summary = useSummary(input, { refetch: 300_000 })
  const s = summary.data
  const counting = s?.mode === 'counts'
  const sources = useBreakdown(input, 'sources', 4, !!live && s?.mode === 'visitors')
  const status = useAbility<{ connected: boolean }>('analytics/search', { report: 'status' }, { enabled: !!live && can('analytics.view'), staleTime: 300_000 })
  const search = useAbility<SearchSummary>('analytics/search', { period: '30d', report: 'summary', page: uri }, { enabled: !!live && status.data?.connected === true, placeholderData: keepPreviousData })
  const link = uri ? `/analytics?f=${encodeURIComponent(`page:is:${uri}`)}` : '/analytics'

  if (!live) return <EmptyState compact icon="chart" title={t('Counted once it is published')} />
  if (summary.isError) return <QueryError compact title={t('Couldn’t load the numbers')} onRetry={() => summary.refetch()} retrying={summary.isFetching} />
  if (!s) return <div className="space-y-3 p-1"><Skeleton className="h-16" /><Skeleton className="h-12" /></div>

  const stats = counting ? (['views'] as const) : (['visitors', 'views', 'timeOnPage', 'scrollDepth'] as const)

  return (
    <div className="space-y-6">
      <div>
        <p className="m-0 mb-3 text-xs text-muted">{t('Last 30 days')}</p>
        <div className="grid grid-cols-2 gap-x-4 gap-y-4">
          {stats.map((m) => (
            <Stat key={m} size="sm" label={({ visitors: t('Visitors'), views: t('Page Views'), timeOnPage: t('Time on Page'), scrollDepth: t('Scroll Depth') })[m]} value={format(m, s[m])} delta={change(s[m], s.previous[m])} />
          ))}
        </div>
        <Sparkline values={s.series.map((d) => (counting ? d.views : d.visitors) ?? 0)} label={counting ? t('Page Views per Day') : t('Visitors per Day')} className="mt-4 h-10 w-full" />
      </div>

      {!counting && sources.data && sources.data.items.length > 0 && (
        <div>
          <p className="m-0 mb-2 text-xs font-medium text-muted">{t('Sources')}</p>
          <ul className="m-0 list-none space-y-0.5 p-0">
            {sources.data.items.map((item) => (
              <li key={item.key ?? ''}><BarRow label={valueLabel('sources', item)} share={item.share} value={compact(item.visitors)} /></li>
            ))}
          </ul>
        </div>
      )}

      {search.data && (
        <div>
          <p className="m-0 mb-2 text-xs font-medium text-muted">{t('Google Search')}</p>
          <div className="grid grid-cols-2 gap-x-4 gap-y-3">
            <Stat size="sm" label={t('Clicks')} value={number(search.data.clicks)} />
            <Stat size="sm" label={t('Average Position')} value={search.data.position === null ? '–' : search.data.position.toFixed(1)} />
          </div>
          <p className="m-0 mt-2 text-xs text-muted">{t('{impressions} impressions, {ctr} clicked', { impressions: compact(search.data.impressions), ctr: percent(search.data.ctr, 1) })}</p>
        </div>
      )}

      <Button variant="secondary" size="sm" className="w-full" onClick={() => navigate(link)}>{t('Open in Analytics')}</Button>
    </div>
  )
}
