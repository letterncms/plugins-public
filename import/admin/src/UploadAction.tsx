import { boot, CmsError, invalidate, locale, navigate, t, xsrfToken, type SurfaceComponentProps } from '@letterncms/sdk'
import { toast } from '@letterncms/ui'
import { useEffect, useRef } from 'react'

interface Source {
  id: string
  path: string
  type: 'wordpress' | 'csv' | 'markdown'
  size: number | null
}

/** One file into the import folder, with progress (multipart; the server checks what it holds). */
function upload(file: File, onProgress: (fraction: number) => void): Promise<Source> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    const form = new FormData()
    form.append('file', file)
    xhr.open('POST', `${boot.api}/import/upload`)
    xhr.setRequestHeader('X-XSRF-TOKEN', xsrfToken() ?? '')
    xhr.setRequestHeader('Accept', 'application/json')
    xhr.setRequestHeader('X-Cms-Locale', locale())
    xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(e.loaded / e.total)
    xhr.onload = () => {
      const json = (() => {
        try {
          return JSON.parse(xhr.responseText)
        } catch {
          return null
        }
      })()
      if (xhr.status >= 200 && xhr.status < 300 && json?.data) resolve(json.data)
      else reject(new CmsError(json?.error?.message ?? (xhr.status === 413 ? t('The file is larger than the server allows.') : t('Upload failed ({status})', { status: xhr.status })), json?.error?.type ?? 'failed', xhr.status))
    }
    xhr.onerror = () => reject(new CmsError(t('Network error during upload.')))
    xhr.send(form)
  })
}

/** The Import place's Upload File: the file picker at once, then the file's own page, where its preview is. */
export default function UploadAction({ close }: SurfaceComponentProps) {
  const input = useRef<HTMLInputElement>(null)
  useEffect(() => {
    const el = input.current
    if (!el) return
    const cancel = () => close?.()
    el.addEventListener('cancel', cancel)
    el.click()
    return () => el.removeEventListener('cancel', cancel)
  }, [])
  return (
    <input
      ref={input}
      type="file"
      accept=".xml,.csv,.tsv,.zip"
      className="hidden"
      onChange={async (e) => {
        const file = e.target.files?.[0]
        close?.()
        if (!file) return
        const title = t('Uploading {name}…', { name: file.name })
        const id = toast.loading(title, { description: '0%' })
        try {
          const source = await upload(file, (p) => toast.loading(title, { id, description: `${Math.round(p * 100)}%` }))
          invalidate('import')
          toast.success(t('{name} uploaded', { name: source.path }), { id, description: undefined })
          navigate(`/import/files/${source.id}`)
        } catch (error) {
          toast.dismiss(id)
          toast.error(t('Couldn’t upload {name}: {reason}', { name: file.name, reason: error instanceof Error ? error.message : String(error) }))
        }
      }}
    />
  )
}
