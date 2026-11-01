import { can, locale, navigate, t, useManifest, useTab } from '@letterncms/sdk'
import { BarRow, Button, Delta, EmptyState, IconButton, Icon, Page, Segmented, Skeleton, Tooltip, Widget, cn, compactNumber } from '@letterncms/ui'
import type { ReactNode } from 'react'
import { AreaChart } from './Chart'
import { METRICS, PERIODS, change, format, useBreakdown, useSummary, type Breakdown, type BreakdownItem, type Period } from './data'

const periodLabels: Record<Period, () => string> = { '7d': () => t('7 days'), '30d': () => t('30 days'), '90d': () => t('90 days') }

/**
 * Analytics: the headline numbers for a period (each one switches the chart), visitors
 * or views per day, and where visits go, come from and what they browse with.
 */
export default function AnalyticsScreen() {
  const [period, setPeriod] = useTab(PERIODS, 'period')
  const [picked, setMetric] = useTab(METRICS.map((m) => m.key), 'metric')
  const summary = useSummary(period)
  const s = summary.data
  const counting = s?.mode === 'counts'
  const metrics = counting ? METRICS.filter((m) => m.counts) : METRICS
  const metric = counting ? 'views' : picked

  const actions = (
    <>
      {s && !!s.current && <Live count={s.current} />}
      <Segmented value={period} onChange={setPeriod} options={(['7d', '30d', '90d'] as Period[]).map((p) => ({ value: p, label: periodLabels[p]() }))} />
      {can('settings.manage') && <IconButton icon="settings" label={t('Analytics Settings')} onClick={() => navigate('/settings/analytics')} />}
    </>
  )

  if (s && !s.tracked) {
    return (
      <Page crumbs={[{ label: t('Analytics'), icon: 'chart' }]} actions={can('settings.manage') && <IconButton icon="settings" label={t('Analytics Settings')} onClick={() => navigate('/settings/analytics')} />} width="default">
        {s.enabled ? (
          <EmptyState icon="chart" title={t('No visits yet')} description={t('Visits appear here as people open your published site.')} />
        ) : (
          <EmptyState icon="chart" title={t('Analytics is off')} description={t('Turn it on to see who visits your site.')} action={can('settings.manage') && <Button variant="primary" onClick={() => navigate('/settings/analytics')}>{t('Open Settings')}</Button>} />
        )}
      </Page>
    )
  }

  const chosen = METRICS.find((m) => m.key === metric)!

  return (
    <Page crumbs={[{ label: t('Analytics'), icon: 'chart' }]} actions={actions} width="default">
      {s && !s.enabled && (
        <div className="mb-6 flex items-center gap-2 rounded-md border border-border bg-surface px-3 py-2 text-sm text-muted">
          <Icon name="pause" size={14} className="text-faint" />
          <span className="flex-1">{t('Counting visits is paused.')}</span>
          {can('settings.manage') && <Button size="xs" variant="ghost" onClick={() => navigate('/settings/analytics')}>{t('Settings')}</Button>}
        </div>
      )}

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

      <div className="mt-12 grid grid-cols-1 gap-x-10 gap-y-10 lg:grid-cols-2">
        <Pages period={period} dimension="pages" counting={counting} />
        {counting ? <Languages period={period} /> : <Sources period={period} />}
        {counting || <Pages period={period} dimension="entries" />}
        {counting || <Devices period={period} />}
      </div>

      {counting && (
        <p className="m-0 mt-10 text-sm text-muted">
          {t('Counting page views only: nothing is read from the browser or kept about visitors.')}{' '}
          {can('settings.manage') && (
            <button type="button" className="underline underline-offset-2 hover:text-text" onClick={() => navigate('/settings/analytics')}>
              {t('Change what is counted')}
            </button>
          )}
        </p>
      )}
    </Page>
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

/** Visitors when they're known, else views. */
const count = (item: BreakdownItem) => item.visitors ?? item.views ?? 0

/** A breakdown as rows with bars: the largest value is a full bar. */
function Ranked({ title, icon, query, column, render, action }: { title: string; icon: string; query: ReturnType<typeof useBreakdown>; column: string; render: (item: BreakdownItem, max: number, total: number) => ReactNode; action?: ReactNode }) {
  const data: Breakdown | undefined = query.data
  const max = Math.max(1, ...(data?.items ?? []).map(count))

  return (
    <Widget
      title={title}
      icon={icon}
      query={query}
      action={action ?? <span className="text-2xs font-medium uppercase tracking-[0.04em] text-faint">{column}</span>}
      empty={data?.items.length === 0 && { icon, title: t('No visits in this period') }}
    >
      <div className={cn('space-y-0.5 transition-opacity duration-180', query.isPlaceholderData && 'opacity-60')}>{data?.items.map((item) => render(item, max, data.total))}</div>
    </Widget>
  )
}

/** The pages visited most, or the ones visits started on. */
function Pages({ period, dimension, counting = false }: { period: Period; dimension: 'pages' | 'entries'; counting?: boolean }) {
  const manifest = useManifest()
  const query = useBreakdown(period, dimension)
  const site = manifest.site.url?.replace(/\/$/, '') ?? ''

  return (
    <Ranked
      title={dimension === 'pages' ? t('Top Pages') : t('Entry Pages')}
      icon={dimension === 'pages' ? 'file' : 'arrow-right'}
      column={counting ? t('Views') : t('Visitors')}
      query={query}
      render={(item, max) => (
        <BarRow
          key={item.key}
          share={count(item) / max}
          href={site ? site + (item.path ?? '') : undefined}
          label={
            <span className="flex min-w-0 items-baseline gap-2">
              <span className="truncate">{item.label}</span>
              {item.label !== item.path && <span className="hidden truncate font-mono text-2xs text-faint sm:inline">{item.path}</span>}
            </span>
          }
          value={compactNumber(count(item))}
        />
      )}
    />
  )
}

/** Page views per language. */
function Languages({ period }: { period: Period }) {
  const query = useBreakdown(period, 'languages')
  return (
    <Ranked
      title={t('Languages')}
      icon="translate"
      column={t('Views')}
      query={query}
      render={(item, max) => <BarRow key={item.key} share={count(item) / max} label={item.label} value={compactNumber(count(item))} />}
    />
  )
}

function Sources({ period }: { period: Period }) {
  const query = useBreakdown(period, 'sources')
  return (
    <Ranked
      title={t('Sources')}
      icon="link"
      column={t('Visitors')}
      query={query}
      render={(item, max) => (
        <BarRow
          key={item.key ?? 'direct'}
          share={count(item) / max}
          label={item.key === null ? t('Direct') : item.label}
          value={compactNumber(count(item))}
        />
      )}
    />
  )
}

const percent = (value: number, total: number) => new Intl.NumberFormat(locale(), { style: 'percent', maximumFractionDigits: 0 }).format(total > 0 ? value / total : 0)

/** Devices by kind, browser or operating system, switched in the header. */
function Devices({ period }: { period: Period }) {
  const [dimension, setDimension] = useTab(['devices', 'browsers', 'os'], 'devices')
  const query = useBreakdown(period, dimension)
  const kinds: Record<string, [() => string, string]> = { desktop: [() => t('Desktop'), 'desktop'], mobile: [() => t('Mobile'), 'phone'], tablet: [() => t('Tablet'), 'tablet'] }

  return (
    <Ranked
      title={t('Devices')}
      icon="desktop"
      column=""
      query={query}
      action={<Segmented size="xs" value={dimension} onChange={setDimension} options={[{ value: 'devices', label: t('Type') }, { value: 'browsers', label: t('Browser') }, { value: 'os', label: t('System') }]} />}
      render={(item, max, total) => {
        const kind = dimension === 'devices' ? kinds[item.key ?? ''] : undefined
        return (
          <BarRow
            key={item.key}
            share={count(item) / max}
            icon={kind && <Icon name={kind[1]} size={14} className="shrink-0 text-faint" />}
            label={kind ? kind[0]() : item.key === 'Other' ? t('Other') : item.label}
            value={percent(count(item), total)}
          />
        )
      }}
    />
  )
}
