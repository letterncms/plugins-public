import { boot, can, getManifest, navigate } from '@letterncms/sdk'
import { useSyncExternalStore } from 'react'

/** Where the chat is: out of the way, docked beside the page, or taking the whole window (a chat's own URL). */
export type Mode = 'closed' | 'docked' | 'full'

interface State {
  mode: Mode
  /** In the dock, the list of chats takes the thread's place. */
  history: boolean
  conversation: string | null
  title: string
  prompt: string | null
  focus: number
  contextPath: string
  /** The page this person took out of the chat with its chip; it comes back on the next page. */
  dismissedPage: string | null
  /** Likewise for the selected block. */
  dismissedSelection: string | null
  /** Text selected on the page. */
  selectedText: string
  drafts: Record<string, string>
  /** Models picked per chat ('new' before it exists); the next turn sends it. */
  models: Record<string, string>
  working: { conversation: string | null; title: string } | null
  /** Calls in the chat wait for this person's go-ahead. */
  awaiting: boolean
}

const OPEN = 'cms.chat.open'
const CURRENT = 'cms.chat.current'

/** Reloading brings the chat back where it was; a phone or small window starts with the page. */
function restored(): Pick<State, 'mode' | 'conversation'> {
  try {
    const open = localStorage.getItem(OPEN) === 'true' && window.matchMedia('(min-width: 1024px)').matches
    return { mode: open ? 'docked' : 'closed', conversation: sessionStorage.getItem(CURRENT) || null }
  } catch {
    return { mode: 'closed', conversation: null }
  }
}

const listeners = new Set<() => void>()
let state: State = { ...restored(), history: false, title: '', prompt: null, focus: 0, contextPath: '/', dismissedPage: null, dismissedSelection: null, selectedText: '', drafts: {}, models: {}, working: null, awaiting: false }
/** Where maximizing started, to return to. */
let returnTo: string | null = null
/** What had the focus before the dock took it. */
let returnFocus: Element | null = null

export function chatPath(id: string | null) {
  return id ? `/chat/${encodeURIComponent(id)}` : '/chat/new'
}

export function conversationAt(path: string): string | null | undefined {
  const match = /^\/chat\/([^/]+)$/.exec(path)
  return match ? (match[1] === 'new' ? null : decodeURIComponent(match[1])) : undefined
}

/** Its nav item says who gets it: without an API key, only those who can add one. Everyone else sees no assistant they can't use. */
export const available = () => can('assistant.use') && getManifest().nav.some((item) => item.id === 'chat')

const here = () => window.location.pathname.slice(boot.base.length) || '/'

/** The chat's URL replaces the page's while the page stays open underneath: nothing is being left. */
function visit(id: string | null, replace = false) {
  const path = chatPath(id)
  if (here() !== path) navigate(path, { replace, overlay: true })
}

function persist() {
  try {
    localStorage.setItem(OPEN, String(state.mode !== 'closed'))
    if (state.conversation) sessionStorage.setItem(CURRENT, state.conversation)
    else sessionStorage.removeItem(CURRENT)
  } catch { /* Optional preference. */ }
}

/** The one chat of the admin: where it is, in-memory drafts and model picks survive moving between screens. */
export const panel = {
  get: () => state,
  set(patch: Partial<State>) {
    const before = state
    state = { ...state, ...(patch.conversation !== undefined && patch.conversation !== state.conversation ? { title: '' } : {}), ...patch }
    listeners.forEach((listener) => listener())
    if (state.mode !== before.mode || state.conversation !== before.conversation) persist()
  },
  /** Bring it up, with the focus in its composer. */
  open() {
    if (!available()) return
    if (state.mode === 'closed') {
      returnFocus = document.activeElement
      panel.set({ mode: 'docked', focus: state.focus + 1 })
    } else panel.set({ focus: state.focus + 1 })
  },
  close() {
    const full = state.mode === 'full'
    panel.set({ mode: 'closed' })
    if (full) navigate(returnTo ?? '/', { overlay: returnTo !== null })
    if (returnFocus instanceof HTMLElement && returnFocus.isConnected) returnFocus.focus()
    returnFocus = null
  },
  /** ⌘J: bring it up; from inside it, put it away again. */
  toggle() {
    const inside = document.activeElement?.closest('.cms-dock')
    if (state.mode === 'closed' || !inside) panel.open()
    else if (state.mode === 'full') panel.minimize()
    else panel.close()
  },
  maximize() {
    if (!available()) return
    returnTo = here() + window.location.search
    visit(state.conversation)
  },
  minimize() {
    if (state.mode !== 'full') return
    navigate(returnTo ?? '/', { overlay: returnTo !== null })
  },
  /** Open a chat where the chat is. */
  select(id: string | null) {
    if (!available()) return
    if (state.mode === 'full') visit(id)
    if (state.mode === 'closed') returnFocus = document.activeElement
    panel.set({ conversation: id, mode: state.mode === 'closed' ? 'docked' : state.mode, history: false, prompt: null, focus: state.focus + 1 })
  },
  newChat() {
    // An unsent message waits where it was written; a saved, finished chat starts fresh.
    const id = state.drafts[state.conversation ?? 'new']?.trim() ? state.conversation : null
    panel.select(id)
  },
  ask(prompt?: string) {
    panel.newChat()
    if (prompt?.trim()) panel.set({ prompt: prompt.trim() })
  },
  draft(value: string) {
    panel.set({ drafts: { ...state.drafts, [state.conversation ?? 'new']: value } })
  },
  model(id: string) {
    panel.set({ models: { ...state.models, [state.conversation ?? 'new']: id } })
  },
  deleted(id: string) {
    const { [id]: removed, ...drafts } = state.drafts
    const { [id]: picked, ...models } = state.models
    panel.set({ drafts, models })
    // A slow deletion must not pull someone away from another chat.
    if (here() === chatPath(id)) visit(null, true)
    if (state.conversation === id) panel.set({ conversation: null, prompt: null, focus: state.focus + 1 })
  },
  created(id: string, title: string) {
    if (state.conversation !== id) {
      const { new: picked, ...models } = state.models
      panel.set({ conversation: id, title, drafts: { ...state.drafts, [id]: state.drafts.new ?? '', new: '' }, models: picked ? { ...models, [id]: picked } : models })
    }
    else panel.set({ title })
    if (here() === '/chat/new') visit(id, true)
  },
  subscribe(listener: () => void) {
    listeners.add(listener)
    return () => { listeners.delete(listener) }
  },
}

export function usePanel(): State {
  return useSyncExternalStore(panel.subscribe, panel.get)
}
