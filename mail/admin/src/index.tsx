import './styles.css'
import { registry, run, t } from '@letterncms/sdk'
import { Button, toast } from '@letterncms/ui'
import { lazy, useState } from 'react'

registry.widgets.set('mail-status', lazy(() => import('./WidgetEmail')))

/** Sends a test email to the person signed in, with the settings as saved. */
async function sendTest(): Promise<void> {
  const me = await run<{ email: string }>('account/get')
  try {
    await run('mail/test', { to: me.email })
    toast.success(t('Test email sent to {email}', { email: me.email }))
  } catch (e) {
    toast.error(e)
  }
}

/** On the Email settings page: try what is saved. Changes not yet saved wait for Save first. */
function SendTest({ dirty }: { namespace: string; tab: string; dirty: boolean }) {
  const [busy, setBusy] = useState(false)
  return (
    <Button icon="mail" loading={busy} disabled={dirty} title={dirty ? t('Save your changes first') : undefined} onClick={async () => { setBusy(true); await sendTest(); setBusy(false) }}>
      {t('Send Test Email')}
    </Button>
  )
}

registry.panels.set('mail-test', { id: 'mail-test', slot: 'settings.actions', component: SendTest, when: (p: { namespace: string; tab: string }) => p.namespace === 'mail' && p.tab === 'settings' })

/** Command palette: send a test email to yourself. */
registry.commands.set('mail-test', {
  id: 'mail-test',
  label: t('Send Test Email'),
  icon: 'mail',
  group: t('Email'),
  keywords: ['smtp', 'email', 'mail', 'test'],
  perform: sendTest,
})
