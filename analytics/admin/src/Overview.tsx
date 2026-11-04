import { locale, setQuery, t, useLocation, useTab } from '@letterncms/sdk'
import { useEffect } from 'react'
import { Delta, Segmented, Skeleton, Tooltip, cn } from '@letterncms/ui'
import { AreaChart } from './Chart'
import { METRICS, PERIODS, change, format, useSummary, type Period } from './data'

const periodLabels: Record<Period, () => string> = { '7d': () => t('7 days'), '30d': () => t('30 days'), '90d': () => t('90 days') }

/**
 * The headline of the Analytics place (AnalyticsViews): the period (kept in the address as
 * ?period=, which the lists below read too), who is on the site now, the four numbers
 * against the period before, each switching the chart of that number per day.
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

  return (
    <div className="mb-10">
      <div className="mb-2 flex flex-wrap items-center justify-end gap-2">
        {s && !!s.current && <Live count={s.current} />}
        <Segmented value={period} onChange={setPeriod} options={(['7d', '30d', '90d'] as Period[]).map((p) => ({ value: p, label: periodLabels[p]() }))} />
      </div>

      <div role="tablist" aria-label={t('Metric')} className="grid grid-cols-2 border-b border-border md:grid-cols-4">
        {metrics.map((m) => (
          <button
            key={m.key}
            type="button"
            role="tab"
            aria-selected={metric === m.key}
            onClick={() => setMetric(m.key)}
            className={cn('group relative -mb-px border-b-2 py-3 pr-4 text-left outline-none transition-colors duration-120 focus-visible:bg-hover', metric === m.key ? 'border-ink' : 'border-transparent hover:border-border-strong')}
          >
            <span className={cn('block text-xs transition-colors', metric === m.key ? 'text-text' : 'text-muted group-hover:text-text')}>{m.label()}</span>
            {s ? (
              <span className="mt-1 flex items-baseline gap-2">
                <span className="text-2xl font-semibold tracking-[-0.02em] text-ink">{format(m.key, s[m.key])}</span>
                {change(s[m.key], s.previous[m.key]) !== null && <Delta value={change(s[m.key], s.previous[m.key])!} good={m.good} />}
              </span>
            ) : (
              <Skeleton className="mt-2 h-6 w-20" />
            )}
          </button>
        ))}
      </div>
      <p className="m-0 mt-2 text-2xs text-faint">{t('Compared with the {period} before', { period: periodLabels[period]().toLowerCase() })}</p>

      <div className="mt-6">
        {s ? (
          <AreaChart key={metric} points={s.series.map((d) => ({ date: d.date, value: d[metric] ?? 0 }))} format={(v) => format(metric, v)} label={chosen.label()} pending={summary.isPlaceholderData} />
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
