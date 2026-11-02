import { t, useManifest } from '@letterncms/sdk'
import { Icon, Spinner, Tooltip, cn } from '@letterncms/ui'
import { available, panel, useFloating, usePanel } from './store'

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

/** At the far end of every screen's bar: opens the chat as you left it, and closes it where it floats. A docked one has its own close button in that spot. */
export function ChatToggle() {
  const { mode, working, awaiting } = usePanel()
  const { chosen, required } = useFloating()
  const { sidebar } = useManifest()
  const docked = mode !== 'closed' && !chosen && !required
  if (docked || !available() || sidebar.hidden.includes('chat')) return null
  const open = mode !== 'closed'
  return (
    <Tooltip content={t('Chat')} shortcut="mod+j" side="bottom">
      <button type="button" aria-label={t('Chat')} aria-expanded={open} onClick={() => (open ? panel.close() : panel.open())} className={cn('grid size-7 shrink-0 place-items-center rounded-sm outline-offset-[-2px] transition-colors', open ? 'bg-active text-ink' : 'text-muted hover:bg-hover hover:text-text')}>
        {!open && (working || awaiting) ? <Doing /> : <Icon name="sparkle" size={15} />}
      </button>
    </Tooltip>
  )
}
