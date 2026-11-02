import { can, events, getManifest, Link, run, t, useAbility, useAction, useLocation, useManifest, useScreen, type SurfaceAction } from '@letterncms/sdk'
import { Button, Dock, Icon, IconButton, Menu, Spinner, Surface, Textarea, Tooltip, cn, confirm, toast } from '@letterncms/ui'
import { useEffect, useLayoutEffect, useRef, useState, useSyncExternalStore, type KeyboardEvent } from 'react'
import { Composer } from './Composer'
import { attachments } from './context'
import { History, NewChatButton } from './History'
import { Markdown } from './Markdown'
import { available, conversationAt, panel, usePanel } from './store'
import { useChat, type Call, type Item, type Part } from './useChat'

interface Model {
  id: string
  name: string
  description: string
}

interface Status {
  configured: boolean
  /** The default from Settings → Assistant. */
  model: string
  models: Model[]
}

const PREFERENCE = 'assistant.model'

/** New chats start with the person's last pick, while it is still offered; otherwise with the default. */
function preferredModel(status: Status): string {
  const saved = getManifest().user.preferences?.[PREFERENCE]
  return status.models.some((m) => m.id === saved) ? (saved as string) : status.model
}

function rememberModel(id: string) {
  // The manifest is this session's copy of the preferences; keep it current for the next new chat.
  const user = getManifest().user
  user.preferences = { ...(user.preferences ?? {}), [PREFERENCE]: id }
  void run('account/update', { preferences: { [PREFERENCE]: id } }).catch(() => {})
}

const wideQuery = window.matchMedia('(min-width: 1024px)')
const useWide = () => useSyncExternalStore((change) => {
  wideQuery.addEventListener('change', change)
  return () => wideQuery.removeEventListener('change', change)
}, () => wideQuery.matches)

/**
 * The chat, on every screen: docked at the right edge, the page open and live
 * next to it. A chat's URL is the same surface at full size, the list of chats
 * beside it. It stays mounted while closed, so a reply keeps coming.
 */
export function AssistantPanel() {
  const { path } = useLocation()
  const { mode, title } = usePanel()
  const id = conversationAt(path)
  useLayoutEffect(() => {
    const state = panel.get()
    if (id === undefined) {
      if (path !== state.contextPath) panel.set({ contextPath: path, dismissed: [], selectedText: '' })
      if (state.mode === 'full') panel.set({ mode: 'docked' })
    } else {
      panel.set({ mode: 'full', history: false, ...(state.conversation !== id ? { conversation: id, prompt: null } : {}) })
    }
  }, [path, id])
  if (!available()) return null
  return (
    <Dock open={mode !== 'closed'} full={mode === 'full'} label={title || t('New Chat')}>
      <Chat open={mode !== 'closed'} full={mode === 'full'} />
    </Dock>
  )
}

