import { t, useManifest } from '@letterncms/sdk'
import { Icon, Spinner, Tooltip } from '@letterncms/ui'
import { available, panel, usePanel } from './store'

/** How the chat is doing while it's away, if there's anything to say. */
function Doing() {
  const { working, awaiting } = usePanel()
  if (awaiting) return <span role="status" aria-label={t('Waiting for your go-ahead')} className="size-1.5 rounded-full bg-signal ring-1 ring-border-strong" />
  if (working) return <span role="status" aria-label={t('Working…')}><Spinner size={12} /></span>
  return null
}

/** Beside New Chat in the sidebar. */
export function NavStatus() {
  return usePanel().mode === 'closed' ? <Doing /> : null
}

/**
 * At the far end of every screen's bar, where the chat's close button is once it's
 * open: back to the chat as you left it, on screens where the sidebar is away too.
 */
export function ChatToggle() {
  const { mode, working, awaiting } = usePanel()
  const { sidebar } = useManifest()
  if (mode !== 'closed' || !available() || sidebar.hidden.includes('chat')) return null
  return (
    <Tooltip content={t('Chat')} shortcut="mod+j" side="bottom">
      <button type="button" aria-label={t('Chat')} onClick={() => panel.open()} className="grid size-7 shrink-0 place-items-center rounded-sm text-muted outline-offset-[-2px] transition-colors hover:bg-hover hover:text-text">
        {working || awaiting ? <Doing /> : <Icon name="sparkle" size={15} />}
      </button>
    </Tooltip>
  )
}
