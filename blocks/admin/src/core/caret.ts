/** Caret helpers for contenteditable elements. */

export function isCollapsed(doc: Document = document): boolean {
  return doc.getSelection()?.isCollapsed ?? true
}

function rangeTo(el: HTMLElement, fromStart: boolean): Range | null {
  const sel = el.ownerDocument.getSelection()
  if (!sel || sel.rangeCount === 0) return null
  const caret = sel.getRangeAt(0)
  if (!el.contains(caret.startContainer)) return null
  const range = el.ownerDocument.createRange()
  range.selectNodeContents(el)
  if (fromStart) range.setEnd(caret.startContainer, caret.startOffset)
  else range.setStart(caret.endContainer, caret.endOffset)
  return range
}

function text(range: Range | null): string {
  return range ? range.toString().replace(/​/g, '') : ''
}

export function atStart(el: HTMLElement): boolean {
  return isCollapsed(el.ownerDocument) && text(rangeTo(el, true)) === '' && !htmlOf(rangeTo(el, true)).includes('<br')
}

export function atEnd(el: HTMLElement): boolean {
  return isCollapsed(el.ownerDocument) && text(rangeTo(el, false)) === ''
}

function htmlOf(range: Range | null): string {
  if (!range) return ''
  const div = range.startContainer.ownerDocument!.createElement('div')
  div.appendChild(range.cloneContents())
  return div.innerHTML
}

/** How many characters of text come before the caret in the element (0 when it is elsewhere). */
export function caretOffset(el: HTMLElement): number {
  return text(rangeTo(el, true)).length
}

/** Split the element's HTML at the caret: [before, after]. */
export function split(el: HTMLElement): [string, string] {
  const before = rangeTo(el, true)
  const after = rangeTo(el, false)
  return [htmlOf(before), htmlOf(after)]
}

/** Is the caret on the first/last visual line? (for arrow-key navigation between blocks) */
export function onFirstLine(el: HTMLElement): boolean {
  const rect = caretRect(el.ownerDocument)
  if (!rect) return true
  return rect.top - el.getBoundingClientRect().top < lineHeight(el) * 0.8
}

export function onLastLine(el: HTMLElement): boolean {
  const rect = caretRect(el.ownerDocument)
  if (!rect) return true
  return el.getBoundingClientRect().bottom - rect.bottom < lineHeight(el) * 0.8
}

function lineHeight(el: HTMLElement): number {
  const lh = parseFloat(el.ownerDocument.defaultView!.getComputedStyle(el).lineHeight)
  return Number.isFinite(lh) ? lh : 20
}

export function caretRect(doc: Document = document): DOMRect | null {
  const sel = doc.getSelection()
  if (!sel || sel.rangeCount === 0) return null
  const range = sel.getRangeAt(0).cloneRange()
  const rects = range.getClientRects()
  if (rects.length > 0) return rects[0]
  const span = doc.createElement('span')
  span.textContent = '​'
  range.insertNode(span)
  const rect = span.getBoundingClientRect()
  span.remove()
  return rect
}

/** Place the caret at start, end, or a text offset. */
export function place(el: HTMLElement, at: 'start' | 'end' | number) {
  el.focus({ preventScroll: true })
  const sel = el.ownerDocument.getSelection()
  if (!sel) return
  const range = el.ownerDocument.createRange()

  if (at === 'start' || at === 'end') {
    range.selectNodeContents(el)
    range.collapse(at === 'start')
  } else {
    let remaining = at
    const walker = el.ownerDocument.createTreeWalker(el, NodeFilter.SHOW_TEXT)
    let node: Node | null = walker.nextNode()
    let placed = false
    while (node) {
      const len = node.textContent?.length ?? 0
      if (remaining <= len) {
        range.setStart(node, remaining)
        range.collapse(true)
        placed = true
        break
      }
      remaining -= len
      node = walker.nextNode()
    }
    if (!placed) {
      range.selectNodeContents(el)
      range.collapse(false)
    }
  }

  sel.removeAllRanges()
  sel.addRange(range)
  el.scrollIntoView({ block: 'nearest' })
}

/** Text length of HTML (for placing the caret after a merge). */
export function textLength(html: string): number {
  const div = document.createElement('div')
  div.innerHTML = html
  return (div.textContent ?? '').length
}

/** Normalize browser-generated markup to the inline allowlist. */
export function clean(html: string): string {
  return html
    .replace(/\u200B/g, '')
    .replace(/<(\/?)strike>/g, '<$1s>')
    .replace(/<(\/?)b>/g, '<$1strong>')
    .replace(/<(\/?)i>/g, '<$1em>')
    .replace(/<div><br><\/div>/g, '<br>')
    .replace(/<\/?(div|p|span)[^>]*>/g, '')
    .replace(/ style="[^"]*"/g, '')
    .replace(/&nbsp;/g, ' ')
    .replace(/<br>$/, '')
}

/* Inline Markdown typed in the text: `**bold**`, `*italic*`, `_italic_`, `~~strike~~` and `` `code` ``
   become marks the moment the closing marker is typed (Notion's behavior). */
const MARKS: [string, string][] = [['**', 'strong'], ['__', 'strong'], ['~~', 's'], ['`', 'code'], ['*', 'em'], ['_', 'em']]

/** Turn the inline Markdown just closed at the caret into its mark; true when it did. */
export function inlineMarkdown(doc: Document = document): boolean {
  const sel = doc.getSelection()
  if (!sel || !sel.isCollapsed || sel.rangeCount === 0) return false
  const node = sel.anchorNode
  if (!node || node.nodeType !== Node.TEXT_NODE || node.parentElement?.closest('code')) return false
  const offset = sel.anchorOffset
  const before = (node.textContent ?? '').slice(0, offset)

  for (const [marker, tag] of MARKS) {
    if (!before.endsWith(marker)) continue
    const end = before.length - marker.length
    // A single * or _ that is half of ** or __ belongs to the longer marker.
    if (marker.length === 1 && before[end - 1] === marker) return false
    const open = before.lastIndexOf(marker, end - 1)
    if (open < 0) continue
    const inner = before.slice(open + marker.length, end)
    if (!inner || /^\s|\s$/.test(inner) || (marker.length === 1 && marker !== '`' && inner.includes(marker))) continue
    // snake_case and 2*3*4 stay as typed: the opening marker starts a word.
    if (marker.length === 1 && marker !== '`' && open > 0 && /[\w*_]/.test(before[open - 1])) continue

    const range = doc.createRange()
    range.setStart(node, open)
    range.setEnd(node, offset)
    range.deleteContents()
    const mark = doc.createElement(tag)
    mark.textContent = inner
    range.insertNode(mark)
    // Typing goes on after the mark, not inside it (a zero-width space holds the place; clean() drops it).
    const after = doc.createTextNode('\u200B')
    mark.after(after)
    const caret = doc.createRange()
    caret.setStart(after, 1)
    caret.collapse(true)
    sel.removeAllRanges()
    sel.addRange(caret)
    return true
  }
  return false
}
