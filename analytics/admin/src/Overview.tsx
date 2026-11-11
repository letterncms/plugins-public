import { locale, setQuery, t, useLocation, useTab } from '@letterncms/sdk'
import { useEffect } from 'react'
import { Button, Segmented, Skeleton, Stat, Tooltip, cn } from '@letterncms/ui'
import { AreaChart } from './Chart'
import { METRICS, PERIODS, change, format, useSummary, type Period } from './data'

const periodLabels: Record<Period, () => string> = { '7d': () => t('7 Days'), '30d': () => t('30 Days'), '90d': () => t('90 Days') }

/**
 * The headline of the Analytics place (AnalyticsViews): the period (kept in the address as
 * ?period=, which the lists below read too), who is on the site now, the numbers against the
 * period before, and one of them per day. With page views alone there is one number and its
 * chart; with visitors there are four, and the numbers themselves choose which one the chart draws.
 */
export default function Overview() {
  const [period, setPeriod] = useTab(PERIODS, 'period')
  // A period it doesn't know (a link typed by hand) leaves the address, so the lists below read the same one.
  const asked = useLocation().query.period
  useEffect(() => { if (asked !== undefined && !(PERIODS as readonly string[]).includes(asked)) setQuery({ period: null }) }, [asked])
  const [picked, setMetric] = useTab(METRICS.map((m) => m.key), 'metric')
  const summary = useSummary(period)
  const s = summary.data
  const counting = s?.mode === 'counts'
  const metrics = counting ? METRICS.filter((m) => m.counts) : METRICS
  const metric = counting ? 'views' : picked
  const chosen = METRICS.find((m) => m.key === metric)!
  const before = t('Compared with the {period} before', { period: periodLabels[period]().toLowerCase() })

  return (
    <div className="mb-10">
      <div className="mb-4 flex flex-wrap items-center justify-end gap-2">
        {s && !!s.current && <Live count={s.current} />}
        <Segmented value={period} onChange={setPeriod} options={(['7d', '30d', '90d'] as Period[]).map((p) => ({ value: p, label: periodLabels[p]() }))} />
      </div>

      <div className="grid grid-cols-2 gap-x-4 gap-y-5 md:grid-cols-4">
        {metrics.map((m) => {
          if (!s) return <div key={m.key}><p className="m-0 text-xs text-muted">{m.label()}</p><Skeleton className="mt-2 h-6 w-20" /></div>
          const delta = change(s[m.key], s.previous[m.key])
          const stat = <Stat label={m.label()} value={format(m.key, s[m.key])} delta={delta} good={m.good} />
          // With more than one number, each is the button that puts it in the chart: no second row repeating their names.
          // Flush with the page: the one in the chart carries a line over it, like a tab, and nothing reaches past the margin.
          const cell = metrics.length > 1
            ? <Button variant="ghost" aria-current={metric === m.key ? 'true' : undefined} onClick={() => setMetric(m.key)} className={cn('flex h-auto! w-full justify-start rounded-none! border-t-2 px-0! pb-1 pt-2 text-left font-normal hover:bg-transparent active:bg-transparent', metric === m.key ? 'border-ink' : 'border-border')}>{stat}</Button>
            : stat
          // What the change is measured against is said where the change is, when there is one.
          return delta === null ? <div key={m.key}>{cell}</div> : <Tooltip key={m.key} content={before}><div>{cell}</div></Tooltip>
        })}
      </div>

      <div className="mt-6">
        {s ? (
          <AreaChart key={metric} points={s.series.map((d) => ({ date: d.date, value: d[metric] ?? 0 }))} format={(v) => format(metric, v)} label={chosen.label()} whole={metric === 'visitors' || metric === 'views'} pending={summary.isPlaceholderData} />
        ) : (
          <Skeleton className="h-[240px] w-full rounded-md" />
        )}
      </div>
    </div>
  )
}

/** "3 on the site now", with a quiet live dot. */
function Live({ count }: { count: number }) {
  return (
    <Tooltip content={t('Visitors active in the last five minutes')}>
      <span className="mr-2 flex items-center gap-1.5 text-xs text-muted">
        <span className="relative flex size-1.5">
          <span className="absolute inset-0 animate-ping rounded-full bg-success opacity-50 motion-reduce:hidden" />
          <span className="relative size-1.5 rounded-full bg-success" />
        </span>
        {t('{count} on the site now', { count: count.toLocaleString(locale()) })}
      </span>
    </Tooltip>
  )
}
