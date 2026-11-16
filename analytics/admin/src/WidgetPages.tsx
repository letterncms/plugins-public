import { t } from '@letterncms/sdk'
import { BarRow, Widget, WidgetSkeleton } from '@letterncms/ui'
import { compact, useBreakdown } from './data'

/** Home-screen widget: the pages read most in the last 30 days. */
export default function WidgetPages() {
  const pages = useBreakdown({ period: '30d' }, 'pages', 6)
  const items = pages.data?.items ?? []

  return (
    <Widget
      action={{ label: t('View Analytics'), to: '/analytics' }}
      query={pages}
      skeleton={<WidgetSkeleton rows={6} />}
      empty={pages.data && !items.length && { icon: 'file-text', title: t('No visits in this period') }}
    >
      <ul className="m-0 list-none space-y-0.5 p-0 pt-1">
        {items.map((item) => (
          <li key={item.key ?? ''}>
            <BarRow label={item.label} share={item.share} value={compact(item.visitors ?? item.views)} to={`/analytics?f=${encodeURIComponent(`page:is:${item.key}`)}`} />
          </li>
        ))}
      </ul>
    </Widget>
  )
}
