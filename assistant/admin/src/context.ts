import { screenContext, screenText, type ScreenContext } from '@letterncms/sdk'
import { panel } from './store'

/** What a message may carry of the page on view. The server cuts anything longer. */
const LIMITS = { data: 40_000, text: 12_000, selection: 12_000, selectedText: 4_000 }

export interface PageContext {
  path?: string
  title?: string
  kind?: string
  data?: string
  text?: string
  selection?: { label: string; data?: string }
  selected_text?: string
  /** The same as the last message of this chat carried, so not sent again. */
  unchanged?: boolean
}

function json(value: unknown, limit: number): string | undefined {
  if (value === undefined || value === null) return undefined
  try {
    return JSON.stringify(value).slice(0, limit)
  } catch {
    return undefined
  }
}

/** What the composer's chips show: the same things a message then carries. */
export function attachments(screen: ScreenContext, state: ReturnType<typeof panel.get>) {
  const secret = screen.private === true
  return {
    page: !secret && !!screen.title && state.dismissedPage !== state.contextPath ? { title: screen.title, icon: screen.icon } : null,
    selection: !secret && screen.selection && state.dismissedSelection !== screen.selection.id ? screen.selection : null,
    text: !secret ? state.selectedText : '',
  }
}

/** Per chat, what its last message carried. */
const carried = new Map<string, string>()

/**
 * What the next message carries of the page next to it, read as it is sent: the
 * screen first brings itself up to date, so the assistant reads and changes what
 * this person sees. The text of a page that says nothing about itself stands in.
 */
export async function pageContext(conversation: string | null): Promise<{ context: PageContext; carry: (id: string) => void }> {
  const screen = screenContext.get()
  const state = panel.get()
  const { page, selection, text } = attachments(screen, state)
  const context: PageContext = {}
  // Where someone is, even on a screen that stays private; unless they took the page off this message.
  if (page || selection || text || screen.private) context.path = state.contextPath
  if (!page && !selection && !text) return { context, carry: () => {} }

  await screen.flush?.()
  if (page) {
    context.title = page.title
    context.kind = screen.kind
    if (screen.data) context.data = json(screen.data(), LIMITS.data)
    else context.text = screenText(LIMITS.text) || undefined
  }
  if (selection) context.selection = { label: selection.label, data: json(selection.data?.(), LIMITS.selection) }
  if (text) {
    context.selected_text = text.slice(0, LIMITS.selectedText)
    // It went with this message; the next one starts clean.
    panel.set({ selectedText: '' })
  }

  const digest = JSON.stringify(context)
  if (conversation && carried.get(conversation) === digest) {
    return { context: { path: context.path, title: context.title, kind: context.kind, unchanged: true }, carry: () => {} }
  }
  return { context, carry: (id) => carried.set(id, digest) }
}

/** An edited prompt rewinds the chat, so what came before it counts as unsent. */
export function forget(conversation: string | null) {
  if (conversation) carried.delete(conversation)
}

document.addEventListener('selectionchange', () => {
  const selection = document.getSelection()
  const node = selection?.anchorNode
  const element = node instanceof Element ? node : node?.parentElement
  // Moving into the composer leaves what was selected attached.
  if (!selection || !element?.closest('[data-cms-screen]')) return
  const text = selection.isCollapsed ? '' : selection.toString().trim()
  if (text !== panel.get().selectedText) panel.set({ selectedText: text })
})
