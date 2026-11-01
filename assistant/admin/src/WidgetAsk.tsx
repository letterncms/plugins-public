import { can, navigate, t, timeAgo, useAbility } from '@letterncms/sdk'
import { Button, Fields, Kbd, Widget, WidgetRow } from '@letterncms/ui'
import { useState, type FormEvent } from 'react'
import { panel } from './store'

interface Chat {
  id: string
  title: string
  updatedAt: string | null
}

/** Home-screen widget: ask the assistant from here, or pick up one of your recent chats. */
export default function WidgetAsk() {
  const status = useAbility<{ configured: boolean }>('assistant/status', {}, { staleTime: 60_000 })
  const ready = status.data?.configured === true
  const chats = useAbility<Chat[]>('assistant/conversations', { limit: 4 }, { enabled: ready })
  const [question, setQuestion] = useState('')

  const ask = (e: FormEvent) => {
    e.preventDefault()
    const prompt = question.trim()
    if (!prompt) return
    panel.ask(prompt)
    setQuestion('')
  }

  return (
    <Widget
      query={status}
      action={ready && <Kbd keys="mod+j" />}
      empty={
        status.data?.configured === false && {
          icon: 'key',
          title: t('Connect Claude'),
          description: can('settings.manage') ? t('The assistant needs an Anthropic API key.') : t('Ask an administrator to add an Anthropic API key.'),
          action: can('settings.manage') && <Button size="sm" onClick={() => navigate('/settings/assistant')}>{t('Open Settings')}</Button>,
        }
      }
    >
      <form onSubmit={ask}>
        <Fields bare fields={[{ handle: 'question', type: 'text', label: t('Ask {brand}'), placeholder: t('Ask anything about your site'), icon: 'sparkle', max: 2000 }]} values={{ question }} onChange={(_, value) => setQuestion(value ?? '')} />
      </form>
      {chats.data && chats.data.length > 0 && (
        <div className="mt-3">
          <p className="m-0 mb-0.5 text-xs text-muted">{t('Recent Chats')}</p>
          {chats.data.map((c) => (
            <WidgetRow key={c.id} icon="chat" title={c.title} meta={timeAgo(c.updatedAt)} onClick={() => panel.select(c.id)} />
          ))}
        </div>
      )}
    </Widget>
  )
}
