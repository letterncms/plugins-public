import { locale, t } from '@letterncms/sdk'
import { Button, DateInput, Field, Icon, IconButton, Popover, Tooltip } from '@letterncms/ui'
import { useState } from 'react'
import { filterLabel, periodLabels, useReport, type Period } from './data'

const SHOWN: Period[] = ['realtime', 'today', 'yesterday', '7d', '30d', '90d', '12mo']

/** The period: the usual ones in a list, and two dates for any other, in one popover from one button. */
export function PeriodPicker({ counting = false }: { counting?: boolean }) {
  const report = useReport()
  const [open, setOpen] = useState(false)
  const [dates, setDates] = useState<{ from: string | null; to: string | null }>({ from: report.from ?? null, to: report.to ?? null })
  const today = new Date().toLocaleDateString('sv')
  const day = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString(locale(), { month: 'short', day: 'numeric', year: 'numeric' })
  const label = report.period === 'custom' && report.from && report.to ? `${day(report.from)} – ${day(report.to)}` : periodLabels[report.period]()
  const valid = !!dates.from && !!dates.to && dates.from <= dates.to && dates.to <= today
  const choose = (period: Period, range?: { from: string; to: string }) => {
    report.setPeriod(period, range)
    setOpen(false)
  }

  return (
    <Popover open={open} onOpenChange={setOpen} align="end" className="w-80 p-1.5" trigger={<Button variant="secondary" size="sm" icon="calendar">{label}</Button>}>
      <div role="group" aria-label={t('Period')} className="flex flex-col">
        {SHOWN.filter((p) => !counting || p !== 'realtime').map((p) => (
          <Button key={p} variant="ghost" size="sm" aria-pressed={report.period === p} onClick={() => choose(p)} className="w-full justify-start font-normal">
            <span className="flex-1 text-left">{periodLabels[p]()}</span>
            {report.period === p && <Icon name="check" size={14} />}
          </Button>
        ))}
      </div>
      <form
        className="mt-1.5 space-y-3 border-t border-border px-1.5 pb-1.5 pt-3"
        onSubmit={(e) => {
          e.preventDefault()
          if (valid) choose('custom', { from: dates.from!, to: dates.to! })
        }}
      >
        <div className="grid grid-cols-2 gap-2">
          <Field label={t('From||date')}><DateInput size="sm" value={dates.from} onChange={(from) => setDates((d) => ({ ...d, from }))} aria-label={t('From||date')} /></Field>
          <Field label={t('To||date')}><DateInput size="sm" value={dates.to} onChange={(to) => setDates((d) => ({ ...d, to }))} aria-label={t('To||date')} /></Field>
        </div>
        <Button type="submit" size="sm" variant={report.period === 'custom' ? 'primary' : 'secondary'} disabled={!valid} className="w-full">{t('Show Period')}</Button>
      </form>
    </Popover>
  )
}

/** What the numbers are narrowed to, each with a way to take it off; nothing when there is nothing. */
export function FilterBar() {
  const report = useReport()
  if (!report.filters.length) return null

  return (
    <div className="flex min-w-0 flex-wrap items-center gap-1.5">
      {report.filters.map((filter, i) => {
        const words = filterLabel(filter)
        return (
          <span key={`${filter.dimension}-${i}`} className="inline-flex h-7 max-w-full items-center gap-0.5 rounded-sm bg-active pl-2.5 text-sm text-text">
            <span className="truncate">{words}</span>
            <Tooltip content={t('Remove Filter')}>
              <IconButton icon="close" size="xs" variant="ghost" label={t('Remove {filter}', { filter: words })} title={undefined} onClick={() => report.setFilters(report.filters.filter((_, j) => j !== i))} />
            </Tooltip>
          </span>
        )
      })}
      {report.filters.length > 1 && <Button variant="ghost" size="sm" onClick={() => report.setFilters([])}>{t('Clear Filters')}</Button>}
    </div>
  )
}
