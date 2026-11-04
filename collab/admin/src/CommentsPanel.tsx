import { can, invalidate, locale, run, t, timeAgo, useAbility, type Entry } from '@letterncms/sdk'
import { Avatar, Button, Fields, Icon, IconButton, Menu, Spinner, confirm, toast, cn } from '@letterncms/ui'
import { Fragment, useState, type ReactNode } from 'react'

export interface Person {
  id: string
  name: string
  initials: string
  avatar: string | null
}

export interface CommentData {
  id: string
  body: string
  author: Person | null
  mentions: Person[]
  resolved: boolean
  mine: boolean
  createdAt: string
  editedAt: string | null
  blockId: string | null
  replies: CommentData[]
}

/** Entry sidebar: comment threads with replies, @mentions and resolving. */
export function CommentsPanel({ entry }: { entry: Entry | null }) {
  const entryId = entry?.id ?? ''
  const threads = useAbility<CommentData[]>('comments/list', { entryId }, { enabled: !!entry, refetchInterval: 30_000 })
  const writable = can('comments.create')
  const people = useAbility<Person[]>('comments/people', { entryId }, { enabled: !!entry && writable, staleTime: 300_000 })
  const [showResolved, setShowResolved] = useState(false)

  if (!entry) return null

  const all = threads.data ?? []
  const open = all.filter((thread) => !thread.resolved)
  const resolved = all.filter((thread) => thread.resolved)
  const refresh = () => invalidate('comments/')

  return (
    <div className="flex h-full flex-col">
      <div className="min-h-0 flex-1 space-y-2 overflow-y-auto px-4 py-3">
        {threads.isLoading ? (
          <div className="grid place-items-center py-10 text-faint"><Spinner /></div>
        ) : open.length === 0 && resolved.length === 0 ? (
          <p className="m-0 py-0.5 text-sm text-muted">{t('No comments yet.')}</p>
        ) : (
          <>
            {open.map((thread) => <Thread key={thread.id} thread={thread} entryId={entryId} people={people.data ?? []} onChange={refresh} writable={writable} />)}
            {resolved.length > 0 && (
              <button type="button" onClick={() => setShowResolved(!showResolved)} className="flex h-7 items-center gap-1.5 rounded-sm px-1.5 text-xs text-muted hover:bg-hover hover:text-ink" aria-expanded={showResolved}>
                <Icon name="chevron-down" size={12} className={cn('transition-transform duration-120', !showResolved && '-rotate-90')} /> {t('{count} resolved', { count: resolved.length })}
              </button>
            )}
            {showResolved && resolved.map((thread) => <Thread key={thread.id} thread={thread} entryId={entryId} people={people.data ?? []} onChange={refresh} writable={writable} />)}
          </>
        )}
      </div>
      {writable && (
        <div className="shrink-0 px-4 pb-4">
          <Composer entryId={entryId} people={people.data ?? []} onPosted={refresh} placeholder={t('Add a comment…')} />
        </div>
      )}
    </div>
  )
}

function Thread({ thread, entryId, people, onChange, writable }: { thread: CommentData; entryId: string; people: Person[]; onChange: () => void; writable: boolean }) {
  const [replying, setReplying] = useState(false)

  const resolve = async () => {
    try {
      await run('comments/resolve', { id: thread.id, resolved: !thread.resolved })
      onChange()
    } catch (e) {
      toast.error(e)
    }
  }

  return (
    <article className={cn('rounded-lg bg-bg shadow-[0_0_0_1px_var(--cms-border)]', thread.resolved && 'opacity-70')}>
      <div className="space-y-3 px-3 pb-3 pt-2.5">
        <CommentView comment={thread} onChange={onChange} actions={writable && (
          <IconButton icon={thread.resolved ? 'refresh' : 'check'} label={thread.resolved ? t('Reopen') : t('Resolve')} size="xs" onClick={resolve} />
        )} />
        {thread.replies.map((r) => <CommentView key={r.id} comment={r} onChange={onChange} />)}
      </div>
      {writable && (
        <div className="border-t border-border px-3 py-2">
          {replying ? (
            <Composer entryId={entryId} parentId={thread.id} people={people} autoFocus placeholder={t('Reply…')} onPosted={() => { setReplying(false); onChange() }} onCancel={() => setReplying(false)} />
          ) : (
            <button type="button" onClick={() => setReplying(true)} className="-mx-1.5 flex h-6 items-center rounded-sm px-1.5 text-xs text-muted hover:bg-hover hover:text-ink">{t('Reply')}</button>
          )}
        </div>
      )}
    </article>
  )
}

