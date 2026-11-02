import { t } from '@letterncms/sdk'
import { Spinner } from '@letterncms/ui'
import { usePanel } from './store'

/** Beside New Chat in the sidebar, while the chat is away: how it is doing. */
export function NavStatus() {
  const { mode, working, awaiting } = usePanel()
  if (mode !== 'closed') return null
  if (awaiting) return <span role="status" aria-label={t('Waiting for your go-ahead')} className="size-1.5 rounded-full bg-signal ring-1 ring-border-strong" />
  if (working) return <span role="status" aria-label={t('Working…')}><Spinner size={12} /></span>
  return null
}
