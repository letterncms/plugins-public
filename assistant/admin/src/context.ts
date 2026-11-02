import { screenContext, screenText, t, type ContextItem, type ScreenContext } from '@letterncms/sdk'
import { panel } from './store'

/** How much of each thing a message may carry; the server turns away anything longer. */
const LIMIT = 40_000
/** Of a screen that says nothing about itself: its text. */
const SCREEN_TEXT = 12_000
/** Of the text someone selected. */
const SELECTED_TEXT = 4_000

/** One thing as it goes out with a message. */
export interface SentItem {
  kind?: string
  label: string
  icon?: string
  to?: string
  content?: string
  /** The same as the last message of this chat carried, so not sent again. */
  unchanged?: boolean
}

export interface MessageContext {
  path?: string
  items?: SentItem[]
  skill?: string
}

type State = ReturnType<typeof panel.get>

/**
 * What a message would carry on its own, as it stands: the page on view, what the
 * screen offers, the text selected on it. A private screen offers nothing.
 */
export function offered(screen: ScreenContext, state: State): ContextItem[] {
  if (screen.private) return []
  const items: ContextItem[] = []
  if (screen.title) {
    items.push({
      id: 'page', kind: 'page', label: screen.title, icon: screen.icon ?? 'file', to: state.contextPath,
      read: screen.kind ? async () => ({ kind: screen.kind, ...(screen.data?.() as object) }) : () => screenText(SCREEN_TEXT),
    })
  }
  items.push(...(screen.items ?? []))
  if (state.selectedText) {
    const text = state.selectedText.slice(0, SELECTED_TEXT)
    items.push({ id: 'text', kind: 'selected_text', label: text.replace(/\s+/g, ' '), icon: 'quote', read: () => text })
  }
  return items.filter((item) => !state.dismissed.includes(item.id))
}

/** All of it: what is on offer, then what was picked for this message. */
export function attachments(screen: ScreenContext, state: State): ContextItem[] {
  const picked = state.attached[state.conversation ?? 'new'] ?? []
  return [...offered(screen, state), ...picked.filter((item) => !offered(screen, state).some((other) => other.id === item.id))]
}

const text = (value: unknown): string | undefined => {
  if (value === undefined || value === null || value === '') return undefined
  try {
    return (typeof value === 'string' ? value : JSON.stringify(value)).slice(0, LIMIT)
  } catch {
    return undefined
  }
}

/** Per chat and item: what it last carried. */
const carried = new Map<string, Map<string, string>>()

/**
 * What the next message carries, read as it is sent: the screen first brings
 * itself up to date, so the assistant reads and changes what this person sees.
 */
export async function messageContext(conversation: string | null): Promise<{ context: MessageContext; carry: (id: string) => void }> {
  const screen = screenContext.get()
  const state = panel.get()
  const key = conversation ?? 'new'
  const items = attachments(screen, state)
  const skill = state.skills[key]?.skill
  // What was picked goes with this message; the next one starts clean.
  panel.set({ attached: { ...state.attached, [key]: [] }, skills: { ...state.skills, [key]: null }, selectedText: '' })

  await screen.flush?.()
  const seen = (conversation && carried.get(conversation)) || new Map<string, string>()
  const next = new Map(seen)
  const sent = await Promise.all(items.map(async (item): Promise<SentItem> => {
    const shown = { kind: item.kind, label: item.label, icon: item.icon, to: item.to }
    const content = text(await Promise.resolve().then(() => item.read?.()).catch(() => undefined))
    if (content === undefined) return shown
    if (seen.get(item.id) === content) return { ...shown, unchanged: true }
    next.set(item.id, content)
    return { ...shown, content }
  }))

  return {
    // Where someone is, even on a screen that stays private; unless they took the page off this message.
    context: { path: items.some((item) => item.id === 'page') || screen.private ? state.contextPath : undefined, items: sent, skill: skill ?? undefined },
    carry: (id) => carried.set(id, next),
  }
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
  const selected = selection.isCollapsed ? '' : selection.toString().trim()
  if (selected !== panel.get().selectedText) panel.set({ selectedText: selected, dismissed: panel.get().dismissed.filter((id) => id !== 'text') })
})

/** What the name of a kind of thing says on a chip that has no better hint. */
export const hints: Record<string, () => string> = {
  page: () => t('{brand} sees this page'),
  block: () => t('{brand} sees this block'),
  selected_text: () => t('{brand} sees the text you selected'),
}
