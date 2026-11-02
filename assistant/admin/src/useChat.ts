import { eventStream, events, loadManifest, queryClient, run, t, CmsError, type SurfaceDocument, type SurfaceAction } from '@letterncms/sdk'
import { useCallback, useEffect, useRef, useState } from 'react'
import { forget, messageContext, type SentItem } from './context'
import { panel } from './store'

export type ToolStatus = 'running' | 'ok' | 'error' | 'declined' | 'awaiting'

export type Part =
  | { type: 'text'; text: string }
  | { type: 'thinking'; text: string }
  | { type: 'tool'; id: string; name: string; label: string; input?: unknown; status: ToolStatus; output?: string; surface?: SurfaceDocument; used?: boolean }

export type Item = { role: 'user'; text: string; messageIndex?: number; attached?: Omit<SentItem, 'content' | 'unchanged'>[] } | { role: 'assistant'; parts: Part[] } | { role: 'notice'; text: string; tone: string }

export interface Call {
  id: string
  name: string
  label: string
  input: Record<string, unknown>
  destructive: boolean
  /** The chat has read content others may have written, so changes ask first. */
  untrusted?: boolean
}

interface Loaded {
  id: string
  title: string
  /** Null for chats that follow the default model. */
  model: string | null
  items: Item[]
  awaiting: Call[]
}

interface Models {
  /** Picked for this chat, or the start for a new one. */
  picked?: string
  /** For chats that never picked one, or whose model is no longer offered. */
  fallback?: string
  /** The models on offer; a chat saved with another one falls back. */
  offered?: string[]
}

