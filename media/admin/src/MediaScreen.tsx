import { locale, plural, t, useLocation } from '@letterncms/sdk'
import { Page, ScreenTabs, useScreenTabs } from '@letterncms/ui'
import { Library } from './Library'

export default function MediaScreen() {
  const { query } = useLocation()
  const [tab, setTab] = useScreenTabs(['library'])
  return (
    <Library
      mode="manage"
      autoUpload={query.upload === '1'}
      // Like the entry lists: the count beside the title, the main action in the top bar.
      frame={({ upload, total, body }) => (
        <Page
          width="wide"
          crumbs={[{ label: <>{t('Media')}{!!total && <span className="ml-2 font-normal tabular-nums text-faint" title={plural(total, '{count} file', '{count} files')}>{total.toLocaleString(locale())}</span>}</>, icon: 'media' }]}
          actions={tab === 'library' && upload}
        >
          <ScreenTabs value={tab} onChange={setTab} tabs={[{ value: 'library', label: t('Library') }]}>{body}</ScreenTabs>
        </Page>
      )}
    />
  )
}
