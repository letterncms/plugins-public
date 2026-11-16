import { locale, t } from '@letterncms/sdk'
import { BarRow, Widget, WidgetSkeleton } from '@letterncms/ui'
import { compact, useBreakdown, useSummary } from './data'

/** Home-screen widget: who is on the site now, and what they read in the last half hour. Visitors mode. */
export default function WidgetNow() {
  const summary = useSummary({ period: 'realtime' })
  const s = summary.data
  const counting = s?.mode === 'counts'
  const pages = useBreakdown({ period: 'realtime' }, 'pages', 5, !!s && !counting)
  const items = pages.data?.items ?? []

  return (
    <Widget
      action={{ label: t('View Analytics'), to: '/analytics?period=realtime' }}
      query={summary}
      skeleton={<WidgetSkeleton rows={4} />}
      empty={s && (counting ? { icon: 'activity', title: t('Counting page views only') } : !s.views ? { icon: 'activity', title: t('Nobody on the site right now') } : null)}
    >
      {s && !counting && (
        <div className="pt-1">
          <p className="m-0 flex items-baseline gap-2">
            <span className="text-2xl font-semibold tabular-nums text-ink">{(s.current ?? 0).toLocaleString(locale())}</span>
            <span className="text-sm text-muted">{t('on the site now')}</span>
          </p>
          <p className="m-0 mt-1 text-xs text-muted">{t('{count} page views in the last 30 minutes', { count: (s.views ?? 0).toLocaleString(locale()) })}</p>
          <ul className="m-0 mt-4 list-none space-y-0.5 p-0">
            {items.map((item) => (
              <li key={item.key ?? ''}><BarRow label={item.label} share={item.share} value={compact(item.visitors)} to={`/analytics?period=realtime&f=${encodeURIComponent(`page:is:${item.key}`)}`} /></li>
            ))}
          </ul>
        </div>
      )}
    </Widget>
  )
}
