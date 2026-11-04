import './styles.css'
import { registry, t } from '@letterncms/sdk'
import { Button, toast } from '@letterncms/ui'
import { lazy } from 'react'

/** How to connect Claude, Cursor & co. over MCP: part of API & Agents on the Account screen. */
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
    <div id="mcp" className="scroll-mt-16 space-y-3 pt-2">
      {row(t('MCP Server URL'), endpoint)}
      {row('Claude Code', command)}
    </div>
  )
}

registry.panels.set('mcp-connect', { id: 'mcp-connect', slot: 'account.api', component: Agents, order: 10 })
registry.widgets.set('mcp-agents', lazy(() => import('./WidgetAgents')))
