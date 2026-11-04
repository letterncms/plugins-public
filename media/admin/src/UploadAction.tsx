import { type SurfaceComponentProps } from '@letterncms/sdk'
import { useEffect, useRef } from 'react'
import { libraryFolder, uploadAll } from './upload'

/** The Media place's Upload: the file picker at once, the chosen files into the folder on view. */
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
      multiple
      className="hidden"
      onChange={(e) => {
        const files = Array.from(e.target.files ?? [])
        close?.()
        if (files.length) void uploadAll(files, libraryFolder.current)
      }}
    />
  )
}