function Chat({ open, full }: { open: boolean; full: boolean }) {
  const { conversation, prompt, drafts, models, focus: requestedFocus, history } = usePanel()
  const wide = useWide()
  const canMaximize = useSyncExternalStore((change) => {
    const media = window.matchMedia('(min-width: 768px)')
    media.addEventListener('change', change)
    return () => media.removeEventListener('change', change)
  }, () => window.matchMedia('(min-width: 768px)').matches)
  // At full size the list has a column of its own; in the dock it is the thread's second page.
  const column = full && wide
  const status = useAbility<Status>('assistant/status', {}, { staleTime: 60_000 })
  const chat = useChat(conversation, {
    picked: models[conversation ?? 'new'] ?? (conversation || !status.data ? undefined : preferredModel(status.data)),
    fallback: status.data?.model,
    offered: status.data?.models.map((m) => m.id),
  })
  useEffect(() => panel.set({ awaiting: chat.awaiting.length > 0 }), [chat.awaiting.length])
  const remove = useAction<{ deleted: boolean }, { id: string }>('assistant/conversation-delete', {
    invalidates: ['assistant/conversations'],
    onSuccess: (_, { id }) => {
      panel.deleted(id)
      toast.success(t('Chat deleted'))
    },
    onError: toast.error,
  })
  const draft = drafts[conversation ?? 'new'] ?? ''
  const setDraft = panel.draft
  // Bumped to move the focus into the composer.
  const [focus, setFocus] = useState(0)
  const [editing, setEditing] = useState<number | null>(null)
  const scroller = useRef<HTMLDivElement>(null)
  const pinned = useRef(true)

  // Prompts handed over from the command palette.
  useEffect(() => {
    if (!open || !prompt || chat.busy || chat.loading || remove.isPending || editing !== null || !status.data?.configured) return
    panel.set({ prompt: null })
    chat.send(prompt)
  }, [open, prompt, chat, remove.isPending, editing, status.data?.configured])

  useEffect(() => {
    pinned.current = true
    setEditing(null)
    if (open) setFocus((f) => f + 1)
  }, [conversation, open, requestedFocus])

  useLayoutEffect(() => {
    const el = scroller.current
    if (open && el && pinned.current) el.scrollTop = el.scrollHeight
  })

  const skill = usePanel().skills[conversation ?? 'new']
  const sendable = !!draft.trim() || !!skill
  const submit = () => {
    if (!sendable || chat.busy || chat.loading || remove.isPending || editing !== null) return
    pinned.current = true
    // A skill says enough by itself.
    chat.send(draft.trim() || skill!.label)
    setDraft('')
  }

  const ready = status.data?.configured === true
  const surfaces = new Map<string, string>()
  for (const item of chat.items) {
    if (item.role === 'assistant') for (const part of item.parts) {
      if (part.type === 'tool' && part.surface && part.status === 'ok') surfaces.set(part.surface.id, part.id)
    }
  }
  const surfaceProps = { disabled: chat.busy || chat.loading || chat.awaiting.length > 0 || editing !== null, onAction: chat.interact, surfaces }

  const deleteChat = async () => {
    if (!conversation || chat.busy || chat.loading || remove.isPending) return
    if (await confirm({
      title: t('Delete “{title}”?', { title: chat.title || t('New Chat') }),
      description: t('This chat and its messages will be permanently deleted.'),
      confirm: t('Delete Chat'),
      danger: true,
    })) remove.mutate({ id: conversation })
  }

  const empty = ready && !chat.loading && !chat.items.length
  const title = chat.title || t('New Chat')
  const listing = history && !column

  // Esc puts it away, unless something inside it takes the key first (a menu, a message being edited).
  const onKeyDown = (e: KeyboardEvent) => {
    if (e.key !== 'Escape' || e.defaultPrevented || document.querySelector('[role="dialog"], [role="menu"], [role="listbox"], [data-popup-open]')) return
    if (listing) panel.set({ history: false })
    else if (full) panel.minimize()
    else panel.close()
  }

  return (
    <div className="cms-assistant flex h-full min-h-0" data-full={full || undefined} onKeyDown={onKeyDown}>
      {column && (
        <div className="flex w-[260px] shrink-0 flex-col border-r border-border">
          <div className="flex h-12 shrink-0 items-center justify-between pl-[calc(1rem+var(--cms-sidebar-toggle,0px))] pr-2">
            <h2 className="m-0 text-sm font-semibold text-ink">{t('Chats')}</h2>
            <NewChatButton />
          </div>
          <History />
        </div>
      )}
      <section aria-label={title} data-empty={(full && empty) || undefined} className="cms-assistant-page relative flex min-w-0 flex-1 flex-col">
        <header className={cn('flex h-12 shrink-0 items-center gap-0.5 border-b border-border pr-2', full && !column ? 'pl-[calc(0.75rem+var(--cms-sidebar-toggle,0px))]' : 'pl-3')}>
          {listing && (
            <Tooltip content={t('Back to Chat')} side="bottom">
              <IconButton icon="arrow-left" label={t('Back to Chat')} title={undefined} className="-ml-1 mr-0.5" onClick={() => panel.set({ history: false })} />
            </Tooltip>
          )}
          <h2 className="m-0 min-w-0 flex-1 truncate text-sm font-medium text-ink">{listing ? t('Chats') : title}</h2>
          {!column && <NewChatButton />}
          {!column && conversation && !listing && (
            <Tooltip content={t('All Chats')} side="bottom">
              <IconButton icon="history" label={t('All Chats')} title={undefined} onClick={() => panel.set({ history: true })} />
            </Tooltip>
          )}
          {conversation && !listing && (
            <Menu
              trigger={<IconButton icon="more" label={t('Chat Actions')} disabled={remove.isPending} />}
              items={[{
                label: t('Delete Chat'),
                icon: 'trash',
                danger: true,
                disabled: chat.busy || chat.loading || remove.isPending,
                onSelect: deleteChat,
              }]}
            />
          )}
          {canMaximize && (
            <Tooltip content={full ? t('Back to the Page') : t('Full Size')} side="bottom">
              <IconButton icon={full ? 'minimize' : 'maximize'} label={full ? t('Back to the Page') : t('Full Size')} title={undefined} onClick={() => (full ? panel.minimize() : panel.maximize())} />
            </Tooltip>
          )}
          {!full && (
            <Tooltip content={t('Close')} shortcut="mod+j" side="bottom">
              <IconButton icon="close" label={t('Close')} title={undefined} onClick={() => panel.close()} />
            </Tooltip>
          )}
        </header>
        {listing ? <History /> : <>
          {ready && empty && !column && <History />}
          <div
            ref={scroller}
            onScroll={(e) => {
              const el = e.currentTarget
              pinned.current = el.scrollHeight - el.scrollTop - el.clientHeight < 48
            }}
            className={cn('cms-assistant-scroll min-h-0 overflow-y-auto overscroll-contain', ready && empty && !column ? 'shrink-0' : 'flex-1')}
          >
            {status.isPending || chat.loading ? (
              <div className="grid h-full place-items-center text-faint"><Spinner /></div>
            ) : status.isError ? (
              <div role="alert" className="cms-assistant-width flex min-h-full flex-col items-center justify-center gap-3 text-sm text-muted">
                <p>{t('Could not connect to the assistant.')}</p>
                <Button onClick={() => void status.refetch()}>{t('Try Again')}</Button>
              </div>
            ) : !ready ? (
              <Setup />
            ) : chat.items.length === 0 ? (
              <Welcome onPick={(text, send) => (send ? chat.send(text) : (setDraft(text), setFocus((f) => f + 1)))} />
            ) : (
              <div className="cms-assistant-thread cms-assistant-width space-y-6 py-5">
                {chat.items.map((item, i) => (
                  <Message
                    key={`${conversation}:${i}`}
                    item={item}
                    {...surfaceProps}
                    editing={editing === i}
                    onEditingChange={(value) => {
                      if (panel.get().conversation === conversation) setEditing((active) => value ? i : active === i ? null : active)
                    }}
                    editingDisabled={chat.busy || chat.loading || remove.isPending}
                    onEdit={chat.edit}
                  />
                ))}
                {chat.busy && <Working items={chat.items} />}
              </div>
            )}
          </div>

          {chat.error && (
            <div className="cms-assistant-width w-full shrink-0 pb-2">
              <div role="alert" className="flex items-start gap-2 rounded-md border border-danger/30 bg-danger/6 px-3 py-2 text-sm text-text">
                <Icon name="warning" size={15} className="mt-0.5 shrink-0 text-danger" />
                <span className="flex-1">{chat.error}</span>
                <IconButton icon="close" label={t('Dismiss')} size="xs" onClick={chat.dismissError} />
              </div>
            </div>
          )}

          {chat.awaiting.length > 0 && editing === null && <Approval calls={chat.awaiting} onDecide={chat.decide} />}

          {ready && (
            <div className="cms-assistant-footer cms-assistant-width w-full shrink-0">
              <Composer placeholder={chat.awaiting.length ? t('Or tell {brand} what to do instead…') : t('Ask anything. @ for context, / for skills')} focus={focus} onSubmit={submit}>
                <div className="flex items-center gap-2 px-2 pb-2">
                  <div className="flex min-w-0 flex-1">
                    {status.data && !chat.loading && <ModelPicker models={status.data.models} value={chat.model} onChange={(id) => { panel.model(id); rememberModel(id) }} />}
                  </div>
                  {chat.busy ? (
                    <IconButton icon="stop" label={t('Stop')} variant="secondary" onClick={chat.stop} />
                  ) : (
                    <IconButton icon="arrow-up" label={t('Send')} variant="primary" onClick={submit} disabled={!sendable || chat.loading || remove.isPending || editing !== null} />
                  )}
                </div>
              </Composer>
            </div>
          )}
        </>}
      </section>
    </div>
  )
}

