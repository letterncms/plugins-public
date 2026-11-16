import { locale, setQuery, t, useAbility, useLocation } from '@letterncms/sdk'
import { keepPreviousData } from '@tanstack/react-query'
import { useMemo } from 'react'

export const PERIODS = ['30d', 'realtime', 'today', 'yesterday', '7d', '90d', '12mo', 'custom'] as const
export type Period = (typeof PERIODS)[number]
export type Interval = 'minute' | 'hour' | 'day' | 'week' | 'month'

export const periodLabels: Record<Period, () => string> = {
  realtime: () => t('Last 30 Minutes'),
  today: () => t('Today'),
  yesterday: () => t('Yesterday'),
  '7d': () => t('Last 7 Days'),
  '30d': () => t('Last 30 Days'),
  '90d': () => t('Last 90 Days'),
  '12mo': () => t('Last 12 Months'),
  custom: () => t('Custom Period'),
}

/** What a number of the whole site is narrowed to: `{dimension: 'source', op: 'is', value: 'Google'}`. Null is none (Direct). */
export interface Filter {
  dimension: Dimension
  op: 'is' | 'is_not' | 'contains'
  value: string | null
}

export type Dimension = 'page' | 'goal' | 'entry' | 'exit' | 'channel' | 'source' | 'medium' | 'campaign' | 'country' | 'device' | 'browser' | 'os'

/** In counts mode only views is known; the others are null. */
export interface Metrics {
  visitors: number | null
  visits: number | null
  views: number | null
  viewsPerVisit: number | null
  bounceRate: number | null
  visitDuration: number | null
  timeOnPage: number | null
  scrollDepth: number | null
  conversions: number | null
  conversionRate: number | null
}

export interface Summary extends Metrics {
  period: Period
  from: string
  to: string
  interval: Interval
  mode: 'counts' | 'visitors'
  filters: Filter[]
  previous: Metrics
  series: (Metrics & { date: string })[]
  previousSeries?: (Metrics & { date: string })[]
  current: number | null
  enabled: boolean
  tracked: boolean
  unavailable?: 'mode'
}

export interface Item {
  key: string | null
  label: string
  share: number
  visitors: number | null
  views?: number
  visits?: number
  bounceRate?: number
  visitDuration?: number
  timeOnPage?: number | null
  scrollDepth?: number | null
  events?: number
  conversionRate?: number | null
  kind?: 'event' | 'page'
  prop?: string
  path?: string
  url?: string
  icon?: string
  entry?: { id: string; collection: string } | null
}

export interface Breakdown {
  dimension: string
  total: number
  items: Item[]
  unavailable?: 'mode' | 'goal'
}

export type Metric = keyof Metrics

/** The headline numbers: how each is named, formatted, and which way is good. */
export const METRICS: Record<Metric, { label: () => string; good: 'up' | 'down'; whole?: boolean }> = {
  visitors: { label: () => t('Visitors'), good: 'up', whole: true },
  visits: { label: () => t('Visits'), good: 'up', whole: true },
  views: { label: () => t('Page Views'), good: 'up', whole: true },
  viewsPerVisit: { label: () => t('Views per Visit'), good: 'up' },
  bounceRate: { label: () => t('Bounce Rate'), good: 'down' },
  visitDuration: { label: () => t('Visit Duration'), good: 'up' },
  timeOnPage: { label: () => t('Time on Page'), good: 'up' },
  scrollDepth: { label: () => t('Scroll Depth'), good: 'up' },
  conversions: { label: () => t('Conversions'), good: 'up', whole: true },
  conversionRate: { label: () => t('Conversion Rate'), good: 'up' },
}

/** Which numbers a report shows: a page's are about reading it, a goal's about reaching it. */
export function metricsFor(summary: Summary | undefined, filters: Filter[]): Metric[] {
  if (summary?.mode === 'counts') return ['views']
  if (filters.some((f) => f.dimension === 'goal')) return ['conversions', 'conversionRate', 'visits', 'views', 'bounceRate', 'visitDuration']
  if (filters.some((f) => f.dimension === 'page' && f.op !== 'is_not')) return ['visitors', 'visits', 'views', 'bounceRate', 'timeOnPage', 'scrollDepth']
  return ['visitors', 'visits', 'views', 'viewsPerVisit', 'bounceRate', 'visitDuration']
}

