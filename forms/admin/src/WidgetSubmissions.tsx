import { can, navigate, plural, t, timeAgo, useAbility } from '@letterncms/sdk'
import { Button, Icon, Sparkline, Stat, Widget, WidgetRow, compactNumber, cn } from '@letterncms/ui'

interface Activity {
  forms: number
  new: number
  /** Submissions per day, the last 14 days, oldest first. */
  days: number[]
  latest: { id: string; form: string; formTitle: string; summary: string; status: string; createdAt: string }[]
}

/** Home-screen widget: how many submissions came in, and the latest ones. */
export default function WidgetSubmissions() {
  const activity = useAbility<Activity>('forms/activity', { limit: 4 }, { refetchInterval: 60_000 })
  const a = activity.data
  const week = a ? sum(a.days.slice(7)) : 0
  const before = a ? sum(a.days.slice(0, 7)) : 0

  return (
    <Widget
      action={{ label: t('All Forms'), to: '/forms' }}
      query={activity}
      empty={
        a?.forms === 0
          ? { icon: 'form', title: t('No forms yet'), action: can('forms.manage') && <Button size="sm" icon="plus" onClick={() => navigate('/forms?do=new-form')}>{t('New Form')}</Button> }
          : a?.latest.length === 0 && { icon: 'inbox', title: t('No submissions yet') }
      }
    >
      {a && (
        <>
          <div className="grid grid-cols-2 gap-4 @md:grid-cols-4">
            <Stat size="sm" label={t('Unread')} value={compactNumber(a.new)} />
            <Stat size="sm" label={t('Last 7 days')} value={compactNumber(week)} delta={before ? (week - before) / before : null} />
            <div className="col-span-2 flex items-end">
              <Sparkline values={a.days} className="h-9 w-full" label={plural(sum(a.days), '{count} submission in the last 14 days', '{count} submissions in the last 14 days')} />
            </div>
          </div>
          <p className="m-0 mt-4 mb-1 text-xs text-muted">{t('Latest')}</p>
          {a.latest.map((s) => (
            <WidgetRow
              key={s.id}
              to={`/forms/${s.form}?tab=submissions`}
              leading={<Icon name={s.status === 'new' ? 'mail' : 'envelope-open'} size={15} className={cn('shrink-0', s.status === 'new' ? 'text-ink' : 'text-faint')} />}
              title={<span className={s.status === 'new' ? 'font-medium' : undefined}>{s.summary || t('No answers')}</span>}
              description={s.formTitle}
              meta={timeAgo(s.createdAt)}
            />
          ))}
        </>
      )}
    </Widget>
  )
}

function sum(values: number[]): number {
  return values.reduce((total, n) => total + n, 0)
}
