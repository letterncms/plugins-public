import { createContext, useContext, type KeyboardEvent } from 'react'
import { atEnd, atStart, onFirstLine, onLastLine } from '../core/caret'
import type { EditorStore } from '../core/store'
import { newId, type BlockNode } from '../core/types'
import type { Stop } from './outline'

/** What the block document shares with its rows (the outline is derived once, in WriteEditor). */
export interface WriteApi {
  /** Caret stops in reading order, for the current tree. */
  stops: () => Stop[]
  /** Open a block's settings beside it. */
  settings: (id: string) => void
  readOnly: boolean
}

export const WriteContext = createContext<WriteApi>({ stops: () => [], settings: () => {}, readOnly: false })

export const useWrite = () => useContext(WriteContext)

export function paragraph(text = ''): BlockNode {
  return { id: newId(), type: 'paragraph', props: { text } }
}

export function sameStop(a: Stop, id: string, prop: string, index?: number) {
  return a.id === id && a.prop === prop && (index === undefined || a.index === index)
}

export function focusStop(store: EditorStore, stop: Stop, at: 'start' | 'end' | number) {
  store.focus(stop.id, at, stop.prop, stop.index)
}

/** Put the caret in the first editable text of a freshly inserted block, or select it. */
export function focusFirst(store: EditorStore, node: BlockNode | undefined, stops: Stop[]) {
  if (!node) return
  const ids = new Set<string>()
  const walk = (n: BlockNode) => {
    ids.add(n.id)
    n.children?.forEach(walk)
  }
  walk(node)
  const stop = stops.find((s) => ids.has(s.id))
  if (stop) focusStop(store, stop, 'start')
  else store.select(node.id, true)
}

/** The page title above the document (the entry editor's title field). */
function focusTitle(doc: Document): boolean {
  const title = doc.getElementById('f-title') as HTMLTextAreaElement | null
  if (!title) return false
  title.focus()
  title.setSelectionRange(title.value.length, title.value.length)
  return true
}

/**
 * Arrow keys that leave a text at its edge move to the neighboring stop, the
 * way a single document would. Returns true when it handled the key.
 */
export function moveBetweenStops(e: KeyboardEvent<HTMLElement>, el: HTMLElement, store: EditorStore, stops: Stop[], at: number): boolean {
  if (e.shiftKey || e.metaKey || e.ctrlKey || e.altKey) return false
  const back = (e.key === 'ArrowUp' && onFirstLine(el)) || (e.key === 'ArrowLeft' && atStart(el))
  const forward = (e.key === 'ArrowDown' && onLastLine(el)) || (e.key === 'ArrowRight' && atEnd(el))
  if (back) {
    const prev = stops[at - 1]
    if (prev) focusStop(store, prev, 'end')
    else if (e.key !== 'ArrowUp' || !focusTitle(el.ownerDocument)) return false
    e.preventDefault()
    return true
  }
  if (forward) {
    const next = stops[at + 1]
    if (!next) return false
    e.preventDefault()
    focusStop(store, next, 'start')
    return true
  }
  return false
}