/** One conversation with the assistant: history, streaming state and actions. Turns run on `model`. */
export function useChat(conversation: string | null, { picked, fallback, offered }: Models = {}) {
  const [items, setItems] = useState<Item[]>([])
  const [saved, setSaved] = useState<string | null>(null)
  const model = picked ?? (saved && (!offered || offered.includes(saved)) ? saved : fallback)
  const [awaiting, setAwaiting] = useState<Call[]>([])
  const [title, setTitle] = useState('')
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const abort = useRef<AbortController | null>(null)
  const current = useRef(conversation)
  const turnModel = useRef(model)
  turnModel.current = model

  // Load a saved chat (unless it is the one we are streaming into).
  useEffect(() => {
    if (conversation === current.current && (items.length || busy)) return
    abort.current?.abort()
    abort.current = null
    setBusy(false)
    setLoading(false)
    current.current = conversation
    setItems([])
    setTitle('')
    setSaved(null)
    setError(null)
    setAwaiting([])
    if (!conversation) {
      return
    }
    let canceled = false
    setLoading(true)
    run<Loaded>('assistant/conversation', { id: conversation })
      .then((c) => {
        if (canceled) return
        setItems(c.items)
        setAwaiting(c.awaiting)
        setTitle(c.title)
        setSaved(c.model)
        panel.set({ title: c.title })
      })
      .catch((e: CmsError) => {
        if (canceled) return
        if (e.status === 404) panel.deleted(conversation)
        else setError(e.message)
      })
      .finally(() => !canceled && setLoading(false))
    return () => {
      canceled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [conversation])

  useEffect(() => () => abort.current?.abort(), [])

  const update = (fn: (parts: Part[]) => Part[]) =>
    setItems((list) => {
      const last = list[list.length - 1]
      if (last?.role !== 'assistant') return [...list, { role: 'assistant', parts: fn([]) }]
      return [...list.slice(0, -1), { ...last, parts: fn(last.parts) }]
    })

  const patchTool = (id: string, patch: Partial<Extract<Part, { type: 'tool' }>>) =>
    setItems((list) =>
      list.map((item) =>
        item.role === 'assistant' ? { ...item, parts: item.parts.map((p) => (p.type === 'tool' && p.id === id ? { ...p, ...patch } : p)) } : item,
      ),
    )

  const append = (type: 'text' | 'thinking', delta: string) =>
    update((parts) => {
      const last = parts[parts.length - 1]
      return last?.type === type ? [...parts.slice(0, -1), { ...last, text: last.text + delta }] : [...parts, { type, text: delta }]
    })

  const stream = useCallback(async (body: Record<string, unknown>, onAccepted?: () => void) => {
    abort.current?.abort()
    const controller = new AbortController()
    abort.current = controller
    setBusy(true)
    setError(null)
    let working = { conversation: current.current, title: panel.get().title || String(body.message ?? t('New Chat')) }
    panel.set({ working })
    let mutated = false
    let accepted = false
    const model = turnModel.current

    try {
      // Only what someone says carries the page; deciding and clicking go on from there.
      if (body.edit) forget(current.current)
      const page = typeof body.message === 'string' ? await messageContext(current.current) : null
      if (controller.signal.aborted) return false
      await eventStream(
        'assistant/chat',
        { conversation: current.current, model, ...body, context: page?.context },
        (event, data) => {
          if (controller.signal.aborted || abort.current !== controller) return
          switch (event) {
            case 'user_message': {
              accepted = true
              const edit = body.edit as { index: number } | undefined
              setItems((list) => {
                const index = edit ? list.findIndex((item) => item.role === 'user' && item.messageIndex === edit.index) : list.findLastIndex((item) => item.role === 'user')
                if (index < 0) return list
                return [...list.slice(0, index), { role: 'user', text: data.text, messageIndex: data.messageIndex, attached: data.attached }, ...(edit ? [] : list.slice(index + 1))]
              })
              if (edit) { setAwaiting([]); onAccepted?.() }
              break
            }
            case 'conversation':
              // The server took the turn, and with it the model it runs on.
              if (model) setSaved(model)
              current.current = data.id
              page?.carry(data.id)
              working = { conversation: data.id, title: data.title }
              panel.set({ working })
              setTitle(data.title)
              panel.created(data.id, data.title)
              void queryClient.invalidateQueries({ queryKey: ['assistant/conversations'] })
              break
            case 'text':
            case 'thinking':
              append(event, data.delta)
              break
            case 'tool':
              update((parts) => [...parts, { type: 'tool', id: data.id, name: data.name, label: data.label, status: 'running' }])
              break
            case 'tool_result':
              patchTool(data.id, { status: data.declined ? 'declined' : data.ok ? 'ok' : 'error', output: data.output, input: data.input, surface: data.surface ?? undefined })
              mutated ||= data.mutated
              if (data.mutated) {
                void queryClient.invalidateQueries()
                // An open editor shows what just changed.
                events.emit('content.changed')
              }
              break
            case 'surface_used':
              patchTool(data.id, { used: true })
              break
            case 'approval':
              setAwaiting(data.calls)
              for (const call of data.calls as Call[]) patchTool(call.id, { status: 'awaiting', input: call.input })
              break
            case 'notice':
              setItems((list) => [...list, { role: 'notice', text: data.message, tone: data.tone }])
              break
            case 'error':
              setError(data.message)
              break
            case 'done':
              setTitle(data.conversation.title)
              panel.set({ title: data.conversation.title })
              break
          }
        },
        controller.signal,
      )
    } catch (e) {
      if (!controller.signal.aborted) setError(e instanceof Error ? e.message : t('The assistant failed.'))
    } finally {
      if (mutated) {
        try {
          await loadManifest() // Package and schema changes bring the shared component registry along immediately.
        } catch (e) {
          if (!controller.signal.aborted && abort.current === controller) setError(e instanceof Error ? e.message : t('The assistant failed.'))
        }
      }
      if (panel.get().working === working) panel.set({ working: null })
      if (abort.current === controller) {
        abort.current = null
        setBusy(false)
      }
      if (mutated) void queryClient.invalidateQueries()
      void queryClient.invalidateQueries({ queryKey: ['assistant/conversations'] })
    }
    return accepted
  }, [])

  const edit = useCallback((item: Extract<Item, { role: 'user' }>, text: string, onAccepted?: () => void) => {
    if (abort.current || loading || !current.current || item.messageIndex === undefined || !text.trim()) return Promise.resolve(false)
    return stream({ message: text.trim(), edit: { index: item.messageIndex, original: item.text } }, onAccepted)
  }, [loading, stream])

  const send = useCallback(
    (text: string) => {
      const message = text.trim()
      if (!message) return
      // A new message declines anything still waiting (the server does the same).
      for (const call of awaiting) patchTool(call.id, { status: 'declined' })
      setAwaiting([])
      setItems((list) => [...list, { role: 'user', text: message }])
      void stream({ message })
    },
    [awaiting, stream],
  )

  const decide = useCallback(
    (approve: string[]) => {
      for (const call of awaiting) if (!approve.includes(call.id)) patchTool(call.id, { status: 'declined' })
      for (const id of approve) patchTool(id, { status: 'running' })
      setAwaiting([])
      void stream({ approve })
    },
    [awaiting, stream],
  )

  const stop = useCallback(() => {
    abort.current?.abort()
    abort.current = null
    setBusy(false)
    panel.set({ working: null })
    setItems((list) => [...list, { role: 'notice', text: t('Stopped. The step in progress still finishes on the server.'), tone: 'neutral' }])
  }, [])

  const interact = useCallback((tool: string, action: SurfaceAction) => {
    if (abort.current || awaiting.length || !current.current || loading) return
    const part = items.flatMap((item) => item.role === 'assistant' ? item.parts : []).find((p) => p.type === 'tool' && p.id === tool)
    if (part?.type !== 'tool' || !part.surface || part.used) return
    setItems((list) => [...list, { role: 'user', text: t('Selected an action in “{title}”.', { title: part.surface!.title }) }])
    void stream({ action: { tool, ...action } })
  }, [items, awaiting, loading, stream])

  return { items, awaiting, title, model, busy, loading, error, send, edit, decide, stop, interact, dismissError: () => setError(null) }
}
