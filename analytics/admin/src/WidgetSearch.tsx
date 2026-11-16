import { can, navigate, t, useAbility } from '@letterncms/sdk'
import { BarRow, Button, Stat, Widget, WidgetSkeleton } from '@letterncms/ui'
import { change, compact, number } from './data'

interface Metrics { clicks: number; impressions: number; ctr: number; position: number | null }

/** Home-screen widget: clicks from Google Search in the last 30 days, and the searches they came from. */
export default function WidgetSearch() {
  const status = useAbility<{ connected: boolean; problem: string | null }>('analytics/search', { report: 'status' }, { staleTime: 300_000 })
  const connected = status.data?.connected === true
  const summary = useAbility<Metrics & { previous: Metrics }>('analytics/search', { period: '30d', report: 'summary' }, { enabled: connected })
  const queries = useAbility<{ items: (Metrics & { key: string; label: string })[] }>('analytics/search', { period: '30d', report: 'queries', limit: 5 }, { enabled: connected })
  const s = summary.data
  const items = queries.data?.items ?? []
  const top = Math.max(1, ...items.map((i) => i.clicks))

  return (
    <Widget
      action={{ label: t('View Search'), to: '/analytics?tab=search' }}
      query={connected ? summary : status}
      skeleton={<WidgetSkeleton rows={5} />}
      empty={status.data && !connected && {
        icon: 'search',
        title: status.data.problem ? t('Search Console needs attention') : t('Search Console isn’t connected'),
        action: can('settings.manage') && <Button size="sm" onClick={() => navigate('/analytics?tab=settings')}>{status.data.problem ? t('Open Settings') : t('Connect Search Console')}</Button>,
      }}
    >
      {s && (
        <div className="pt-1">
          <div className="grid grid-cols-2 gap-x-6">
            <Stat label={t('Clicks')} value={number(s.clicks)} delta={change(s.clicks, s.previous.clicks)} />
            <Stat label={t('Impressions')} value={compact(s.impressions)} delta={change(s.impressions, s.previous.impressions)} />
          </div>
          <ul className="m-0 mt-4 list-none space-y-0.5 p-0">
            {items.map((item) => <li key={item.key}><BarRow label={item.label} share={item.clicks / top} value={compact(item.clicks)} /></li>)}
          </ul>
        </div>
      )}
    </Widget>
  )
}
