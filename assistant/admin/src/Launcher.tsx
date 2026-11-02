import { events, t, useManifest, useScreen } from '@letterncms/sdk'
import { ContextMenu, Icon, Kbd, Spinner, Tooltip, cn } from '@letterncms/ui'
import { available, panel, usePanel } from './store'

/**
 * The way to the chat, on every screen: where the composer will be, saying what
 * it would be about. While the chat is away it says how the chat is doing.
 */
export function Launcher({ collapsed, onNavigate }: { collapsed: boolean; onNavigate?: () => void }) {
  const { sidebar } = useManifest()
  const { mode, working, awaiting, drafts, conversation } = usePanel()
  const screen = useScreen()
  // Its nav item carries its pin.
  if (!available() || sidebar.hidden.includes('chat')) return null

  const open = mode !== 'closed'
  const draft = drafts[conversation ?? 'new']?.trim()
  const label = awaiting ? t('Waiting for your go-ahead')
    : working ? working.title || t('Working…')
    : draft ? draft
    : !screen.private && screen.title ? t('Ask about {page}…', { page: screen.title })
    : t('Ask {brand}…')
  const activate = () => {
    if (open) panel.close()
    else panel.open()
    onNavigate?.()
  }
  const status = awaiting ? <span className="size-1.5 shrink-0 rounded-full bg-signal ring-1 ring-border-strong" /> : working ? <Spinner size={14} /> : <Icon name="sparkle" size={15} className="shrink-0" />

  const menu = [
    { label: t('Unpin'), icon: 'pin-off', onSelect: () => events.emit('sidebar.pin', { id: 'chat', pinned: false }) },
    { separator: true, label: '' },
    { label: t('Customize Sidebar…'), icon: 'sidebar', onSelect: () => events.emit('sidebar.customize') },
  ]

  if (collapsed) {
    return (
      <div className="shrink-0 px-2 pb-1">
        <ContextMenu items={menu}>
          <div>
            <Tooltip content={label} shortcut="mod+j" side="right">
              <button type="button" aria-label={label} aria-expanded={open} onClick={activate} className={cn('flex h-8 w-full items-center justify-center rounded-md outline-offset-[-2px]', open ? 'bg-active text-ink' : 'text-muted hover:bg-hover hover:text-text')}>
                {status}
              </button>
            </Tooltip>
          </div>
        </ContextMenu>
      </div>
    )
  }

  return (
    <div className="shrink-0 px-2 pb-2">
      <ContextMenu items={menu}>
        <button
          type="button"
          onClick={activate}
          aria-expanded={open}
          className={cn(
            'flex h-9 w-full items-center gap-2 rounded-md border bg-bg pl-2.5 pr-2 text-left text-sm outline-offset-[-1px] transition-colors',
            open ? 'border-border-strong text-text' : 'border-border text-muted hover:border-border-strong hover:text-text',
          )}
        >
          {status}
          <span className="min-w-0 flex-1 truncate">{label}</span>
          <Kbd keys="mod+j" />
        </button>
      </ContextMenu>
    </div>
  )
}
