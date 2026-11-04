import { locale, navigate, t, timeAgo, useAbility } from '@letterncms/sdk'
import { Button, Meter, Stat, Widget, compactNumber, type WidgetEmpty } from '@letterncms/ui'

type Stats =
  | { state: 'inactive' | 'no-key' }
  | { state: 'error'; message: string }
  | {
      state: 'ok'
      days: number
      requests: number
      delivered: number
      opens: number
      clicks: number
      hardBounces: number
      softBounces: number
      blocked: number
      spamReports: number
      invalid: number
      unsubscribed: number
      fetchedAt: string
    }

/** Home-screen widget: how the email Brevo sent this week fared. */
export default function WidgetStats() {
  const stats = useAbility<Stats>('brevo/stats', {}, { refetchInterval: 10 * 60_000 })
  const s = stats.data
  const settings = <Button size="sm" icon="settings" onClick={() => navigate('/settings/email')}>{t('Open Settings')}</Button>

  const empty: WidgetEmpty | null =
    s?.state === 'inactive'
      ? { icon: 'brevo', title: t('Brevo is not your mail transport'), action: settings }
      : s?.state === 'no-key'
        ? { icon: 'key', title: t('Add Your Brevo API Key'), action: settings }
        : s?.state === 'error'
          ? { icon: 'warning', title: t('No stats from Brevo'), description: s.message, action: <Button size="sm" variant="ghost" icon="refresh" onClick={() => void stats.refetch()}>{t('Try Again')}</Button> }
          : s?.state === 'ok' && s.requests === 0
            ? { icon: 'brevo', title: t('No email sent through Brevo this week') }
            : null

  return (
    <Widget action={{ label: t('Settings'), to: '/settings/email' }} query={stats} empty={empty}>
      {s?.state === 'ok' && <Report s={s} />}
    </Widget>
  )
}

function Report({ s }: { s: Extract<Stats, { state: 'ok' }> }) {
  const delivery = s.delivered / Math.max(1, s.requests)
  const bounces = s.hardBounces + s.softBounces
  const issues = [
    s.blocked > 0 && t('{count} blocked', { count: compactNumber(s.blocked) }),
    s.spamReports > 0 && t('{count} marked as spam', { count: compactNumber(s.spamReports) }),
    s.unsubscribed > 0 && t('{count} unsubscribed', { count: compactNumber(s.unsubscribed) }),
  ].filter(Boolean)

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-4 @md:grid-cols-4">
        <Stat size="sm" label={t('Delivered')} value={compactNumber(s.delivered)} />
        <Stat size="sm" label={t('Open Rate')} value={percent(s.opens / Math.max(1, s.delivered))} />
        <Stat size="sm" label={t('Click Rate')} value={percent(s.clicks / Math.max(1, s.delivered))} />
        <Stat size="sm" label={t('Bounced')} value={compactNumber(bounces)} />
      </div>

      <div>
        <div className="mb-1.5 flex items-baseline justify-between text-xs">
          <span className="text-muted">{t('Delivery Rate')}</span>
          <span className="tabular-nums text-text">{percent(delivery)}</span>
        </div>
        <Meter value={delivery} tone={delivery >= 0.97 ? 'success' : delivery >= 0.9 ? 'warning' : 'danger'} label={t('Delivery Rate')} />
      </div>

      <p className="m-0 flex flex-wrap gap-x-3 text-xs text-faint">
        {issues.length > 0 && <span className="text-muted">{issues.join(' · ')}</span>}
        <span>{t('Last 7 days, updated {time}', { time: timeAgo(s.fetchedAt) })}</span>
      </p>
    </div>
  )
}

function percent(value: number): string {
  return new Intl.NumberFormat(locale(), { style: 'percent', maximumFractionDigits: value < 0.1 ? 1 : 0 }).format(value)
}
