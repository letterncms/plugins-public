import { can, navigate, t } from '@letterncms/sdk'
import { Button, Sparkline, Stat, Widget, Skeleton } from '@letterncms/ui'
import { change, format, shortDate, useSummary } from './data'

/** Home-screen widget: the last 30 days at a glance, with a daily trend. */
export default function WidgetOverview() {
  const summary = useSummary('30d')
  const s = summary.data
  const first = s?.series[0]
  const counting = s?.mode === 'counts'

  return (
    <Widget
      action={{ label: t('View Analytics'), to: '/analytics' }}
      query={summary}
      skeleton={
        <div className="space-y-4 pt-2">
          <div className="grid grid-cols-3 gap-4">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-12" />)}</div>
          <Skeleton className="h-14" />
        </div>
      }
      empty={
        s &&
        !s.tracked && {
          icon: 'chart',
          title: s.enabled ? t('No visits yet') : t('Analytics is off'),
          description: s.enabled ? t('Visits appear here as people open your published site.') : undefined,
          action: !s.enabled && can('settings.manage') && <Button size="sm" onClick={() => navigate('/analytics?tab=settings')}>{t('Open Settings')}</Button>,
        }
      }
    >
      {s && (
        <div className="pt-1">
          <div className="grid grid-cols-2 gap-x-6 gap-y-4 @md:grid-cols-3">
            {counting || <Stat label={t('Visitors')} value={format('visitors', s.visitors)} delta={change(s.visitors, s.previous.visitors)} />}
            <Stat label={t('Page Views')} value={format('views', s.views)} delta={change(s.views, s.previous.views)} />
            {counting || <Stat label={t('Bounce Rate')} value={format('bounceRate', s.bounceRate)} delta={change(s.bounceRate, s.previous.bounceRate)} good="down" className="hidden @md:block" />}
          </div>
          <Sparkline values={s.series.map((d) => (counting ? d.views : d.visitors ?? 0))} label={counting ? t('Page Views per Day') : t('Visitors per Day')} className="mt-5 h-14 w-full" />
          <div className="mt-1.5 flex justify-between text-2xs text-faint">
            <span>{first && shortDate(first.date)}</span>
            <span>{t('Last 30 days')}</span>
            <span>{t('Today')}</span>
          </div>
        </div>
      )}
    </Widget>
  )
}
