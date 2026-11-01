import { boot, can, navigate } from '@letterncms/sdk'
import { useSyncExternalStore } from 'react'

interface State {
  conversation: string | null
  title: string
  prompt: string | null
  focus: number
  contextPath: string
  drafts: Record<string, string>
  /** Models picked per chat ('new' before it exists); the next turn sends it. */
  models: Record<string, string>
  working: { conversation: string | null; title: string } | null
}

const listeners = new Set<() => void>()
let state: State = { conversation: null, title: '', prompt: null, focus: 0, contextPath: '/', drafts: {}, models: {}, working: null }

export function chatPath(id: string | null) {
  return id ? `/chat/${encodeURIComponent(id)}` : '/chat/new'
}

export function conversationAt(path: string): string | null | undefined {
  const match = /^\/chat\/([^/]+)$/.exec(path)
  return match ? (match[1] === 'new' ? null : decodeURIComponent(match[1])) : undefined
}

function visit(id: string | null, replace = false) {
  const path = chatPath(id)
  if (window.location.pathname !== boot.base + path) navigate(path, { replace })
  // A CMS editor may block navigation because it contains unsaved changes.
  return window.location.pathname === boot.base + path
}

/** Chat navigation, in-memory drafts and model picks survive moving between CMS screens. */
export const panel = {
  get: () => state,
  set(patch: Partial<State>) {
    state = { ...state, ...(patch.conversation !== undefined && patch.conversation !== state.conversation ? { title: '' } : {}), ...patch }
    listeners.forEach((listener) => listener())
  },
  select(id: string | null) {
    if (!can('assistant.use') || !visit(id)) return false
    panel.set({ conversation: id, prompt: null, focus: state.focus + 1 })
    return true
  },
  newChat() {
    // Repeated clicks focus the existing draft; a saved, finished chat starts fresh.
    const id = state.drafts[state.conversation ?? 'new']?.trim() ? state.conversation : null
    return panel.select(id)
  },
  ask(prompt?: string) {
    if (!panel.newChat()) return
    if (prompt?.trim() && window.location.pathname === boot.base + chatPath(state.conversation)) {
      panel.set({ prompt: prompt.trim() })
    }
  },
  draft(value: string) {
    panel.set({ drafts: { ...state.drafts, [state.conversation ?? 'new']: value } })
  },
  /** Throw away the unsent new chat. */
  discard() {
    panel.set({ drafts: { ...state.drafts, new: '' } })
  },
  model(id: string) {
    panel.set({ models: { ...state.models, [state.conversation ?? 'new']: id } })
  },
  deleted(id: string) {
    const { [id]: removed, ...drafts } = state.drafts
    const { [id]: picked, ...models } = state.models
    panel.set({ drafts, models })
    // A slow deletion must not pull someone away from another chat or CMS screen.
    if (window.location.pathname === boot.base + chatPath(id)) visit(null, true)
    if (state.conversation === id) panel.set({ conversation: null, prompt: null, focus: state.focus + 1 })
  },
  created(id: string, title: string) {
    if (state.conversation !== id) {
      const { new: picked, ...models } = state.models
      panel.set({ conversation: id, title, drafts: { ...state.drafts, [id]: state.drafts.new ?? '', new: '' }, models: picked ? { ...models, [id]: picked } : models })
    }
    else panel.set({ title })
    if (window.location.pathname === boot.base + '/chat/new') visit(id, true)
  },
  subscribe(listener: () => void) {
    listeners.add(listener)
    return () => listeners.delete(listener)
  },
}

export function usePanel(): State {
  return useSyncExternalStore(panel.subscribe, panel.get)
}
