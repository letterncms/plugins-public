import './styles.css'
import { registry, t } from '@letterncms/sdk'
import { Button, Section, toast } from '@letterncms/ui'
import { lazy } from 'react'

/** How to connect Claude, Cursor & co. over MCP. */
function Agents() {
  const endpoint = `${window.location.origin}/mcp`
  const command = `claude mcp add --transport http cms ${endpoint}`
  const copy = (text: string) => navigator.clipboard.writeText(text).then(() => toast.success(t('Copied')))
  const row = (label: string, value: string) => (
    <div>
      <div className="mb-1 text-xs text-muted">{label}</div>
      <div className="flex h-9 items-center gap-2 rounded-sm bg-sunken pl-3 pr-1.5 font-mono text-xs">
        <span className="min-w-0 flex-1 truncate">{value}</span>
        <Button size="xs" variant="ghost" icon="copy" onClick={() => copy(value)}>{t('Copy')}</Button>
      </div>
    </div>
  )
  return (
    <div id="mcp">
      <Section title={t('Connect an AI Agent')} description={t('Agents can do what you can, over MCP. Every change is in the audit log.')}>
        <div className="space-y-3 text-sm">
          {row(t('Server URL for Claude, Cursor or any MCP client'), endpoint)}
          {row('Claude Code', command)}
          <p className="m-0 text-xs text-faint">{t('Or send an API token as Authorization: Bearer.')}</p>
        </div>
      </Section>
    </div>
  )
}

// A section of the Account screen, like core's own.
registry.panels.set('mcp-connect', { id: 'mcp-connect', slot: 'account.sections', component: Agents, order: 50 })
registry.widgets.set('mcp-agents', lazy(() => import('./WidgetAgents')))