/** Which model the next turn runs on. A change applies from the next message and sticks to the chat. */
function ModelPicker({ models, value, onChange }: { models: Model[]; value?: string; onChange: (id: string) => void }) {
  const current = models.find((m) => m.id === value)
  if (!current) return null
  return (
    <Menu
      side="top"
      align="start"
      className="w-72"
      trigger={
        <button
          type="button"
          aria-label={t('Model: {name}', { name: current.name })}
          className="flex h-7 min-w-0 items-center gap-1 rounded-md pl-2 pr-1.5 text-xs text-muted outline-offset-[-2px] transition-colors hover:bg-hover hover:text-text data-[popup-open]:bg-hover data-[popup-open]:text-text"
        >
          <span className="truncate">{current.name}</span>
          <Icon name="chevron-down" size={12} className="shrink-0 text-faint" />
        </button>
      }
      items={[
        { label: t('Model'), heading: true },
        ...models.map((m) => ({ label: m.name, description: m.description, checked: m.id === current.id, onSelect: () => m.id !== current.id && onChange(m.id) })),
      ]}
    />
  )
}

function Setup() {
  const { sidebar } = useManifest()
  const pinned = !sidebar.hidden.includes('chat')
  return (
    <div className="cms-assistant-width flex min-h-full flex-col items-center justify-center py-8 text-center">
      <div className="mb-4 grid size-10 place-items-center rounded-lg border border-border bg-surface text-muted"><Icon name="key" size={18} /></div>
      <h2 className="m-0 text-base font-semibold text-ink">{t('Connect Claude')}</h2>
      <p className="m-0 mt-1 text-sm text-muted">{t('The assistant needs an Anthropic API key. It is stored encrypted on this server.')}</p>
      {can('settings.manage') ? (
        <Link to="/settings/assistant" className="mt-5 no-underline"><Button variant="primary">{t('Open Assistant Settings')}</Button></Link>
      ) : (
        <p className="m-0 mt-4 text-sm text-faint">{t('Ask an administrator to add one.')}</p>
      )}
      {/* AI is optional: everything in the CMS works without it. One click puts it out of the way, and More keeps it close. */}
      {pinned && <p className="m-0 pt-8 text-xs text-faint">
        {t('Not using AI? {brand} works fully without it.')}{' '}
        <button type="button" className="text-muted underline decoration-border-strong underline-offset-2 hover:text-text" onClick={() => { events.emit('sidebar.pin', { id: 'chat', pinned: false }); panel.close() }}>
          {t('Unpin New Chat')}
        </button>
      </p>}
    </div>
  )
}

