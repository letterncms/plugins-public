import { locale, t, useTab } from '@letterncms/sdk'
import { Button, Notice, Skeleton, Stat, Tooltip, cn } from '@letterncms/ui'
import { AreaChart } from './Chart'
import { columns, DeviceIcon, Panel } from './Panel'
import { FilterBar, PeriodPicker } from './Toolbar'
import { METRICS, change, format, metricsFor, periodLabels, useReport, useSummary, type Metric } from './data'

const ALL = Object.keys(METRICS) as Metric[]

/**
 * The Overview tab of the Analytics place: the period and filters (both in the address, so a report can be
 * linked), who is on the site now, the headline numbers against the period before, one of them over time
 * with the period before behind it, and where visits come from, go and convert. Pressing any row narrows
 * all of it to that row.
 */
export default function Overview() {
  const report = useReport()
  const [picked, setMetric] = useTab(ALL, 'metric')
  const summary = useSummary(report.input, { compare: true })
  const s = summary.data
  const counting = s?.mode === 'counts'
  const metrics = metricsFor(s, report.filters)
  const metric = metrics.includes(picked) ? picked : metrics[0]
  const before = report.period === 'custom' ? t('Compared with the same number of days before') : t('Compared with the {period} before', { period: periodLabels[report.period]().toLowerCase() })
  const c = columns()

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <FilterBar />
        <div className="ml-auto flex items-center gap-2">
          {s && !!s.current && <Live count={s.current} />}
          <PeriodPicker counting={counting} />
        </div>
      </div>

      {s?.unavailable && <Notice tone="neutral" title={t('Counting page views only: the numbers can be narrowed to pages, not to this.')} />}

      <div>
        <div className={cn('grid grid-cols-2 gap-x-4 gap-y-5', metrics.length > 3 ? 'md:grid-cols-3 xl:grid-cols-6' : 'md:grid-cols-3')}>
          {metrics.map((m) => {
            const meta = METRICS[m]
            if (!s) return <div key={m}><p className="m-0 text-xs text-muted">{meta.label()}</p><Skeleton className="mt-2 h-7 w-20" /></div>
            const delta = report.period === 'realtime' ? null : change(s[m], s.previous[m])
            const stat = <Stat label={meta.label()} value={format(m, s[m])} delta={delta} good={meta.good} />
            // With more than one number, each is the button that puts it in the chart: a line over it says which.
            const cell = metrics.length > 1
              ? <Button variant="ghost" aria-pressed={metric === m} onClick={() => setMetric(m)} className={cn('flex h-auto! w-full justify-start rounded-none! border-t-2 px-0! pb-1 pt-2 text-left font-normal hover:bg-transparent active:bg-transparent', metric === m ? 'border-ink' : 'border-border')}>{stat}</Button>
              : stat
            return delta === null ? <div key={m}>{cell}</div> : <Tooltip key={m} content={before}><div>{cell}</div></Tooltip>
          })}
        </div>

        <div className="mt-6">
          {!s ? (
            <Skeleton className="h-[240px] w-full rounded-md" />
          ) : s.series.length > 1 ? (
            <AreaChart
              key={`${metric}-${s.interval}`}
              points={s.series.map((d) => ({ date: d.date, value: d[metric] ?? 0 }))}
              previous={report.period === 'realtime' ? undefined : s.previousSeries?.map((d) => ({ date: d.date, value: d[metric] ?? 0 }))}
              interval={s.interval}
              format={(v) => format(metric, v)}
              label={METRICS[metric].label()}
              whole={!!METRICS[metric].whole}
              pending={summary.isPlaceholderData}
            />
          ) : null}
        </div>
      </div>

      {s && (counting ? (
        <div className="grid min-w-0 gap-x-8 gap-y-8 md:grid-cols-2">
          <Panel title={t('Pages')} input={report.input} tabs={[{ dimension: 'pages', label: t('Top Pages'), name: t('Page'), filter: 'page', columns: [c.views, c.share] }]} />
          <Panel hideSingle title={t('Languages')} input={report.input} tabs={[{ dimension: 'languages', label: t('Languages'), name: t('Language'), columns: [c.views, c.share] }]} />
          <Panel title={t('Goals')} input={report.input} tabs={[{ dimension: 'goals', label: t('Goals'), name: t('Goal'), columns: [c.events] }]} />
        </div>
      ) : (
        <div className="grid min-w-0 gap-x-8 gap-y-8 md:grid-cols-2">
          <Panel title={t('Sources')} input={report.input} tabs={[
            { dimension: 'channels', label: t('Channels'), name: t('Channel'), filter: 'channel', columns: [c.visitors, c.bounce] },
            { dimension: 'sources', label: t('Sources'), name: t('Source'), filter: 'source', columns: [c.visitors, c.bounce] },
            { dimension: 'campaigns', label: t('Campaigns'), name: t('Campaign'), filter: 'campaign', columns: [c.visitors, c.bounce] },
          ]} />
          <Panel title={t('Pages')} input={report.input} tabs={[
            { dimension: 'pages', label: t('Top Pages'), name: t('Page'), filter: 'page', columns: [c.visitors, c.time, c.scroll] },
            { dimension: 'entries', label: t('Entry Pages'), name: t('Page'), filter: 'entry', columns: [c.visitors, c.bounce] },
            { dimension: 'exits', label: t('Exit Pages'), name: t('Page'), filter: 'exit', columns: [c.visitors] },
          ]} />
          <Panel title={t('Countries')} input={report.input} tabs={[{ dimension: 'countries', label: t('Countries'), name: t('Country'), filter: 'country', columns: [c.visitors, c.share] }]} />
          <Panel title={t('Devices')} input={report.input} tabs={[
            { dimension: 'devices', label: t('Type'), name: t('Device'), filter: 'device', columns: [c.visitors, c.share], icon: (item) => <DeviceIcon item={item} /> },
            { dimension: 'browsers', label: t('Browser'), name: t('Browser'), filter: 'browser', columns: [c.visitors, c.share] },
            { dimension: 'os', label: t('System'), name: t('System'), filter: 'os', columns: [c.visitors, c.share] },
          ]} />
          <Goals key={report.filters.some((f) => f.dimension === 'goal') ? 'props' : 'goals'} input={report.input} />
          <Panel hideSingle title={t('Languages')} input={report.input} tabs={[{ dimension: 'languages', label: t('Languages'), name: t('Language'), columns: [c.views, c.share] }]} />
        </div>
      ))}
    </div>
  )
}

