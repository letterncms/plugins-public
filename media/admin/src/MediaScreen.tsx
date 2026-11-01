import { locale, plural, t, useLocation } from '@letterncms/sdk'
import { Page } from '@letterncms/ui'
import { Library } from './Library'

export default function MediaScreen() {
  const { query } = useLocation()
  return (
    <Library
      mode="manage"
      autoUpload={query.upload === '1'}
      // Like the entry lists: the count beside the title, the main action in the top bar.
      frame={({ upload, total, body }) => (
        <Page
          width="wide"
          crumbs={[{ label: <>{t('Media')}{total !== null && <span className="ml-0.5 font-normal tabular-nums text-faint" title={plural(total, '{count} file', '{count} files')}>{total.toLocaleString(locale())}</span>}</>, icon: 'media' }]}
          actions={upload}
        >
          {body}
        </Page>
      )}
    />
  )
}
