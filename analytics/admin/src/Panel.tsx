import { t } from '@letterncms/sdk'
import { BarRow, Button, EmptyState, Icon, QueryError, Section, Segmented, Skeleton, Tooltip, cn } from '@letterncms/ui'
import { useState, type ReactNode } from 'react'
import { compact, duration, number, percent, useBreakdown, useReport, valueLabel, type Dimension, type Item } from './data'

export interface Column {
  label: string
  value: (item: Item) => ReactNode
  /** The whole value in words, for its tooltip. */
  title?: (item: Item) => string
  /** Hidden in a narrow panel: the first column always shows. */
  wide?: boolean
}

export interface PanelTab {
  /** The breakdown it shows (analytics/breakdown's dimension). */
  dimension: string
  label: string
  /** The filter a row adds when it is pressed; none: rows only read. */
  filter?: Dimension
  /** The name column's heading. */
  name: string
  columns: Column[]
  /** What a row's share is of: drawn as the bar behind it. */
  icon?: (item: Item) => ReactNode
}

/** The usual columns: who, how long, how far, how often. Words are read when drawn, in the admin's language. */
export const columns = () => ({
  visitors: { label: t('Visitors'), value: (i: Item) => compact(i.visitors), title: (i: Item) => number(i.visitors) } satisfies Column,
  views: { label: t('Page Views'), value: (i: Item) => compact(i.views), title: (i: Item) => number(i.views) } satisfies Column,
  share: { label: t('Share'), value: (i: Item) => percent(i.share), wide: true } satisfies Column,
  time: { label: t('Time on Page'), value: (i: Item) => duration(i.timeOnPage), wide: true } satisfies Column,
  scroll: { label: t('Scroll Depth'), value: (i: Item) => (i.scrollDepth === null || i.scrollDepth === undefined ? '–' : percent(i.scrollDepth / 100)), wide: true } satisfies Column,
  bounce: { label: t('Bounce Rate'), value: (i: Item) => percent(i.bounceRate), wide: true } satisfies Column,
  events: { label: t('Times'), value: (i: Item) => compact(i.events), title: (i: Item) => number(i.events), wide: true } satisfies Column,
  conversion: { label: t('Conversion Rate'), value: (i: Item) => percent(i.conversionRate, 1), wide: true } satisfies Column,
})

/**
 * One breakdown of the period on view, most first, as bars of their share: a few at a glance and more on
 * request. With tabs, the same place shows related breakdowns (sources, channels, campaigns). Pressing a
 * row narrows the whole report to it.
 */
export function Panel({ title, tabs, input, limit = 8, hideSingle = false }: { title: string; tabs: PanelTab[]; input: Record<string, unknown>; limit?: number; /** Nothing to compare with one row (one language): not drawn. */ hideSingle?: boolean }) {
  const [picked, setPicked] = useState(tabs[0].dimension)
  const [more, setMore] = useState(false)
  const tab = tabs.find((x) => x.dimension === picked) ?? tabs[0]
  const shown = more ? 50 : limit
  const query = useBreakdown(input, tab.dimension, shown)
  const report = useReport()
  const items = query.data?.items ?? []

  if (hideSingle && query.data && !query.isPlaceholderData && items.length <= 1) return null

  const heading = tabs.length > 1
    ? <Segmented size="xs" aria-label={title} value={tab.dimension} onChange={(d) => { setPicked(d); setMore(false) }} options={tabs.map((x) => ({ value: x.dimension, label: x.label }))} />
    : undefined

  return (
    <Section title={title} actions={heading} className="@container min-w-0">
      {query.isError ? (
        <QueryError compact title={t('Couldn’t load {title}', { title: title.toLowerCase() })} onRetry={() => query.refetch()} retrying={query.isFetching} />
      ) : !query.data ? (
        <div className="space-y-1.5">{Array.from({ length: 5 }, (_, i) => <Skeleton key={i} className="h-8" />)}</div>
      ) : items.length === 0 ? (
        <EmptyState compact icon="chart" title={t('No visits in this period')} />
      ) : (
        <div className={cn('transition-opacity duration-move', query.isPlaceholderData && 'opacity-60')}>
          <div className="mb-1 flex items-center gap-3 text-2xs font-medium text-faint">
            <span className="flex-1 pl-2">{tab.name}</span>
            <Values columns={tab.columns} render={(c) => c.label} />
          </div>
          <ul className="m-0 list-none space-y-0.5 p-0">
            {items.map((item) => {
              const label = valueLabel(tab.dimension, item)
              const active = tab.filter && report.filters.some((f) => f.dimension === tab.filter && f.value === item.key && f.op === 'is')
              return (
                <li key={`${item.key ?? ''}|${item.prop ?? ''}`} className="min-w-0">
                  <BarRow
                    label={
                      <span className="flex min-w-0 items-baseline gap-2">
                        <span className={cn('truncate', active && 'font-medium')}>{label}</span>
                        {item.path && item.path !== label && <span className="hidden shrink truncate font-mono text-2xs text-faint @sm:inline">{item.path}</span>}
                        {item.prop && <span className="shrink-0 text-2xs text-faint">{item.prop}</span>}
                      </span>
                    }
                    icon={tab.icon?.(item)}
                    share={item.share}
                    value={<Values columns={tab.columns} item={item} render={(c, i) => c.title ? <Tooltip content={c.title(i!)}><span>{c.value(i!)}</span></Tooltip> : c.value(i!)} />}
                    to={tab.filter && !active ? report.linkWith({ dimension: tab.filter, op: 'is', value: item.key }) : undefined}
                  />
                </li>
              )
            })}
          </ul>
          {(items.length >= shown || more) && (
            <Button variant="ghost" size="sm" className="mt-1" onClick={() => setMore(!more)}>{more ? t('Show Fewer') : t('Show More')}</Button>
          )}
        </div>
      )}
    </Section>
  )
}

/** The numbers of a row, or the headings above them, in columns of the same widths; the wide ones only where there is room. */
function Values({ columns, item, render }: { columns: Column[]; item?: Item; render: (c: Column, item?: Item) => ReactNode }) {
  return (
    <span className="flex shrink-0 items-center gap-3 pr-1">
      {columns.map((c, i) => (
        <span key={c.label} className={cn('w-16 text-right tabular-nums', c.wide && i > 0 && 'hidden @md:inline-block', !c.wide || i === 0 ? 'inline-block' : '')}>
          {render(c, item)}
        </span>
      ))}
    </span>
  )
}

export function DeviceIcon({ item }: { item: Item }) {
  return item.icon ? <Icon name={item.icon} size={14} className="shrink-0 text-muted" /> : null
}
