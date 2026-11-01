import { boot, CmsError, locale, t, xsrfToken, type RecordTimestamps } from '@letterncms/sdk'

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

/** A file size in the admin's language (“12 kB”, “1,4 Mo”). */
export function formatBytes(bytes: number): string {
  const [unit, value, digits] = bytes < 1024 ? ['byte', bytes, 0] : bytes < 1024 ** 2 ? ['kilobyte', bytes / 1024, 0] : ['megabyte', bytes / 1024 ** 2, 1]
  return new Intl.NumberFormat(locale(), { style: 'unit', unit: unit as string, unitDisplay: 'short', maximumFractionDigits: digits as number }).format(value as number)
}
