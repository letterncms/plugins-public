import { can, navigate, registry, t, useAbility } from '@letterncms/sdk'
import { Button, Fields, Kbd, Widget } from '@letterncms/ui'
import { useState, type FormEvent } from 'react'

/** Home-screen widget: ask Claude from here. The answer comes in the workspace's thread, beside the page (through its New Chat command). */
export default function WidgetAsk() {
  const status = useAbility<{ configured: boolean }>('assistant/status', {}, { staleTime: 60_000 })
  const ready = status.data?.configured === true
  const [question, setQuestion] = useState('')

  const ask = (e: FormEvent) => {
    e.preventDefault()
    const prompt = question.trim()
    if (!prompt) return
    void registry.commands.get('workspace')?.perform(prompt)
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
    </Widget>
  )
}