function Welcome({ onPick }: { onPick: (text: string, send: boolean) => void }) {
  const state = usePanel()
  const screen = useScreen()
  const items = attachments(screen, state)
  const page = items.some((item) => item.id === 'page')
  const block = items.some((item) => item.kind === 'block')
  const ideas: { label: string; text: string; send: boolean }[] = [
    ...(block ? [
      { label: t('Rewrite This Block'), text: t('Rewrite the selected block so it reads better. Keep what it says and show me what you changed.'), send: true },
    ] : []),
    ...(page && screen.kind === 'entry' ? [
      { label: t('Tighten This Page'), text: t('Proofread this page: fix mistakes, tighten the copy and improve the SEO description. Show me what you changed.'), send: true },
    ] : page ? [
      { label: t('Explain This Screen'), text: t('What am I looking at here, and what stands out?'), send: true },
    ] : []),
    { label: t('Build a Landing Page'), text: t('Build a landing page for '), send: false },
    { label: t('Draft a Blog Post'), text: t('Draft a blog post about '), send: false },
    { label: t('Audit SEO'), text: t('Check every published page for SEO and accessibility problems and list the most important fixes. Do not change anything yet.'), send: true },
    { label: t('Adjust the Design'), text: t('Change the design system so the site feels '), send: false },
  ]

  return (
    <div className="cms-assistant-welcome cms-assistant-width flex min-h-full flex-col justify-end pb-3 pt-2">
      <h2 className="cms-assistant-greeting m-0 font-semibold tracking-[-0.02em] text-ink">{t('What should we work on?')}</h2>
      <div className="mt-3 flex flex-wrap gap-1.5">
        {ideas.slice(0, 5).map((idea) => (
          <button
            key={idea.label}
            type="button"
            onClick={() => onPick(idea.text, idea.send)}
            className="min-h-7 rounded-full border border-border px-2.5 py-1 text-xs text-muted transition-colors hover:border-border-strong hover:bg-hover hover:text-text"
          >
            {idea.label}
          </button>
        ))}
      </div>
    </div>
  )
}

