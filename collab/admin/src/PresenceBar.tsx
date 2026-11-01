import { plural, run, t, useManifest, type Entry } from '@letterncms/sdk'
import { Avatar, Button, Tooltip, cn } from '@letterncms/ui'
import { useEffect, useRef, useState } from 'react'

interface Person {
  id: string
  name: string
  initials: string
  avatar: string | null
  editing: boolean
}

interface Beat {
  others: Person[]
  updatedAt?: string
  updatedBy?: { id: string; name: string } | null
}

const EVERY = 15_000
const TYPING_WINDOW = 60_000

/** Entry toolbar: who else has this entry open, and a nudge when they saved newer changes. */
export function PresenceBar({ entry, reload }: { entry: Entry | null; reload: () => void }) {
  const manifest = useManifest()
  const [beat, setBeat] = useState<Beat>({ others: [] })
  const lastInput = useRef(0)
  const id = entry?.id

  useEffect(() => {
    if (!id) return
    const onInput = () => (lastInput.current = Date.now())
    document.addEventListener('input', onInput, true)
    document.addEventListener('keydown', onInput, true)

    let alive = true
    const tick = () => {
      if (document.visibilityState === 'hidden') return
      run<Beat>('collab/presence', { entryId: id, editing: Date.now() - lastInput.current < TYPING_WINDOW })
        .then((b) => alive && setBeat(b))
        .catch(() => {})
    }
    tick()
    const timer = window.setInterval(tick, EVERY)
    document.addEventListener('visibilitychange', tick)

    return () => {
      alive = false
      window.clearInterval(timer)
      document.removeEventListener('input', onInput, true)
      document.removeEventListener('keydown', onInput, true)
      document.removeEventListener('visibilitychange', tick)
      void run('collab/presence', { entryId: id, leave: true }).catch(() => {})
    }
  }, [id])

  if (!entry) return null

  const newer = beat.updatedAt && entry.updatedAt && Date.parse(beat.updatedAt) > Date.parse(entry.updatedAt) && beat.updatedBy?.id !== manifest.user.id
  const editing = beat.others.filter((p) => p.editing)

  return (
    <div className="flex items-center gap-2">
      {newer && (
        <Tooltip content={t('Your unsaved edits will be replaced')}>
          <Button size="xs" variant="secondary" icon="refresh" onClick={reload}>
            {beat.updatedBy ? t('{name} saved · Reload', { name: beat.updatedBy.name }) : t('Someone saved · Reload')}
          </Button>
        </Tooltip>
      )}
      {beat.others.length > 0 && (
        <Tooltip content={beat.others.map((p) => (p.editing ? t('{name} is editing', { name: p.name }) : t('{name} is viewing', { name: p.name }))).join(' · ')}>
          <div className="flex -space-x-1.5" aria-label={plural(beat.others.length, '{count} other person here', '{count} other people here')}>
            {beat.others.slice(0, 4).map((p) => (
              <span key={p.id} className={cn('rounded-full ring-2', p.editing ? 'ring-ink' : 'ring-bg')}>
                <Avatar name={p.name} initials={p.initials} src={p.avatar} size={22} />
              </span>
            ))}
          </div>
        </Tooltip>
      )}
      {editing.length > 0 && <span className="text-xs text-muted">{editing.length === 1 ? t('{name} is editing', { name: editing[0].name }) : t('{count} editing', { count: editing.length })}</span>}
    </div>
  )
}
