import type { FieldProps } from '@letterncms/sdk'
import { Textarea } from '@letterncms/ui'
import { useEffect, useRef } from 'react'

/** The chat message being written: Enter submits the surrounding form, Shift+Enter breaks the line. It takes focus whenever `focus` changes. */
export function ComposerField({ field, value, onChange, disabled }: FieldProps<string | null>) {
  const input = useRef<HTMLTextAreaElement>(null)
  useEffect(() => input.current?.focus(), [field.focus])

  return (
    <Textarea
      ref={input}
      value={value ?? ''}
      disabled={disabled}
      onChange={(e) => onChange(e.target.value)}
      onKeyDown={(e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
          e.preventDefault()
          e.currentTarget.form?.requestSubmit()
        }
      }}
      rows={2}
      placeholder={field.placeholder}
      className="max-h-52 min-h-0 resize-none border-0 bg-transparent px-3 pt-2.5 text-sm shadow-none focus:ring-0 focus-visible:outline-none"
      aria-label={field.label}
    />
  )
}