interface SurfaceHostProps {
  disabled: boolean
  onAction: (tool: string, action: SurfaceAction) => void
  surfaces: Map<string, string>
}

interface MessageProps extends SurfaceHostProps {
  item: Item
  editing: boolean
  onEditingChange: (value: boolean) => void
  editingDisabled: boolean
  onEdit: (item: Extract<Item, { role: 'user' }>, text: string, onAccepted?: () => void) => Promise<boolean>
}

function Message({ item, editing, onEditingChange, editingDisabled, onEdit, ...host }: MessageProps) {
  const [draft, setDraft] = useState('')
  const editButton = useRef<HTMLButtonElement>(null)
  const cancel = () => { onEditingChange(false); requestAnimationFrame(() => editButton.current?.focus()) }
  if (item.role === 'user') {
    const save = async () => {
      if (editingDisabled || !draft.trim() || draft.trim() === item.text) return
      await onEdit(item, draft, cancel)
    }
    return (
      <div className="cms-assistant-message flex flex-col items-end gap-1">
        {item.attached && item.attached.length > 0 && !editing && <Carried items={item.attached} />}
        {editing ? (
          <form className="cms-assistant-message-editor w-full rounded-xl border border-border-strong bg-surface p-3" onSubmit={(e) => { e.preventDefault(); void save() }}>
            <Textarea autoFocus aria-label={t('Edit message')} value={draft} onChange={(e) => setDraft(e.target.value)} maxLength={20000} disabled={editingDisabled} rows={2} className="max-h-52 resize-none" onKeyDown={(e) => {
              if (e.nativeEvent.isComposing) return
              if (e.key === 'Escape') { e.preventDefault(); cancel() }
              if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); void save() }
            }} />
            <div className="mt-2 flex justify-end gap-2">
              <Button size="sm" variant="ghost" disabled={editingDisabled} onClick={cancel}>{t('Cancel')}</Button>
              <Button type="submit" size="sm" variant="primary" disabled={editingDisabled || !draft.trim() || draft.trim() === item.text}>{t('Save & Send')}</Button>
            </div>
          </form>
        ) : <div className="cms-assistant-bubble max-w-[85%] whitespace-pre-wrap break-words rounded-xl px-3.5 py-2 text-sm text-text">{item.text}</div>}
        {!editing && <div className="cms-assistant-message-actions flex items-center gap-0.5">
          <CopyMessage text={item.text} />
          {item.messageIndex !== undefined && <Tooltip content={t('Edit message')}><IconButton ref={editButton} icon="edit" label={t('Edit message')} title={undefined} disabled={editingDisabled} onClick={() => { setDraft(item.text); onEditingChange(true) }} /></Tooltip>}
        </div>}
      </div>
    )
  }

  if (item.role === 'notice') {
    return (
      <p className={cn('m-0 flex items-center gap-1.5 text-xs', item.tone === 'warning' ? 'text-warning' : 'text-faint')}>
        <Icon name={item.tone === 'warning' ? 'warning' : 'info'} size={13} /> {item.text}
      </p>
    )
  }

  return (
    <div className="cms-assistant-message space-y-2">
      {item.parts.map((part, i) => <PartView key={part.type === 'tool' ? part.id : i} part={part} {...host} />)}
      {item.parts.some((part) => part.type === 'text' && part.text.trim()) && <div className="cms-assistant-message-actions flex items-center"><CopyMessage text={item.parts.filter((part) => part.type === 'text').map((part) => part.text).join('\n\n')} /></div>}
    </div>
  )
}