/** Goals, and with one chosen, the properties its events carried: which links, which files, which forms. */
function Goals({ input }: { input: ReturnType<typeof useReport>['input'] }) {
  const report = useReport()
  const goal = report.filters.find((f) => f.dimension === 'goal' && f.op === 'is')
  const c = columns()
  return goal
    ? <Panel title={t('Goals')} input={input} tabs={[{ dimension: 'props', label: t('Details'), name: t('Detail'), columns: [c.visitors, c.events] }]} />
    : <Panel title={t('Goals')} input={input} tabs={[{ dimension: 'goals', label: t('Goals'), name: t('Goal'), filter: 'goal', columns: [c.visitors, c.events, c.conversion] }]} />
}

/** "3 on the site now", with a quiet live dot. */
function Live({ count }: { count: number }) {
  return (
    <Tooltip content={t('Visitors active in the last five minutes')}>
      <span className="mr-1 flex items-center gap-1.5 text-xs text-muted">
        <span className="relative flex size-1.5">
          <span className="absolute inset-0 animate-ping rounded-full bg-success opacity-50 motion-reduce:hidden" />
          <span className="relative size-1.5 rounded-full bg-success" />
        </span>
        {t('{count} on the site now', { count: count.toLocaleString(locale()) })}
      </span>
    </Tooltip>
  )
}
