import { boot, CmsError, invalidate, locale, plural, t, xsrfToken, type RecordTimestamps } from '@letterncms/sdk'
import { toast } from '@letterncms/ui'

export interface MediaItem extends RecordTimestamps {
  id: string
  url: string
  thumb: string | null
  name: string
  filename: string
  mime: string
  kind: 'image' | 'video' | 'audio' | 'pdf' | 'file'
  size: number
  width: number | null
  height: number | null
  alt: string | null
  caption: string | null
  focal: { x: number; y: number }
  folder: string
  createdAt: string
}

/** Upload one file with progress (multipart; the server validates and sanitizes). */
export function upload(file: File, folder = '', onProgress?: (fraction: number) => void): Promise<MediaItem> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    const form = new FormData()
    form.append('file', file)
    form.append('folder', folder)
    xhr.open('POST', `${boot.api}/media/upload`)
    xhr.setRequestHeader('X-XSRF-TOKEN', xsrfToken() ?? '')
    xhr.setRequestHeader('Accept', 'application/json')
    xhr.setRequestHeader('X-Cms-Locale', locale())
    xhr.upload.onprogress = (e) => e.lengthComputable && onProgress?.(e.loaded / e.total)
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

/**
 * Upload a batch where the person is looking, whatever they scroll to: one toast for its
 * progress, then what came of it. Resolves with the files that arrived.
 */
export async function uploadAll(files: FileList | File[], folder = ''): Promise<MediaItem[]> {
  const batch = Array.from(files)
  if (!batch.length) return []
  const progress = batch.map(() => 0)
  const title = plural(batch.length, 'Uploading {count} file…', 'Uploading {count} files…')
  const id = toast.loading(title, { description: '0%' })
  const report = () => toast.loading(title, { id, description: `${Math.round((progress.reduce((a, b) => a + b, 0) / batch.length) * 100)}%` })
  const done: MediaItem[] = []
  const failed: { name: string; error: unknown }[] = []
  await Promise.all(
    batch.map(async (file, index) => {
      try {
        done.push(await upload(file, folder, (p) => { progress[index] = p; report() }))
      } catch (error) {
        failed.push({ name: file.name, error })
      }
      progress[index] = 1
    }),
  )
  invalidate('media/')
  if (done.length) toast.success(plural(done.length, '{count} file uploaded', '{count} files uploaded'), { id, description: undefined })
  else toast.dismiss(id)
  for (const { name, error } of failed) toast.error(t('Couldn’t upload {name}: {reason}', { name, reason: error instanceof Error ? error.message : String(error) }))
  return done
}

/** The folder the library on view shows: where the Media place's Upload puts files. */
export const libraryFolder = { current: '' }

/** A file size in the admin's language (“12 kB”, “1,4 Mo”). */
export function formatBytes(bytes: number): string {
  const [unit, value, digits] = bytes < 1024 ? ['byte', bytes, 0] : bytes < 1024 ** 2 ? ['kilobyte', bytes / 1024, 0] : ['megabyte', bytes / 1024 ** 2, 1]
  // Bytes in short form read “109 byte”; narrow is “109B”, like kB and MB.
  return new Intl.NumberFormat(locale(), { style: 'unit', unit: unit as string, unitDisplay: unit === 'byte' ? 'narrow' : 'short', maximumFractionDigits: digits as number }).format(value as number)
}
