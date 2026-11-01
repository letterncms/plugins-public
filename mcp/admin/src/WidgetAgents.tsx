import { navigate, t, timeAgo, useAbility } from '@letterncms/sdk'
import { Button, Widget, WidgetRow } from '@letterncms/ui'

interface Agents {
  agents: { name: string; connectedAt: string | null; lastUsedAt: string | null }[]
  connected: number
  activity: { id: string; ability: string; label: string; subject: string | null; createdAt: string }[]
}

/** Home-screen widget: the AI agents signed in to your account, and what they changed lately. */
export default function WidgetAgents() {
  const agents = useAbility<Agents>('mcp/agents', { limit: 4 }, { refetchInterval: 60_000 })
  const a = agents.data

  return (
    <Widget
      action={{ label: t('Connect'), to: '/account#mcp' }}
      query={agents}
      empty={
        a?.agents.length === 0 &&
        a.activity.length === 0 && {
          icon: 'mcp',
          title: t('No agents connected'),
          action: <Button size="sm" icon="plus" onClick={() => navigate('/account#mcp')}>{t('Connect an Agent')}</Button>,
        }
      }
    >
      {a && (
        <>
          {a.agents.map((agent) => (
            <WidgetRow
              key={agent.name}
              icon="robot"
              title={<span className="font-medium">{agent.name}</span>}
              meta={agent.lastUsedAt ? t('Active {time}', { time: timeAgo(agent.lastUsedAt) }) : t('Not used yet')}
            />
          ))}
          {a.activity.length > 0 && (
            <>
              <p className={a.agents.length ? 'm-0 mt-4 mb-1 text-xs text-muted' : 'm-0 mb-1 text-xs text-muted'}>{t('Recent Changes')}</p>
              {a.activity.map((entry) => (
                <WidgetRow key={entry.id} icon="activity" title={entry.label} description={entry.subject ?? undefined} meta={timeAgo(entry.createdAt)} />
              ))}
            </>
          )}
        </>
      )}
    </Widget>
  )
}
