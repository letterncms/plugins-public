import './styles.css'
import { registry, run, t } from '@letterncms/sdk'
import { toast } from '@letterncms/ui'
import { lazy } from 'react'

registry.widgets.set('mail-status', lazy(() => import('./WidgetEmail')))

/** Command palette: send a test email to yourself. */
registry.commands.set('mail-test', {
  id: 'mail-test',
  label: t('Send a Test Email to Me'),
  icon: 'mail',
  group: t('Email'),
  keywords: ['smtp', 'email', 'mail', 'test'],
  perform: async () => {
    const me = await run<{ email: string }>('account/get')
    try {
      await run('mail/test', { to: me.email })
      toast.success(t('Test email sent to {email}', { email: me.email }))
    } catch (e) {
      toast.error(e)
    }
  },
})
