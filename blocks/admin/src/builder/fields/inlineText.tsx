import { type FieldProps } from '@letterncms/sdk'
import { fieldControlId, cn } from '@letterncms/ui'
import { useEffect, useRef } from 'react'
import { inputClass } from '../controls'

/**
 * `blocks-inline-text` — a label or caption (inline rich text) as one line, like any other
 * input. Bold, italic and links set on the canvas are kept; ⌘B and ⌘I work here too.
 */
export function InlineTextField({ field, value, onChange, disabled }: FieldProps<string | null>) {
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (ref.current && ref.current.innerHTML !== (value ?? '') && document.activeElement !== ref.current) ref.current.innerHTML = value ?? ''
  }, [value])

  return (
    <div
      ref={ref}
      id={fieldControlId(field)}
      role="textbox"
      aria-label={field.label}
      aria-multiline={false}
      contentEditable={!disabled}
      suppressContentEditableWarning
      data-placeholder={field.placeholder ?? ''}
      onInput={() => onChange(ref.current?.innerHTML ?? '')}
      onKeyDown={(e) => e.key === 'Enter' && e.preventDefault()}
      onPaste={(e) => {
        e.preventDefault()
        document.execCommand('insertText', false, e.clipboardData.getData('text/plain').replace(/\s*\n\s*/g, ' '))
      }}
      className={cn(inputClass, 'cursor-text overflow-hidden whitespace-nowrap leading-[26px] outline-none [&_*]:inline empty:before:text-ghost empty:before:content-[attr(data-placeholder)]')}
    />
  )
}
