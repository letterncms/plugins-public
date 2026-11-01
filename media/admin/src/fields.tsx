import type { FieldProps } from '@letterncms/sdk'
import { Button } from '@letterncms/ui'
import { useEffect, useRef } from 'react'

/** Opens the file picker and hands the chosen files over; autofocus opens it on mount. */
export function UploadField({ field, onChange, disabled }: FieldProps<File[] | null>) {
  const input = useRef<HTMLInputElement>(null)
  useEffect(() => {
    if (field.autofocus) input.current?.click()
  }, [])

  const picker = (
    <input
      ref={input}
      type="file"
      multiple={!!field.multiple}
      accept={(field.accept as string) || undefined}
      disabled={disabled}
      className="hidden"
      onChange={(e) => {
        if (e.target.files?.length) onChange(Array.from(e.target.files))
        e.target.value = ''
      }}
    />
  )

  return (
    <>
      {picker}
      <Button size="sm" variant={field.variant === 'primary' ? 'primary' : 'secondary'} icon="upload" disabled={disabled} onClick={() => input.current?.click()}>{field.label}</Button>
    </>
  )
}
