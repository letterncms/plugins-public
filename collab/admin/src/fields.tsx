import { t, type FieldProps } from '@letterncms/sdk'
import { Avatar, Textarea, cn } from '@letterncms/ui'
import { useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import type { Person } from './CommentsPanel'

/**
 * A comment being written: `@` suggests the `people` to mention, ⌘↵ submits
 * the surrounding form. Keys it does not use (Escape) are left to the composer.
 */
export function ComposerField({ field, value, onChange, disabled }: FieldProps<string | null>) {
  const body = value ?? ''
  const people = (field.people as Person[]) ?? []
  const [query, setQuery] = useState<string | null>(null)
  const [highlighted, setHighlighted] = useState(0)
  const input = useRef<HTMLTextAreaElement>(null)

  const matches = useMemo(() => (query === null ? [] : people.filter((p) => p.name.toLowerCase().includes(query.toLowerCase())).slice(0, 6)), [query, people])

  const detect = (value: string, caret: number) => {
    const before = value.slice(0, caret)
    const m = before.match(/(?:^|\s)@([\p{L}\p{N} .'-]{0,30})$/u)
    setQuery(m && !m[1].includes('  ') ? m[1] : null)
    setHighlighted(0)
  }

  const pick = (person: Person) => {
    const el = input.current
    const caret = el?.selectionStart ?? body.length
    const before = body.slice(0, caret).replace(/@([\p{L}\p{N} .'-]{0,30})$/u, `@${person.name} `)
    onChange(before + body.slice(caret))
    setQuery(null)
    requestAnimationFrame(() => {
      el?.focus()
      el?.setSelectionRange(before.length, before.length)
    })
  }

  const onKey = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (matches.length) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault()
        setHighlighted((h) => (h + (e.key === 'ArrowDown' ? 1 : matches.length - 1)) % matches.length)
        return
      }
      if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault()
        pick(matches[highlighted])
        return
      }
      if (e.key === 'Escape') {
        e.preventDefault()
        setQuery(null)
        return
      }
    }
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
      e.preventDefault()
      e.currentTarget.form?.requestSubmit()
    }
  }

  return (
    <div className="relative">
      {matches.length > 0 && (
        <ul role="listbox" aria-label={t('Mention Someone')} className="cms-pop absolute bottom-full left-0 z-20 mb-1 w-full list-none p-1">
          {matches.map((p, i) => (
            <li key={p.id} role="option" aria-selected={i === highlighted}>
              <button
                type="button"
                onMouseDown={(e) => { e.preventDefault(); pick(p) }}
                className={cn('flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm', i === highlighted ? 'bg-hover' : 'hover:bg-hover')}
              >
                <Avatar name={p.name} initials={p.initials} src={p.avatar} size={18} />
                {p.name}
              </button>
            </li>
          ))}
        </ul>
      )}
      {/* One line that grows as you write, the send button inside it: the composer of the assistant, at the panel's size. */}
      <div className="flex items-end gap-1 rounded-lg border border-border bg-bg py-1 pl-2.5 pr-1 transition-colors focus-within:border-border-strong">
        <Textarea
          ref={input}
          value={body}
          autoFocus={field.autofocus === true}
          disabled={disabled}
          rows={1}
          appearance="plain"
          placeholder={field.placeholder}
          aria-label={field.label}
          onChange={(e) => { onChange(e.target.value); detect(e.target.value, e.target.selectionStart) }}
          onKeyDown={onKey}
          onBlur={() => setQuery(null)}
          className="max-h-40 flex-1 py-1 text-sm leading-5 placeholder:text-ghost"
        />
        {field.trailing as ReactNode}
      </div>
    </div>
  )
}