function CommentView({ comment, onChange, actions }: { comment: CommentData; onChange: () => void; actions?: ReactNode }) {
  const [editing, setEditing] = useState(false)
  const [body, setBody] = useState(comment.body)
  const moderator = can('comments.moderate')

  const saveEdit = async () => {
    try {
      await run('comments/update', { id: comment.id, body })
      setEditing(false)
      onChange()
    } catch (e) {
      toast.error(e)
    }
  }

  const remove = async () => {
    if (!(await confirm({ title: t('Delete this comment?'), description: comment.replies.length ? t('Its replies are deleted too.') : undefined, confirm: t('Delete'), danger: true }))) return
    try {
      await run('comments/delete', { id: comment.id })
      onChange()
    } catch (e) {
      toast.error(e)
    }
  }

  return (
    <div className="group">
      <div className="flex items-center gap-2">
        {comment.author && <Avatar name={comment.author.name} initials={comment.author.initials} src={comment.author.avatar} size={20} />}
        <span className="min-w-0 truncate text-xs font-medium text-text">{comment.author?.name ?? t('Deleted user')}</span>
        <time className="shrink-0 text-xs text-faint" dateTime={comment.createdAt} title={new Date(comment.createdAt).toLocaleString(locale(), { dateStyle: 'medium', timeStyle: 'short' })}>
          {comment.editedAt ? t('{time} · edited', { time: timeAgo(comment.createdAt) }) : timeAgo(comment.createdAt)}
        </time>
        <div className="ml-auto flex items-center opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100">
          {actions}
          {(comment.mine || moderator) && (
            <Menu
              trigger={<IconButton icon="more" label={t('Comment Options')} size="xs" />}
              items={[
                comment.mine && { label: t('Edit'), icon: 'edit', onSelect: () => setEditing(true) },
                { label: t('Delete'), icon: 'trash', danger: true, onSelect: remove },
              ]}
            />
          )}
        </div>
      </div>
      {editing ? (
        <div className="mt-2 space-y-2">
          <Fields bare fields={[{ handle: 'body', type: 'textarea', label: t('Comment'), autofocus: true, rows: 2 }]} values={{ body }} onChange={(_, v) => setBody(v ?? '')} />
          <div className="flex justify-end gap-1.5">
            <Button size="xs" variant="ghost" onClick={() => { setEditing(false); setBody(comment.body) }}>{t('Cancel')}</Button>
            <Button size="xs" variant="primary" disabled={!body.trim()} onClick={saveEdit}>{t('Save')}</Button>
          </div>
        </div>
      ) : (
        <p className="m-0 mt-1.5 whitespace-pre-wrap break-words pl-7 text-sm leading-relaxed text-text">{highlight(comment.body, comment.mentions)}</p>
      )}
    </div>
  )
}

/** Shows "@Name" for mentioned people as a token. */
function highlight(body: string, mentions: Person[]): ReactNode {
  if (!mentions.length) return body
  const names = mentions.map((m) => m.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).sort((a, b) => b.length - a.length)
  const parts = body.split(new RegExp(`(@(?:${names.join('|')}))`, 'g'))
  return parts.map((part, i) => (i % 2 === 1 ? <span key={i} className="rounded-xs bg-active px-0.5 font-medium text-ink">{part}</span> : <Fragment key={i}>{part}</Fragment>))
}

function Composer({ entryId, parentId, people, onPosted, onCancel, placeholder, autoFocus }: { entryId: string; parentId?: string; people: Person[]; onPosted: () => void; onCancel?: () => void; placeholder: string; autoFocus?: boolean }) {
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = async () => {
    if (!body.trim() || busy) return
    setBusy(true)
    try {
      const mentions = people.filter((p) => body.includes(`@${p.name}`)).map((p) => p.id)
      await run('comments/create', { entryId, parentId, body, mentions })
      setBody('')
      onPosted()
    } catch (e) {
      toast.error(e)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form
      onSubmit={(e) => { e.preventDefault(); void submit() }}
      onKeyDown={(e) => e.key === 'Escape' && !e.defaultPrevented && onCancel?.()}
    >
      <Fields
        bare
        fields={[{
          handle: 'body', type: 'textarea', component: 'collab-composer', label: placeholder, placeholder, autofocus: autoFocus, people,
          trailing: (
            <>
              {onCancel && <IconButton icon="close" label={t('Cancel')} size="sm" onClick={onCancel} />}
              {/* Quiet until there is something to send. */}
              <IconButton icon="arrow-up" label={parentId ? t('Reply') : t('Comment')} variant={body.trim() ? 'primary' : 'ghost'} size="sm" type="submit" disabled={!body.trim()} loading={busy} />
            </>
          ),
        }]}
        values={{ body }}
        onChange={(_, v) => setBody(v)}
      />
    </form>
  )
}
