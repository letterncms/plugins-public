import { can, navigate, plural, t, useAbility } from '@letterncms/sdk'
import { BarRow, Button, EmptyState, Icon, QueryError, Section, Segmented, Skeleton, cn } from '@letterncms/ui'
import { keepPreviousData } from '@tanstack/react-query'
import { useState } from 'react'
import { FilterBar, PeriodPicker } from './Toolbar'
import { compact, eventLabel, percent, useReport } from './data'

interface Goals { pages: { name: string; path: string }[]; funnels: { name: string; steps: string[] }[]; events: string[] }
interface Funnel { name: string | null; conversionRate: number; steps: { label: string; page: string | null; visitors: number; share: number; dropoff: number }[] }
interface Paths {
  page: string | null
  paths?: { pages: { path: string; label: string }[]; visitors: number }[]
  before?: { key: string | null; label: string; path: string | null; visitors: number }[]
  after?: { key: string | null; label: string; path: string | null; visitors: number }[]
}

/**
 * The Journeys tab: how visits move through the site. Funnels from the settings, step by step with who
 * dropped off where, and the paths visits take: from the start, or around the page filtered on.
 */
export default function Journeys() {
  const report = useReport()
  const page = report.filters.find((f) => f.dimension === 'page' && f.op === 'is' && f.value && !f.value.includes('*'))?.value ?? null

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <FilterBar />
        <div className="ml-auto"><PeriodPicker /></div>
      </div>
      <Funnels />
      {page ? <Around page={page} /> : <CommonPaths />}
    </div>
  )
}

function Funnels() {
  const report = useReport()
  const goals = useAbility<Goals>('analytics/goals')
  const funnels = goals.data?.funnels ?? []
  const [chosen, setChosen] = useState<string | null>(null)
  const name = funnels.some((f) => f.name === chosen) ? chosen! : funnels[0]?.name
  const funnel = useAbility<Funnel>('analytics/funnel', { ...report.input, funnel: name }, { enabled: !!name, placeholderData: keepPreviousData })

  if (goals.isError) return <Section title={t('Funnels')}><QueryError compact title={t('Couldn’t load the funnels')} onRetry={() => goals.refetch()} /></Section>
  if (!goals.data) return <Section title={t('Funnels')}><Skeleton className="h-40" /></Section>
  if (!funnels.length) {
    return (
      <Section title={t('Funnels')}>
        <EmptyState compact icon="filter" title={t('No funnels yet')} action={can('settings.manage') && <Button size="sm" onClick={() => navigate('/analytics?tab=settings')}>{t('Add Funnel')}</Button>} />
      </Section>
    )
  }

  const f = funnel.data
  return (
    <Section
      title={t('Funnels')}
      actions={funnels.length > 1 && <Segmented size="xs" value={name!} onChange={setChosen} options={funnels.map((x) => ({ value: x.name, label: x.name }))} aria-label={t('Funnel')} />}
    >
      {funnel.isError ? (
        <QueryError compact title={t('Couldn’t load the funnel')} onRetry={() => funnel.refetch()} retrying={funnel.isFetching} />
      ) : !f ? (
        <Skeleton className="h-40" />
      ) : (
        <div className={cn('transition-opacity duration-move', funnel.isPlaceholderData && 'opacity-60')}>
          <p className="m-0 mb-4 text-sm text-muted">{t('{rate} of visitors went all the way', { rate: percent(f.conversionRate, 1) })}</p>
          <ol className="m-0 list-none space-y-3 p-0">
            {f.steps.map((step, i) => (
              <li key={i} className="min-w-0">
                <div className="mb-1 flex items-baseline gap-2 text-xs text-muted">
                  <span className="tabular-nums text-faint">{i + 1}</span>
                  <span className={cn('truncate text-text', step.page && 'font-mono text-xs')}>{step.page ?? eventLabel(step.label)}</span>
                  {i > 0 && step.dropoff > 0 && <span className="ml-auto shrink-0 tabular-nums">{t('{rate} dropped off', { rate: percent(step.dropoff) })}</span>}
                </div>
                <BarRow label={plural(step.visitors, '{count} visitor', '{count} visitors')} share={step.share} value={percent(step.share)} />
              </li>
            ))}
          </ol>
        </div>
      )}
    </Section>
  )
}

