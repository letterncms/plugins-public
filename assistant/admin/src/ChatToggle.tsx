import { t, useManifest } from '@letterncms/sdk'
import { Button, Spinner, Tooltip, cn } from '@letterncms/ui'
import { available, chatHidden, panel, usePanel } from './store'

/** In every screen's bar: opens the chat as you left it, and closes it again. It stays in its place while the chat is out, pressed. */
export function ChatToggle() {
  const { mode, working, awaiting } = usePanel()
  useManifest()
  const open = mode !== 'closed'
  if (!available() || chatHidden()) return null
  return (
    <Tooltip content={t('Chat')} shortcut="mod+j" side="bottom">
      <Button variant="ghost" icon={!open && (working || awaiting) ? undefined : 'comment'} aria-pressed={open} onClick={() => (open ? panel.close() : panel.open())} className={cn('aria-pressed:bg-active aria-pressed:text-ink', !open && 'text-muted hover:text-text')}>
        {!open && awaiting && <span role="status" aria-label={t('Waiting for your go-ahead')} className="size-1.5 rounded-full bg-signal ring-1 ring-border-strong" />}
        {!open && !awaiting && working && <span role="status" aria-label={t('Working…')}><Spinner size={12} /></span>}
        <span className="max-sm:sr-only">{t('Chat')}</span>
      </Button>
    </Tooltip>
  )
}