/** What a message carried, each thing one click away where it has a place in the admin. */
function Carried({ items }: { items: NonNullable<Extract<Item, { role: 'user' }>['attached']> }) {
  return (
    <div className="flex max-w-[85%] flex-wrap justify-end gap-x-2 gap-y-0.5">
      {items.map((item, i) => {
        const inner = <><Icon name={item.icon ?? 'file'} size={11} className="shrink-0" /><span className="truncate">{item.label}</span></>
        const className = 'flex min-w-0 max-w-full items-center gap-1 rounded-xs text-xs text-faint no-underline'
        return item.to
          ? <Link key={i} to={item.to} className={cn(className, 'hover:text-muted')}>{inner}</Link>
          : <span key={i} className={className}>{inner}</span>
      })}
    </div>
  )
}

function CopyMessage({ text }: { text: string }) {
  const [copied, setCopied] = useState(false)
  useEffect(() => {
    if (!copied) return
    const timer = window.setTimeout(() => setCopied(false), 2000)
    return () => window.clearTimeout(timer)
  }, [copied])
  const label = copied ? t('Copied') : t('Copy message')
  return <>
    <Tooltip content={label}><IconButton icon={copied ? 'check' : 'copy'} label={label} title={undefined} onClick={async () => {
      try {
        await navigator.clipboard.writeText(text)
        setCopied(true)
      } catch {
        toast.error(t('Could not copy the message. Try again.'))
      }
    }} /></Tooltip>
    <span role="status" className="sr-only">{copied ? t('Message copied') : ''}</span>
  </>
}

function PartView({ part, disabled, onAction, surfaces }: { part: Part } & SurfaceHostProps) {
  if (part.type === 'text') return <Markdown text={part.text} />
  if (part.type === 'thinking') return <Thinking text={part.text} />
  if (part.surface && part.status === 'ok') {
    if (surfaces.get(part.surface.id) !== part.id) return null
    return <Surface document={part.surface} disabled={disabled || part.used} onAction={(action) => onAction(part.id, action)} />
  }
  return <ToolRow part={part} />
}

function Thinking({ text }: { text: string }) {
  const [open, setOpen] = useState(false)
  return (
    <div>
      <button type="button" onClick={() => setOpen(!open)} className="flex items-center gap-1 text-xs text-faint hover:text-muted" aria-expanded={open}>
        <Icon name={open ? 'chevron-down' : 'chevron-right'} size={11} /> {t('Thought Process')}
      </button>
      {open && <p className="m-0 mt-1.5 whitespace-pre-wrap border-l border-border pl-3 text-xs leading-relaxed text-muted">{text}</p>}
    </div>
  )
}

const statusIcon = { ok: 'check', error: 'x-circle', declined: 'minus', awaiting: 'pause' } as const