function CommonPaths() {
  const report = useReport()
  const [length, setLength] = useState<'2' | '3' | '4'>('3')
  const paths = useAbility<Paths>('analytics/paths', { ...report.input, length: Number(length), limit: 10 }, { placeholderData: keepPreviousData })
  const rows = paths.data?.paths ?? []
  const top = rows[0]?.visitors ?? 0

  return (
    <Section title={t('Common Paths')} actions={<Segmented size="xs" value={length} onChange={setLength} aria-label={t('Pages per path')} options={[{ value: '2', label: t('2 Pages') }, { value: '3', label: t('3 Pages') }, { value: '4', label: t('4 Pages') }]} />}>
      {paths.isError ? (
        <QueryError compact title={t('Couldn’t load the paths')} onRetry={() => paths.refetch()} retrying={paths.isFetching} />
      ) : !paths.data ? (
        <div className="space-y-1.5">{Array.from({ length: 5 }, (_, i) => <Skeleton key={i} className="h-8" />)}</div>
      ) : !rows.length ? (
        <EmptyState compact icon="flow-arrow" title={t('No visits in this period')} />
      ) : (
        <ul className={cn('m-0 list-none space-y-0.5 p-0 transition-opacity duration-move', paths.isPlaceholderData && 'opacity-60')}>
          {rows.map((row, i) => (
            <li key={i} className="min-w-0">
              <BarRow
                share={top > 0 ? row.visitors / top : 0}
                value={compact(row.visitors)}
                label={
                  <span className="flex min-w-0 items-center gap-1.5">
                    {row.pages.map((p, j) => (
                      <span key={j} className="flex min-w-0 items-center gap-1.5">
                        {j > 0 && <Icon name="chevron-right" size={12} className="shrink-0 text-faint" />}
                        <span className="truncate">{p.label}</span>
                      </span>
                    ))}
                  </span>
                }
                to={report.linkWith({ dimension: 'page', op: 'is', value: row.pages[0].path })}
              />
            </li>
          ))}
        </ul>
      )}
    </Section>
  )
}

/** Around one page: where its visitors were just before, and where they went next. */
function Around({ page }: { page: string }) {
  const report = useReport()
  const paths = useAbility<Paths>('analytics/paths', { ...report.input, page, limit: 8 }, { placeholderData: keepPreviousData })
  const list = (title: string, rows: Paths['before'], none: string) => {
    const total = (rows ?? []).reduce((sum, r) => sum + r.visitors, 0)
    return (
      <Section title={title}>
        {!rows ? <Skeleton className="h-32" /> : !rows.length ? <EmptyState compact icon="flow-arrow" title={t('No visits in this period')} /> : (
          <ul className="m-0 list-none space-y-0.5 p-0">
            {rows.map((r) => (
              <li key={r.key ?? ''} className="min-w-0">
                <BarRow label={r.path === null ? none : r.label} share={total > 0 ? r.visitors / total : 0} value={compact(r.visitors)} to={r.path ? report.linkWith({ dimension: 'page', op: 'is', value: r.path }) : undefined} />
              </li>
            ))}
          </ul>
        )}
      </Section>
    )
  }

  if (paths.isError) return <QueryError compact title={t('Couldn’t load the paths')} onRetry={() => paths.refetch()} retrying={paths.isFetching} />
  return (
    <div className="grid min-w-0 gap-8 md:grid-cols-2">
      {list(t('Came From'), paths.data?.before, t('Another website, or nowhere'))}
      {list(t('Went Next'), paths.data?.after, t('Left the site'))}
    </div>
  )
}
