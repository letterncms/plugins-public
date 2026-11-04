import './styles.css'
import { registry, run, t } from '@letterncms/sdk'
import { toast } from '@letterncms/ui'
import { lazy } from 'react'

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

/** Command palette: send a test email to yourself. */
registry.commands.set('mail-test', {
  id: 'mail-test',
  label: t('Send Test Email'),
  icon: 'mail',
  group: t('Email'),
  keywords: ['smtp', 'email', 'mail', 'test'],
  perform: sendTest,
})