export function number(value: number | null | undefined): string {
  return value === null || value === undefined ? '–' : new Intl.NumberFormat(locale()).format(value)
}

/** 1.2K at a glance; the whole number goes in a tooltip. */
export function compact(value: number | null | undefined): string {
  if (value === null || value === undefined) return '–'
  return new Intl.NumberFormat(locale(), { notation: value >= 10_000 ? 'compact' : 'standard', maximumFractionDigits: 1 }).format(value)
}

export function percent(value: number | null | undefined, digits = 0): string {
  return value === null || value === undefined ? '–' : new Intl.NumberFormat(locale(), { style: 'percent', maximumFractionDigits: digits }).format(value)
}

/** 1:05 for a minute and five seconds, 1:02:05 past the hour, 42s under a minute. */
export function duration(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) return '–'
  const s = Math.round(seconds)
  if (s < 60) return t('{count}s', { count: s })
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const rest = String(s % 60).padStart(2, '0')
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${rest}` : `${m}:${rest}`
}

export function format(metric: Metric, value: number | null | undefined): string {
  if (value === null || value === undefined) return '–'
  switch (metric) {
    case 'bounceRate':
    case 'conversionRate':
      return percent(value, value > 0 && value < 0.1 ? 1 : 0)
    case 'scrollDepth':
      return percent(value / 100)
    case 'viewsPerVisit':
      return new Intl.NumberFormat(locale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value)
    case 'visitDuration':
    case 'timeOnPage':
      return duration(value)
    default:
      return number(value)
  }
}

/** Change against the previous period as a fraction, or null when there is nothing to compare with. */
export function change(current: number | null | undefined, previous: number | null | undefined): number | null {
  return current !== null && current !== undefined && previous !== null && previous !== undefined && previous > 0 ? (current - previous) / previous : null
}

/** A step of a chart as the admin's language says it: 14:00, Nov 17, the week of Nov 17, November. */
export function stepLabel(step: string, interval: Interval, long = false): string {
  const options: Intl.DateTimeFormatOptions = interval === 'minute' || interval === 'hour'
    ? { hour: 'numeric', minute: '2-digit', ...(long ? { weekday: 'short', month: 'short', day: 'numeric' } : {}) }
    : interval === 'month'
      ? { month: long ? 'long' : 'short', year: 'numeric' }
      : { month: 'short', day: 'numeric', ...(long ? { weekday: 'short', year: 'numeric' } : {}) }
  const date = step.length === 7 ? new Date(`${step}-01T12:00:00`) : step.length > 10 ? new Date(step.replace(' ', 'T')) : new Date(`${step}T12:00:00`)
  const text = date.toLocaleString(locale(), options)
  return interval === 'week' && long ? t('Week of {date}', { date: text }) : text
}

export function shortDate(iso: string): string {
  return stepLabel(iso, 'day')
}

/** The filters in the address: `?f=source:is:Google`, one `f` per filter (several as `f=…&f=…` joined by a newline). */
function readFilters(raw: string | undefined): Filter[] {
  if (!raw) return []
  return raw.split('\n').flatMap((part) => {
    const [dimension, op, ...value] = part.split(':')
    if (!dimension || !['is', 'is_not', 'contains'].includes(op)) return []
    const text = value.join(':')
    return [{ dimension: dimension as Dimension, op: op as Filter['op'], value: text === '∅' ? null : text }]
  })
}

function writeFilters(filters: Filter[]): string | null {
  return filters.length ? filters.map((f) => `${f.dimension}:${f.op}:${f.value ?? '∅'}`).join('\n') : null
}

/**
 * What the Analytics place reports on, kept in the address so it can be linked and comes back on Back:
 * the period (`?period=`, with `from` and `to` for a custom one) and the filters (`?f=`).
 */
export function useReport() {
  const { query, path } = useLocation()
  const period: Period = (PERIODS as readonly string[]).includes(query.period) ? (query.period as Period) : '30d'
  const from = period === 'custom' ? query.from : undefined
  const to = period === 'custom' ? query.to : undefined
  const raw = query.f
  const filters = useMemo(() => readFilters(raw), [raw])
  const range = { period, ...(from ? { from } : {}), ...(to ? { to } : {}) }

  /** Where the place shows these filters and one more (the same dimension's filter replaced). */
  const linkWith = (filter: Filter) => {
    const params = new URLSearchParams(window.location.search)
    const next = writeFilters([...filters.filter((f) => f.dimension !== filter.dimension), filter])
    next ? params.set('f', next) : params.delete('f')
    return `${path}?${params.toString()}`
  }

  return {
    period,
    from,
    to,
    filters,
    range,
    input: { ...range, filters },
    setPeriod: (next: Period, dates?: { from: string; to: string }) => setQuery({ period: next === '30d' ? null : next, from: dates?.from ?? null, to: dates?.to ?? null }, false),
    setFilters: (next: Filter[]) => setQuery({ f: writeFilters(next) }, false),
    linkWith,
  }
}

export function useSummary(input: Record<string, unknown>, options: { compare?: boolean; refetch?: number } = {}) {
  const realtime = input.period === 'realtime'
  return useAbility<Summary>('analytics/summary', { ...input, ...(options.compare ? { compare: true } : {}) }, { placeholderData: keepPreviousData, refetchInterval: options.refetch ?? (realtime ? 15_000 : 60_000) })
}

export function useBreakdown(input: Record<string, unknown>, dimension: string, limit = 8, enabled = true) {
  return useAbility<Breakdown>('analytics/breakdown', { ...input, dimension, limit }, { placeholderData: keepPreviousData, enabled, refetchInterval: input.period === 'realtime' ? 15_000 : 60_000 })
}

/** The words for a dimension's value: Direct for no source, a country's name, an event of the script's own in the admin's language. */
export function valueLabel(dimension: string, item: Pick<Item, 'key' | 'label'>): string {
  const key = item.key
  if (dimension === 'sources' && key === null) return t('Direct')
  if (dimension === 'countries') return key ? regionName(key) : t('Unknown')
  if (dimension === 'channels') return channelLabels[key ?? 'direct']?.() ?? item.label
  if (dimension === 'devices') return ({ desktop: () => t('Desktop'), mobile: () => t('Mobile'), tablet: () => t('Tablet') } as Record<string, () => string>)[key ?? '']?.() ?? item.label
  if (dimension === 'goals') return eventLabel(item.label)
  if (item.label === 'Other') return t('Other')
  return item.label
}

export const channelLabels: Record<string, () => string> = {
  direct: () => t('Direct'),
  search: () => t('Search Engines'),
  ai: () => t('AI Assistants'),
  social: () => t('Social Networks'),
  email: () => t('Email'),
  paid: () => t('Paid Ads'),
  referral: () => t('Other Websites'),
  campaign: () => t('Campaigns'),
}

/** The script's own events in the admin's language; a site's own keep their names. */
export function eventLabel(name: string): string {
  return ({ 'Outbound Link': () => t('Outbound Link'), 'File Download': () => t('File Download'), 'Form Sent': () => t('Form Sent'), 'Page Not Found': () => t('Page Not Found') } as Record<string, () => string>)[name]?.() ?? name
}

export function regionName(code: string): string {
  try {
    return new Intl.DisplayNames([locale()], { type: 'region' }).of(code) ?? code
  } catch {
    return code
  }
}

/** A filter in words: "Source: Google", "Page is not /blog/*". */
export function filterLabel(filter: Filter): string {
  const dimension = ({
    page: () => t('Page'), goal: () => t('Goal'), entry: () => t('Entry Page'), exit: () => t('Exit Page'), channel: () => t('Channel'), source: () => t('Source'),
    medium: () => t('Medium||campaign'), campaign: () => t('Campaign'), country: () => t('Country'), device: () => t('Device'), browser: () => t('Browser'), os: () => t('System'),
  } as Record<Dimension, () => string>)[filter.dimension]()
  const plural = { page: 'pages', goal: 'goals', entry: 'entries', exit: 'exits', channel: 'channels', source: 'sources', medium: 'sources', campaign: 'campaigns', country: 'countries', device: 'devices', browser: 'browsers', os: 'os' }[filter.dimension]
  const value = valueLabel(plural, { key: filter.value, label: filter.value ?? '' })
  if (filter.op === 'is_not') return t('{dimension} is not {value}', { dimension, value })
  if (filter.op === 'contains') return t('{dimension} contains {value}', { dimension, value })
  return t('{dimension}: {value}', { dimension, value })
}
