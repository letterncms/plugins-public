import { getManifest, run, t, useAbility } from '@letterncms/sdk'
import { Icon, Menu } from '@letterncms/ui'
import { useEffect } from 'react'

interface Model {
  id: string
  name: string
  description: string
}

interface Status {
  configured: boolean
  /** The default from Settings → Assistant. */
  model: string
  models: Model[]
}

/** What the workspace hands a panel in the composer's footer (`workspace.composer`). */
interface ComposerPanelProps {
  thread: string | null
  state: Record<string, unknown> | null
  options: Record<string, unknown>
  setOption: (name: string, value: unknown) => void
  loading: boolean
}

const PREFERENCE = 'assistant.model'
const answering = () => (getManifest().config?.workspace?.agent as { name?: string } | null | undefined)?.name === 'assistant'

/** New threads start with the person's last pick, while it is still offered; otherwise with the default. */
function preferred(status: Status): string {
  const saved = getManifest().user.preferences?.[PREFERENCE]
  return status.models.some((m) => m.id === saved) ? (saved as string) : status.model
}

function remember(id: string) {
  // The manifest is this session's copy of the preferences; keep it current for the next new thread.
  const user = getManifest().user
  user.preferences = { ...(user.preferences ?? {}), [PREFERENCE]: id }
  void run('account/update', { preferences: { [PREFERENCE]: id } }).catch(() => {})
}

/**
 * Which model the next message runs on, in the composer. A change applies from the next message and
 * stays with the thread; a thread saved with a model no longer offered falls back to the default.
 */
export function ModelPicker({ thread, state, options, setOption, loading }: ComposerPanelProps) {
  const ready = answering()
  const status = useAbility<Status>('assistant/status', {}, { staleTime: 60_000, enabled: ready })
  const models = status.data?.models ?? []
  const offered = (id: unknown): id is string => typeof id === 'string' && models.some((m) => m.id === id)
  const value = offered(options.model) ? options.model : offered(state?.model) ? state.model : !status.data ? undefined : thread ? status.data.model : preferred(status.data)
  const current = models.find((m) => m.id === value)
  // A new thread sends the pick it starts with.
  const start = !thread && options.model === undefined ? current?.id : undefined
  useEffect(() => { if (start) setOption('model', start) }, [start, setOption])
  if (!ready || !status.data || loading || !current) return null
  return (
    <Menu
      side="top"
      align="start"
      className="w-72"
      trigger={
        <button
          type="button"
          aria-label={t('Model: {name}', { name: current.name })}
          className="flex h-7 min-w-0 items-center gap-1 rounded-md pl-2 pr-1.5 text-xs text-muted outline-offset-[-2px] transition-colors hover:bg-hover hover:text-text data-[popup-open]:bg-hover data-[popup-open]:text-text"
        >
          <span className="truncate">{current.name}</span>
          <Icon name="chevron-down" size={12} className="shrink-0 text-faint" />
        </button>
      }
      items={[
        { label: t('Model'), heading: true },
        ...models.map((m) => ({ label: m.name, description: m.description, checked: m.id === current.id, onSelect: () => { if (m.id !== current.id) { setOption('model', m.id); remember(m.id) } } })),
      ]}
    />
  )
}
