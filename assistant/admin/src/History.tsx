import { can, run, t, timeAgo, useAction, useLocation } from '@letterncms/sdk'
import { Icon, SidebarRow, Spinner, Tooltip, cn, confirm, toast } from '@letterncms/ui'
import { useInfiniteQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { chatPath, conversationAt, panel, usePanel } from './store'

type Conversation = { id: string; title: string; updatedAt: string | null }
const pageSize = 20

/** Conversations follow the sidebar's pinned items, once there are any, with progressive disclosure for long histories. */
export function ChatHistory({ collapsed, onExpand, onNavigate }: { collapsed: boolean; onExpand: () => void; onNavigate?: () => void }) {
  const { path } = useLocation()
  const { drafts, conversation, title, working } = usePanel()
  const active = conversationAt(path)
  const [visible, setVisible] = useState(5)
  const [folded, setFolded] = useState(() => {
    try { return localStorage.getItem('cms.chats.folded') === 'true' } catch { return false }
  })
  const remove = useAction<{ deleted: boolean }, { id: string }>('assistant/conversation-delete', {
    invalidates: ['assistant/conversations'],
    onSuccess: (_, { id }) => { panel.deleted(id); toast.success(t('Chat deleted')) },
    onError: toast.error,
  })
  const deleteChat = async (chat: Conversation) => {
    if (working?.conversation === chat.id || remove.isPending) return
    if (await confirm({
      title: t('Delete “{title}”?', { title: chat.title || t('New Chat') }),
      description: t('This chat and its messages will be permanently deleted.'),
      confirm: t('Delete Chat'),
      danger: true,
    })) remove.mutate({ id: chat.id })
  }
  const allowed = can('assistant.use')
  const history = useInfiniteQuery({
    queryKey: ['assistant/conversations', 'sidebar'],
    initialPageParam: 0,
    queryFn: ({ pageParam }) => run<Conversation[]>('assistant/conversations', { limit: pageSize, offset: pageParam }),
    getNextPageParam: (last, pages) => last.length === pageSize ? pages.length * pageSize : undefined,
    enabled: allowed,
  })
  if (!allowed) return null
  const saved = history.data?.pages.flat() ?? []
  const chats = working?.conversation
    ? [{ id: working.conversation, title: working.title, updatedAt: saved.find((chat) => chat.id === working.conversation)?.updatedAt ?? null }, ...saved.filter((chat) => chat.id !== working.conversation)]
    : saved
  const creating = working?.conversation === null
  const shown = chats.slice(0, visible)
  const selected = chats.find((chat) => chat.id === active) ?? (active && active === conversation && title ? { id: active, title, updatedAt: null } : undefined)
  if (selected && !shown.includes(selected)) shown.push(selected)
  const more = chats.length > visible || history.hasNextPage
  // Nothing to show until there is a chat: the sidebar carries no empty Chats section.
  if (!chats.length && !creating && !drafts.new?.trim() && !history.isError) return null

  const toggle = () => {
    setFolded(!folded)
    try { localStorage.setItem('cms.chats.folded', String(!folded)) } catch { /* Optional preference. */ }
  }
  const showMore = async () => {
    if (visible >= chats.length && history.hasNextPage) {
      const result = await history.fetchNextPage()
      if (result.isError) return
    }
    setVisible((count) => count + 10)
  }

  if (collapsed) return (
    <Tooltip content={t('Chats')} side="right">
      <button type="button" aria-label={t('Chats')} onClick={() => { setFolded(false); onExpand() }} className={cn('flex h-8 w-full items-center justify-center rounded-md text-muted hover:bg-hover hover:text-text', active !== undefined && 'bg-active text-ink')}>
        {working ? <span role="status" aria-label={t('Working…')}><Spinner size={16} /></span> : <Icon name="chat" size={16} />}
      </button>
    </Tooltip>
  )

  return (
    <section aria-label={t('Chats')}>
      <button type="button" onClick={toggle} aria-expanded={!folded} aria-controls="cms-chat-history" className="group flex h-7 w-full items-center gap-1.5 rounded-md px-2 text-left text-xs font-medium text-muted hover:text-text">
        <span>{t('Chats')}</span>
        <Icon name={folded ? 'chevron-right' : 'chevron-down'} size={11} className="text-faint" />
      </button>
      <div id="cms-chat-history" hidden={folded} className="space-y-px">
        {(creating || drafts.new?.trim()) && (
          <SidebarRow
            label={(creating ? working.title : drafts.new) || t('New Chat')}
            onClick={() => { if (panel.select(null)) onNavigate?.() }}
            active={active === null}
            status={creating ? <span role="status" aria-label={t('Working…')}><Spinner size={12} /></span> : t('Draft')}
            actions={[!creating && { label: t('Discard Draft'), icon: 'close', quick: true, onSelect: () => panel.discard() }]}
          />
        )}
        {shown.map((chat) => (
          <SidebarRow
            key={chat.id}
            to={chatPath(chat.id)}
            onClick={() => onNavigate?.()}
            label={chat.title || t('New Chat')}
            title={`${chat.title} · ${timeAgo(chat.updatedAt)}`}
            active={active === chat.id}
            status={(working?.conversation === chat.id || drafts[chat.id]?.trim()) ? <>
              {working?.conversation === chat.id && <span role="status" aria-label={t('Working…')}><Spinner size={12} /></span>}
              {drafts[chat.id]?.trim() && <span>{t('Draft')}</span>}
            </> : undefined}
            actions={[{ label: t('Delete Chat'), icon: 'trash', danger: true, quick: true, disabled: working?.conversation === chat.id || remove.isPending, onSelect: () => void deleteChat(chat) }]}
          />
        ))}
        {history.isError && <button type="button" onClick={() => void history.refetch()} className="px-2 py-1.5 text-left text-xs text-muted hover:text-text">{t('Could not load chats. Try again')}</button>}
        {more && <button type="button" onClick={() => void showMore()} disabled={history.isFetchingNextPage} className="flex h-7 w-full items-center rounded-md px-2 text-xs text-muted hover:bg-hover hover:text-text disabled:opacity-50">{history.isFetchingNextPage ? t('Loading…') : t('Show more')}</button>}
        {visible > 5 && <button type="button" onClick={() => setVisible(5)} className="flex h-7 w-full items-center rounded-md px-2 text-xs text-muted hover:bg-hover hover:text-text">{t('Show less')}</button>}
      </div>
    </section>
  )
}
