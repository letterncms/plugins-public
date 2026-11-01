import { registry, t } from '@letterncms/sdk'
import { Fields, Icon, Tooltip, cn } from '@letterncms/ui'
import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useAnchors } from './anchors'
import { wrapSelection } from './InlineEditable'
import { linkField } from './link'

/**
 * Floating formatting bar shown over a text selection inside any .cms-block-text
 * element in the block document.
 */
export function FormatToolbar({ root = document, offset }: { root?: Document; offset?: () => { x: number; y: number; scale: number } }) {
  const [pos, setPos] = useState<{ x: number; y: number } | null>(null)
  const [marks, setMarks] = useState<Record<string, boolean>>({})
  const [linking, setLinking] = useState(false)
  const [url, setUrl] = useState('')
  const saved = useRef<Range | null>(null)
  const editable = useRef<HTMLElement | null>(null)
  const startLinkRef = useRef(() => {})
  const anchors = useAnchors()

  useEffect(() => {
    const update = () => {
      if (linking) return
      const sel = root.getSelection()
      if (!sel || sel.isCollapsed || sel.rangeCount === 0) return setPos(null)
      const range = sel.getRangeAt(0)
      const host = (range.commonAncestorContainer.nodeType === 1 ? (range.commonAncestorContainer as HTMLElement) : range.commonAncestorContainer.parentElement)?.closest('[contenteditable="true"]') as HTMLElement | null
      // Only while writing there: a menu or panel taking focus hides it.
      if (!host || host.hasAttribute('data-cms-plain') || root.activeElement !== host) return setPos(null)
      editable.current = host
      const inside = (tag: string) => !!(range.startContainer.nodeType === 1 ? (range.startContainer as HTMLElement) : range.startContainer.parentElement)?.closest(tag)
      setMarks({ bold: root.queryCommandState('bold'), italic: root.queryCommandState('italic'), underline: inside('u'), strike: root.queryCommandState('strikeThrough'), code: inside('code'), link: inside('a') })
      const rect = range.getBoundingClientRect()
      const o = offset?.() ?? { x: 0, y: 0, scale: 1 }
      setPos({ x: o.x + (rect.left + rect.width / 2) * o.scale, y: o.y + rect.top * o.scale })
    }
    const onLink = () => {
      update()
      startLinkRef.current()
    }
    const onFocusOut = () => setTimeout(update)
    root.addEventListener('selectionchange', update)
    root.addEventListener('focusout', onFocusOut)
    root.addEventListener('cms:link', onLink)
    return () => {
      root.removeEventListener('selectionchange', update)
      root.removeEventListener('focusout', onFocusOut)
      root.removeEventListener('cms:link', onLink)
    }
  }, [root, linking, offset])

  if (!pos) return null

  const exec = (command: string) => {
    root.execCommand(command)
    editable.current?.dispatchEvent(new Event('input', { bubbles: true }))
    root.dispatchEvent(new Event('selectionchange'))
  }

  const wrap = (tag: 'code' | 'mark') => {
    wrapSelection(tag, root)
    editable.current?.dispatchEvent(new Event('input', { bubbles: true }))
    root.dispatchEvent(new Event('selectionchange'))
  }

  const startLink = () => {
    const sel = root.getSelection()
    saved.current = sel && sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null
    const anchor = (sel?.anchorNode?.parentElement as HTMLElement | null)?.closest('a')
    setUrl(anchor?.getAttribute('href') ?? '')
    setLinking(true)
  }

  startLinkRef.current = startLink
  // The link picker commits a choice (a page, a pasted URL, "Remove Link") at once.
  const picker = !!registry.fields.get('link')

  const applyLink = (to = url) => {
    const sel = root.getSelection()
    if (saved.current && sel) {
      sel.removeAllRanges()
      sel.addRange(saved.current)
    }
    const value = to.trim()
    if (value === '') root.execCommand('unlink')
    else if (/^(https?:|mailto:|tel:|\/|#|\{brand\.)/i.test(value)) root.execCommand('createLink', false, value)
    else root.execCommand('createLink', false, `https://${value}`)
    editable.current?.dispatchEvent(new Event('input', { bubbles: true }))
    setLinking(false)
    setPos(null)
  }

  const btn = (icon: string, label: string, shortcut: string, onClick: () => void) => (
    <Tooltip key={icon} content={label} shortcut={shortcut}>
      <button type="button" aria-label={label} aria-pressed={!!marks[icon] || (icon === 'link' && linking)} onMouseDown={(e) => e.preventDefault()} onClick={onClick} className={cn('grid size-7 place-items-center rounded-xs hover:bg-white/12 hover:text-ink-contrast', marks[icon] || (icon === 'link' && linking) ? 'bg-white/14 text-ink-contrast' : 'text-ink-contrast/75')}>
        <Icon name={icon} size={14} />
      </button>
    </Tooltip>
  )

  // Closing the picker without a choice goes back to the words being linked.
  const cancelLink = () => {
    setLinking(false)
    const host = editable.current
    const sel = root.getSelection()
    if (!host || !saved.current || !sel) return
    host.focus({ preventScroll: true })
    sel.removeAllRanges()
    sel.addRange(saved.current)
  }
  const linkDef = linkField({ handle: 'url', label: t('Link'), size: 'sm', autofocus: true, anchors, placeholder: t('Paste a link or search pages'), onClose: cancelLink })

  return createPortal(
    <div
      className={cn('fixed z-[70] flex -translate-x-1/2 -translate-y-full items-center gap-0.5 rounded-md bg-ink p-1 shadow-pop', linking && !picker && 'p-1.5')}
      style={{ left: pos.x, top: pos.y - 8 }}
      onMouseDown={(e) => !linking && e.preventDefault()}
      onKeyDown={(e) => e.key === 'Escape' && cancelLink()}
    >
      {linking && !picker ? (
        <form
          onSubmit={(e) => {
            e.preventDefault()
            applyLink()
          }}
          className="flex items-center gap-1"
        >
          <Fields bare className="w-64" fields={[linkDef]} values={{ url }} onChange={(_, v) => setUrl(v ?? '')} />
          <button type="submit" className="h-7 rounded-xs px-2 text-xs font-medium text-ink-contrast hover:bg-white/12">{url ? t('Apply') : t('Remove')}</button>
        </form>
      ) : (
        <>
          {btn('bold', t('Bold'), 'mod+b', () => exec('bold'))}
          {btn('italic', t('Italic'), 'mod+i', () => exec('italic'))}
          {btn('underline', t('Underline'), 'mod+u', () => exec('underline'))}
          {btn('strike', t('Strikethrough'), 'mod+shift+x', () => exec('strikeThrough'))}
          {btn('code', t('Code'), 'mod+e', () => wrap('code'))}
          <span className="mx-0.5 h-4 w-px bg-white/15" />
          {btn('link', t('Link'), 'mod+k', startLink)}
          {/* The picker opens from the bar itself: its own trigger is only the anchor. */}
          {linking && (
            <div className="pointer-events-none absolute inset-0 opacity-0" aria-hidden>
              <Fields bare className="h-full" fields={[linkDef]} values={{ url }} onChange={(_, v) => applyLink(v ?? '')} />
            </div>
          )}
        </>
      )}
    </div>,
    document.body,
  )
}