function ToolRow({ part }: { part: Extract<Part, { type: 'tool' }> }) {
  const [open, setOpen] = useState(false)
  const hint = detail(part.input)

  return (
    <div className="rounded-sm border border-border">
      <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="flex h-8 w-full items-center gap-2 px-2.5 text-left text-xs hover:bg-hover">
        {part.status === 'running' ? (
          <Spinner size={12} />
        ) : (
          <Icon name={statusIcon[part.status]} size={13} className={cn(part.status === 'ok' ? 'text-success' : part.status === 'error' ? 'text-danger' : 'text-faint')} />
        )}
        <span className={cn('shrink-0 font-medium', part.status === 'declined' ? 'text-faint line-through' : 'text-text')}>{part.label}</span>
        {hint && <span className="min-w-0 flex-1 truncate text-faint">{hint}</span>}
        <Icon name={open ? 'chevron-down' : 'chevron-right'} size={11} className="ml-auto shrink-0 text-faint" />
      </button>
      {open && (
        <div className="space-y-2 border-t border-border bg-surface px-2.5 py-2">
          {part.input !== undefined && <Code label={t('Input')} value={JSON.stringify(part.input, null, 2)} />}
          {part.output && <Code label={part.status === 'error' ? t('Error') : t('Result')} value={pretty(part.output)} />}
        </div>
      )}
    </div>
  )
}

function Code({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="cms-caps mb-1">{label}</div>
      <pre className="m-0 max-h-56 overflow-auto whitespace-pre-wrap break-all font-mono text-2xs leading-relaxed text-muted">{value}</pre>
    </div>
  )
}

function Approval({ calls, onDecide }: { calls: Call[]; onDecide: (ids: string[]) => void }) {
  const danger = calls.some((c) => c.destructive)
  return (
    <div className="cms-assistant-width w-full shrink-0 pb-2">
      <div className="max-h-[40dvh] overflow-y-auto rounded-xl border border-border-strong bg-surface p-3 shadow-sm">
      <p className="m-0 flex items-center gap-1.5 text-sm font-medium text-ink">
        <Icon name="shield" size={14} /> {calls.length === 1 ? t('Allow this action?') : t('Allow these {count} actions?', { count: calls.length })}
      </p>
      {calls.some((c) => c.untrusted) && (
        <p className="m-0 mt-1 text-xs text-muted">{t('{brand} has read site content in this chat, which others may have written. Allow only what you asked for.')}</p>
      )}
      <ul className="m-0 mt-2 list-none space-y-1 p-0">
        {calls.map((c) => (
          <li key={c.id} className="flex items-baseline gap-1.5 text-sm">
            <Icon name={c.destructive ? 'trash' : 'edit'} size={12} className={c.destructive ? 'text-danger' : 'text-muted'} />
            <span className="text-text">{c.label}</span>
            {detail(c.input) && <span className="min-w-0 truncate text-xs text-faint">{detail(c.input)}</span>}
          </li>
        ))}
      </ul>
      <div className="mt-3 flex justify-end gap-1.5">
        <Button size="sm" variant="ghost" onClick={() => onDecide([])}>{t('Decline')}</Button>
        <Button size="sm" variant={danger ? 'danger' : 'primary'} onClick={() => onDecide(calls.map((c) => c.id))} autoFocus>
          {calls.length === 1 ? t('Allow') : t('Allow All')}
        </Button>
      </div>
      </div>
    </div>
  )
}

function Working({ items }: { items: Item[] }) {
  const last = items[items.length - 1]
  const tail = last?.role === 'assistant' ? last.parts[last.parts.length - 1] : undefined
  if (tail?.type === 'text' || (tail?.type === 'tool' && tail.status === 'running')) return null
  return (
    <p className="m-0 flex items-center gap-2 text-xs text-faint">
      <Spinner size={12} /> {t('Working…')}
    </p>
  )
}

/** The most telling field of a tool input, shown next to its label. */
function detail(input: unknown): string {
  if (!input || typeof input !== 'object') return ''
  const i = input as Record<string, unknown>
  for (const key of ['title', 'name', 'query', 'q', 'uri', 'collection', 'handle', 'to', 'id']) {
    if (typeof i[key] === 'string' && i[key]) return String(i[key]).slice(0, 80)
  }
  return ''
}

function pretty(text: string): string {
  try {
    return JSON.stringify(JSON.parse(text), null, 2)
  } catch {
    return text
  }
}
