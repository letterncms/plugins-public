import { t } from '@letterncms/sdk'
import { Button } from '@letterncms/ui'

/** New Redirect in the page's top bar asks the Redirects tab for a row. */
export const adding = new EventTarget()

/** The Redirects tab's action, in the page's top bar like every screen's primary action. */
export function NewRedirect() {
  return <Button variant="primary" icon="plus" onClick={() => adding.dispatchEvent(new Event('add'))}>{t('New Redirect')}</Button>
}
