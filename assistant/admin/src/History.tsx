import { can, run, t, timeAgo, useAction } from '@letterncms/sdk'
import { IconButton, SidebarRow, Spinner, Tooltip, confirm, toast } from '@letterncms/ui'
import { useInfiniteQuery } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { panel, usePanel } from './store'

type Conversation = { id: string; title: string; updatedAt: string | null }
const pageSize = 30

function useChats() {
  const allowed = can('assistant.use')
  const { working } = usePanel()
  const history = useInfiniteQuery({
    queryKey: ['assistant/conversations', 'list'],
    initialPageParam: 0,
    queryFn: ({ pageParam }) => run<Conversation[]>('assistant/conversations', { limit: pageSize, offset: pageParam }),
    getNextPageParam: (last, pages) => last.length === pageSize ? pages.length * pageSize : undefined,
    enabled: allowed,
  })
  const saved = history.data?.pages.flat() ?? []
  const chats = working?.conversation
    ? [{ id: working.conversation, title: working.title, updatedAt: saved.find((chat) => chat.id === working.conversation)?.updatedAt ?? null }, ...saved.filter((chat) => chat.id !== working.conversation)]
    : saved
  return { history, chats }
}

function ChatRow({ chat, remove }: { chat: Conversation; remove: (chat: Conversation) => void }) {
  const { conversation, working, drafts } = usePanel()
  const busy = working?.conversation === chat.id
  const draft = !!drafts[chat.id]?.trim()
  return (
    <SidebarRow
      label={chat.title || t('New Chat')}
      title={`${chat.title} · ${timeAgo(chat.updatedAt)}`}
      onClick={() => panel.select(chat.id)}
      active={conversation === chat.id}
      status={busy || draft ? <>
        {busy && <span role="status" aria-label={t('Working…')}><Spinner size={12} /></span>}
        {draft && <span>{t('Draft')}</span>}
      </> : undefined}
      actions={[{ label: t('Delete Chat'), icon: 'trash', danger: true, quick: true, disabled: busy, onSelect: () => remove(chat) }]}
    />
  )
}

function useRemove() {
  const { working } = usePanel()
  const remove = useAction<{ deleted: boolean }, { id: string }>('assistant/conversation-delete', {
    invalidates: ['assistant/conversations'],
    onSuccess: (_, { id }) => { panel.deleted(id); toast.success(t('Chat deleted')) },
    onError: toast.error,
  })
  return async (chat: Conversation) => {
    if (working?.conversation === chat.id || remove.isPending) return
    if (await confirm({
      title: t('Delete “{title}”?', { title: chat.title || t('New Chat') }),
      description: t('This chat and its messages will be permanently deleted.'),
      confirm: t('Delete Chat'),
      danger: true,
    })) remove.mutate({ id: chat.id })
  }
}

/** The one list of chats, all of them, by age: where a new chat starts, and a column of its own at full size. */
export function History() {
  const { history, chats } = useChats()
  const remove = useRemove()
  const { drafts, working, conversation } = usePanel()
  const creating = working?.conversation === null
  const unsent = drafts.new?.trim()
  const day = 86_400_000
  const today = new Date().setHours(0, 0, 0, 0)
  const groups = [
    { label: t('Today'), from: today },
    { label: t('Previous 7 Days'), from: today - 7 * day },
    { label: t('Older'), from: -Infinity },
  ].map((group, i, all) => ({
    ...group,
    chats: chats.filter((chat) => {
      const at = chat.updatedAt ? new Date(chat.updatedAt).getTime() : Date.now()
      return at >= group.from && (i === 0 || at < all[i - 1].from)
    }),
  })).filter((group) => group.chats.length > 0)

  // More of them as the list nears its end.
  const more = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const end = more.current
    if (!end) return
    const observer = new IntersectionObserver(([entry]) => { if (entry.isIntersecting && history.hasNextPage && !history.isFetchingNextPage) void history.fetchNextPage() }, { rootMargin: '200px' })
    observer.observe(end)
    return () => observer.disconnect()
  }, [history.hasNextPage, history.isFetchingNextPage, history.fetchNextPage])

  return (
    <nav aria-label={t('Chats')} className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-2 pb-4 pt-1">
      {/* A chat waiting to be sent, where it isn't already on view. */}
      {(creating || (unsent && conversation !== null)) && (
        <SidebarRow
          label={(creating ? working.title : unsent) || t('New Chat')}
          onClick={() => panel.select(null)}
          active={conversation === null}
          status={creating ? <span role="status" aria-label={t('Working…')}><Spinner size={12} /></span> : t('Draft')}
          actions={[!creating && { label: t('Discard Draft'), icon: 'close', quick: true, onSelect: () => panel.set({ drafts: { ...panel.get().drafts, new: '' } }) }]}
        />
      )}
      {groups.map((group) => (
        <section key={group.label} aria-label={group.label} className="mt-2 first:mt-0">
          <h3 className="m-0 flex h-7 items-center px-2 text-xs font-medium text-faint">{group.label}</h3>
          <div className="space-y-px">{group.chats.map((chat) => <ChatRow key={chat.id} chat={chat} remove={(c) => void remove(c)} />)}</div>
        </section>
      ))}
      {history.isPending && <div className="grid h-24 place-items-center text-faint"><Spinner /></div>}
      {history.isError && <button type="button" onClick={() => void history.refetch()} className="px-2 py-1.5 text-left text-xs text-muted hover:text-text">{t('Could not load chats. Try again')}</button>}
      {history.hasNextPage && <div ref={more} className="grid h-10 place-items-center text-faint">{history.isFetchingNextPage && <Spinner size={14} />}</div>}
    </nav>
  )
}

export function NewChatButton() {
  return (
    <Tooltip content={t('New Chat')}>
      <IconButton icon="plus" label={t('New Chat')} title={undefined} onClick={() => panel.select(null)} />
    </Tooltip>
  )
}
