import { locale, t, xsrfToken, type Entry, type EntryContent } from '@letterncms/sdk'
import { Button, Dialog, Fields, QueryError, Spinner } from '@letterncms/ui'
import { useEffect, useState } from 'react'

/** Output from the site's own renderer. This frame has no editing bridge or second history. */
export function RenderedPreview({ entry, collection, content, onClose }: { entry: Entry | null; collection: string; content: EntryContent; onClose: () => void }) {
  const [device, setDevice] = useState('desktop')
  const [html, setHtml] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [retry, setRetry] = useState(0)
  const [loading, setLoading] = useState(true)
  useEffect(() => {
    const abort = new AbortController()
    setLoading(true)
    fetch(entry ? `/__preview/${entry.id}` : '/__preview', { method: 'POST', credentials: 'same-origin', signal: abort.signal, headers: { 'Content-Type': 'application/json', Accept: 'text/html', 'X-XSRF-TOKEN': xsrfToken() ?? '', 'X-Cms-Locale': locale() }, body: JSON.stringify({ ...content, collection, play: true }) })
      .then(async (response) => { const body = await response.text(); if (!response.ok) throw new Error(t('Preview failed ({status})', { status: response.status })); return body })
      .then((body) => { if (!abort.signal.aborted) { const nonce = document.querySelector<HTMLScriptElement>('script[nonce]')?.nonce
        setHtml(nonce ? body.replace(/<script\b(?![^>]*\bnonce=)/gi, `<script nonce="${nonce}"`) : body); setError(null) } })
      .catch((failure) => { if (!abort.signal.aborted) setError(failure instanceof Error ? failure.message : String(failure)) })
      .finally(() => { if (!abort.signal.aborted) setLoading(false) })
    return () => abort.abort()
  }, [entry?.id, collection, content, retry])
  return <Dialog open onOpenChange={(open) => !open && onClose()} title={t('Preview')} size="xl" className="!top-[3vh] !max-h-[94vh] !max-w-[calc(100vw-32px)]" footer={<Button onClick={onClose}>{t('Close')}</Button>}>
    <Fields bare layout="inline" fields={[{ handle: 'device', type: 'select', display: 'segmented', label: t('Viewport'), required: true, options: { desktop: t('Desktop'), tablet: t('Tablet'), mobile: t('Phone') } }]} values={{ device }} onChange={(_, value) => value && setDevice(value)} />
    {error ? <QueryError message={error} onRetry={() => setRetry(retry + 1)} /> : <div className="relative mt-3 flex h-[68vh] overflow-auto bg-sunken p-2">
      {loading && <div className="absolute inset-0 z-10 grid place-items-center bg-bg/70"><Spinner /></div>}
      <iframe title={t('Site preview')} srcDoc={html} sandbox="allow-same-origin allow-scripts allow-forms allow-popups" className="mx-auto h-full shrink-0 border-0 bg-white" style={{ width: device === 'mobile' ? 390 : device === 'tablet' ? 820 : '100%' }} />
    </div>}
  </Dialog>
}
