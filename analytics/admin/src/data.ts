import { locale, t, useAbility } from '@letterncms/sdk'
import { keepPreviousData } from '@tanstack/react-query'

export const PERIODS = ['30d', '7d', '90d'] as const
export type Period = (typeof PERIODS)[number]

/** In counts mode only views is known; the others are null. */
export interface Metrics {
  visitors: number | null
  views: number
  viewsPerVisit: number | null
  bounceRate: number | null
}

export interface Summary extends Metrics {
  period: Period
  from: string
  to: string
  mode: 'counts' | 'visitors'
  previous: Metrics
  series: (Metrics & { date: string })[]
  current: number | null
  enabled: boolean
  tracked: boolean
}

export interface BreakdownItem {
  key: string | null
  label: string
  visitors: number | null
  views?: number
  path?: string
  entry?: { id: string; collection: string } | null
}

export interface Breakdown {
  dimension: string
  total: number
  items: BreakdownItem[]
}

export type Metric = keyof Metrics

/** The four headline numbers: how each is named, formatted, and which way is good. */
export const METRICS: { key: Metric; label: () => string; good: 'up' | 'down'; counts?: boolean }[] = [
  { key: 'visitors', label: () => t('Visitors'), good: 'up' },
  { key: 'views', label: () => t('Page Views'), good: 'up', counts: true },
  { key: 'viewsPerVisit', label: () => t('Views per Visit'), good: 'up' },
  { key: 'bounceRate', label: () => t('Bounce Rate'), good: 'down' },
]

export function format(metric: Metric, value: number | null): string {
  if (value === null) return '–'
  if (metric === 'bounceRate') return new Intl.NumberFormat(locale(), { style: 'percent', maximumFractionDigits: 0 }).format(value)
  if (metric === 'viewsPerVisit') return new Intl.NumberFormat(locale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value)
  return new Intl.NumberFormat(locale()).format(value)
}

/** Change against the previous period as a fraction, or null when there is nothing to compare with. */
export function change(current: number | null, previous: number | null): number | null {
  return current !== null && previous !== null && previous > 0 ? (current - previous) / previous : null
}

export function useSummary(period: Period) {
  return useAbility<Summary>('analytics/summary', { period }, { placeholderData: keepPreviousData, refetchInterval: 60_000 })
}

export function useBreakdown(period: Period, dimension: string, limit = 8) {
  return useAbility<Breakdown>('analytics/breakdown', { period, dimension, limit }, { placeholderData: keepPreviousData })
}

/** "Oct 27" in the admin's language. */
export function shortDate(iso: string): string {
  return new Date(`${iso}T12:00:00`).toLocaleDateString(locale(), { month: 'short', day: 'numeric' })
}

export function longDate(iso: string): string {
  return new Date(`${iso}T12:00:00`).toLocaleDateString(locale(), { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
}
