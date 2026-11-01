import { t, timeAgo, useAbility } from '@letterncms/sdk'
import { Avatar, Icon, Tooltip, Widget, WidgetRow } from '@letterncms/ui'
import type { CommentData } from './CommentsPanel'

interface InboxItem extends CommentData {
  threadId: string
  entry: { id: string; title: string; collection: string }
  reason: 'mention' | 'yours'
}

/** Home-screen widget: open threads that mention you or sit on your entries, newest first. */
export default function WidgetComments() {
  const inbox = useAbility<InboxItem[]>('comments/inbox', { limit: 5 }, { refetchInterval: 60_000 })

  return (
    <Widget query={inbox} empty={inbox.data?.length === 0 && { icon: 'comment', title: t('Nothing waiting for you') }}>
      {inbox.data?.map((c) => {
        const name = c.author?.name ?? t('Someone')
        return (
          <WidgetRow
            key={c.threadId}
            to={`/content/${c.entry.collection}/${c.entry.id}`}
            leading={<Avatar name={name} initials={c.author?.initials} src={c.author?.avatar} size={22} />}
            title={<span className="font-medium">{c.entry.title || t('Untitled')}</span>}
            description={t('{name}: {comment}', { name, comment: c.body })}
            meta={
              <span className="flex items-center gap-2">
                {c.reason === 'mention' && (
                  <Tooltip content={t('Mentions you')}>
                    <span className="inline-flex text-muted">
                      <Icon name="at" size={12} />
                    </span>
                  </Tooltip>
                )}
                {timeAgo(c.createdAt)}
              </span>
            }
          />
        )
      })}
    </Widget>
  )
}
