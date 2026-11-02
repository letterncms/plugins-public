import { t, useManifest } from '@letterncms/sdk'
import { Button, Spinner, Tooltip, cn } from '@letterncms/ui'
import { available, chatHidden, panel, useFloating, usePanel } from './store'

/** In every screen's bar: opens the chat as you left it, and closes it where it floats. A docked one has its own close button. */
export function ChatToggle() {
  const { mode, working, awaiting } = usePanel()
  const { chosen, required } = useFloating()
  useManifest()
  const open = mode !== 'closed'
  if ((open && !chosen && !required) || !available() || chatHidden()) return null
  return (
    <Tooltip content={t('Chat')} shortcut="mod+j" side="bottom">
      <Button variant="ghost" icon={!open && (working || awaiting) ? undefined : 'comment'} aria-expanded={open} onClick={() => (open ? panel.close() : panel.open())} className={cn('mr-1', open ? 'bg-active text-ink' : 'text-muted hover:text-text')}>
        {!open && awaiting && <span role="status" aria-label={t('Waiting for your go-ahead')} className="size-1.5 rounded-full bg-signal ring-1 ring-border-strong" />}
        {!open && !awaiting && working && <span role="status" aria-label={t('Working…')}><Spinner size={12} /></span>}
        <span className="max-sm:sr-only">{t('Chat')}</span>
      </Button>
    </Tooltip>
  )
}
