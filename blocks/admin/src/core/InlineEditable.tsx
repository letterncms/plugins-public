import { createElement, useLayoutEffect, useRef, type KeyboardEvent } from 'react'
import { clean, inlineMarkdown, place } from './caret'
import type { FocusRequest } from './types'

export interface InlineEditableProps {
  html: string
  /** The new HTML; `el` is the element, for reading the caret. */
  onChange: (html: string, el: HTMLElement) => void
  tag?: string
  placeholder?: string
  className?: string
  focus?: FocusRequest | null
  onKeyDown?: (e: KeyboardEvent<HTMLElement>, el: HTMLElement) => void
  onFocus?: () => void
  onPaste?: (text: string, el: HTMLElement) => boolean
  disabled?: boolean
  singleLine?: boolean
  /** Show the placeholder only while focused (Notion's empty paragraphs). */
  quiet?: boolean
  /** A scalar component property: keep its literal text rather than storing inline HTML. */
  plainText?: boolean
}

/**
 * A contenteditable for inline HTML. Uncontrolled while focused (the caret never
 * jumps); external changes are applied when the element is not being edited.
 */
export function InlineEditable({ html, onChange, tag = 'div', placeholder, className = '', focus, onKeyDown, onFocus, onPaste, disabled, singleLine, quiet, plainText }: InlineEditableProps) {
  const ref = useRef<HTMLElement | null>(null)
  const last = useRef(html)

  // Apply external changes, but never while the person is typing in this element.
  useLayoutEffect(() => {
    const el = ref.current
    if (!el) return
    if (el.ownerDocument.activeElement === el) {
      last.current = html
      return
    }
    if (clean(el.innerHTML) !== html) el.innerHTML = html
    // What it reads back as, which isn't always what's stored (&nbsp;, <br />): only leaving it changed is an edit.
    last.current = clean(el.innerHTML)
  }, [html])

  // Before the next keystroke is handled: a block that just turned into another keeps the caret.
  useLayoutEffect(() => {
    if (focus && ref.current) place(ref.current, focus.at)
  }, [focus?.nonce])

  const emit = () => {
    const el = ref.current
    if (!el) return
    const next = clean(el.innerHTML)
    // Blur re-reads the text; only real changes count (an undo must not be undone by its own blur).
    if (next === last.current) return
    last.current = next
    onChange(next, el)
  }

  return createElement(tag, {
    ref,
    className: `cms-block-text ${className}`,
    contentEditable: !disabled,
    suppressContentEditableWarning: true,
    role: 'textbox',
    'aria-multiline': !singleLine,
    'aria-readonly': disabled || undefined,
    'data-placeholder': placeholder,
    'data-quiet': quiet || undefined,
    'data-cms-inline': '',
    'data-cms-plain': plainText || undefined,
    spellCheck: true,
    onInput: (e: React.FormEvent<HTMLElement>) => {
      const input = e.nativeEvent as InputEvent
      if (!plainText && input.inputType === 'insertText' && input.data && /[*_~`]/.test(input.data)) inlineMarkdown(e.currentTarget.ownerDocument)
      emit()
    },
    onFocus: () => onFocus?.(),
    onKeyDown: (e: KeyboardEvent<HTMLElement>) => {
      const doc = e.currentTarget.ownerDocument
      const mod = e.metaKey || e.ctrlKey
      if (plainText && mod && ['b', 'i', 'u', 'x', 'k', 'e'].includes(e.key.toLowerCase())) { e.preventDefault(); return }
      if (mod && !e.shiftKey && ['b', 'i', 'u'].includes(e.key.toLowerCase())) {
        e.preventDefault()
        doc.execCommand({ b: 'bold', i: 'italic', u: 'underline' }[e.key.toLowerCase()] as string)
        emit()
        return
      }
      if (mod && e.shiftKey && e.key.toLowerCase() === 'x') {
        e.preventDefault()
        doc.execCommand('strikeThrough')
        emit()
        return
      }
      if (mod && e.key.toLowerCase() === 'k') {
        // A link here, not the command palette.
        e.preventDefault()
        e.stopPropagation()
        ref.current?.ownerDocument.dispatchEvent(new CustomEvent('cms:link'))
        return
      }
      if (mod && e.key.toLowerCase() === 'e') {
        e.preventDefault()
        wrapSelection('code', doc)
        emit()
        return
      }
      if (e.key === 'Enter' && e.shiftKey && !singleLine) {
        e.preventDefault()
        doc.execCommand('insertLineBreak')
        emit()
        return
      }
      if (ref.current) onKeyDown?.(e, ref.current)
    },
    onPaste: (e: React.ClipboardEvent<HTMLElement>) => {
      e.preventDefault()
      const doc = e.currentTarget.ownerDocument
      const text = e.clipboardData.getData('text/plain')
      // A link pasted over selected words links them.
      if (!plainText && /^(https?:\/\/|mailto:)\S+$/i.test(text.trim()) && !doc.getSelection()?.isCollapsed) {
        doc.execCommand('createLink', false, text.trim())
        emit()
        return
      }
      if (ref.current && onPaste?.(text, ref.current)) return
      doc.execCommand('insertText', false, singleLine ? text.replace(/\s*\n\s*/g, ' ') : text)
      emit()
    },
    onBlur: () => emit(),
  })
}

/** Wrap (or unwrap) the selection in an inline tag such as <code> or <mark>. */
export function wrapSelection(tag: 'code' | 'mark', doc: Document = document) {
  const sel = doc.getSelection()
  if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return
  const range = sel.getRangeAt(0)
  const ancestor = (range.commonAncestorContainer as HTMLElement).closest?.(tag) ?? range.commonAncestorContainer.parentElement?.closest(tag)
  if (ancestor) {
    ancestor.replaceWith(...Array.from(ancestor.childNodes))
    return
  }
  const wrapper = doc.createElement(tag)
  wrapper.appendChild(range.extractContents())
  range.insertNode(wrapper)
  sel.removeAllRanges()
  const r = doc.createRange()
  r.selectNodeContents(wrapper)
  sel.addRange(r)
}
