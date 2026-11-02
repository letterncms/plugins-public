import { registry, t, useScreen, type ComposerOption, type ComposerSource, type ContextItem } from '@letterncms/sdk'
import { Icon, Spinner, Textarea, Tooltip, cn } from '@letterncms/ui'
import { useEffect, useId, useLayoutEffect, useRef, useState, useSyncExternalStore, type KeyboardEvent, type ReactNode } from 'react'
import { attachments, hints, offered } from './context'
import { panel, usePanel } from './store'

interface Trigger {
  char: '@' | '/'
  query: string
  /** Where the trigger stands in the text. */
  start: number
}

/** @ anywhere after a space, / only at the start of a line: a path in a sentence isn't a command. */
function triggerAt(text: string, caret: number): Trigger | null {
  const match = /(?:^|\s)@([^\s@]*)$|(?:^|\n)\/([^\s/]*)$/.exec(text.slice(0, caret))
  if (!match) return null
  const char = match[1] !== undefined ? '@' : '/'
  const query = match[1] ?? match[2] ?? ''
  return { char, query, start: caret - query.length - 1 }
}

interface Group {
  source: ComposerSource
  options: ComposerOption[]
}

/** What the sources registered for this trigger find, as they find it. A slow one doesn't hold up the rest. */
function useOptions(trigger: Trigger | null) {
  const version = useSyncExternalStore(registry.composer.subscribe, registry.composer.version)
  const [groups, setGroups] = useState<Group[]>([])
  const [loading, setLoading] = useState(false)
  const char = trigger?.char
  const query = trigger?.query
  useEffect(() => {
    if (!char) {
      setGroups([])
      return
    }
    let canceled = false
    setLoading(true)
    const sources = registry.composer.all().filter((source) => source.trigger === char).sort((a, b) => (a.order ?? 50) - (b.order ?? 50))
    const timer = setTimeout(() => {
      const found = new Map<string, ComposerOption[]>()
      let pending = sources.length
      if (!pending) setLoading(false)
      for (const source of sources) {
        void Promise.resolve().then(() => source.search(query ?? '')).catch(() => [] as ComposerOption[]).then((options) => {
          if (canceled) return
          found.set(source.id, options)
          setGroups(sources.map((s) => ({ source: s, options: found.get(s.id) ?? [] })).filter((group) => group.options.length > 0))
          if (--pending === 0) setLoading(false)
        })
      }
    }, query ? 120 : 0)
    return () => {
      canceled = true
      clearTimeout(timer)
    }
  }, [char, query, version])
  return { groups, loading }
}

/** The chips: what the message carries. Each comes off with its ×. */
function Attachments({ onAdd }: { onAdd: () => void }) {
  const state = usePanel()
  const screen = useScreen()
  const key = state.conversation ?? 'new'
  const items = attachments(screen, state)
  const skill = state.skills[key]
  const auto = new Set(offered(screen, state).map((item) => item.id))
  const off = screen.title && !screen.private && state.dismissed.includes('page')
  const remove = (item: ContextItem) => {
    if (item.id === 'text') panel.set({ selectedText: '' })
    else if (auto.has(item.id)) panel.set({ dismissed: [...state.dismissed, item.id] })
    else panel.set({ attached: { ...state.attached, [key]: (state.attached[key] ?? []).filter((other) => other.id !== item.id) } })
  }
  return (
    <div className="flex flex-wrap items-center gap-1 px-2 pt-2">
      <Tooltip content={t('Add Context')} shortcut="@">
        <button type="button" aria-label={t('Add Context')} onClick={onAdd} className="cms-assistant-chip justify-center !px-0 w-[22px] text-muted hover:text-text">
          <span aria-hidden="true" className="text-xs leading-none">@</span>
        </button>
      </Tooltip>
      {skill && <Chip icon={skill.icon ?? 'sparkle'} label={skill.label} hint={skill.detail ?? skill.label} strong onRemove={() => panel.set({ skills: { ...state.skills, [key]: null } })} />}
      {items.map((item) => (
        <Chip key={item.id} icon={item.icon ?? 'file'} label={item.label} hint={(item.kind && hints[item.kind]?.()) ?? item.label} strong={!auto.has(item.id)} onRemove={() => remove(item)} />
      ))}
      {off && (
        <button type="button" onClick={() => panel.set({ dismissed: state.dismissed.filter((id) => id !== 'page') })} className="cms-assistant-chip pr-1.5 text-faint hover:text-text">
          <Icon name="plus" size={11} /><span className="truncate">{screen.title}</span>
        </button>
      )}
    </div>
  )
}

function Chip({ icon, label, hint, strong, onRemove }: { icon: string; label: string; hint: string; strong?: boolean; onRemove: () => void }) {
  return (
    <span className={cn('cms-assistant-chip', strong ? 'text-text' : 'text-muted')}>
      <Tooltip content={hint}>
        <span tabIndex={0} className="flex min-w-0 items-center gap-1 outline-offset-1"><Icon name={icon} size={11} className="shrink-0" /><span className="truncate">{label}</span></span>
      </Tooltip>
      <button type="button" aria-label={t('Remove {name}', { name: label })} onClick={onRemove} className="grid size-4 shrink-0 place-items-center rounded-xs text-faint hover:bg-hover hover:text-text">
        <Icon name="close" size={10} />
      </button>
    </span>
  )
}

/** The lines `@` or `/` offer, above the composer. The keys stay in the text field. */
function Picker({ id, trigger, groups, loading, active, onActive, onPick }: { id: string; trigger: Trigger; groups: Group[]; loading: boolean; active: number; onActive: (index: number) => void; onPick: (option: ComposerOption) => void }) {
  const list = useRef<HTMLDivElement>(null)
  useLayoutEffect(() => {
    list.current?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' })
  }, [active])
  let index = -1
  return (
    <div ref={list} id={id} role="listbox" aria-label={trigger.char === '@' ? t('Add Context') : t('Skills')} className="cms-pop absolute inset-x-0 bottom-full z-10 mb-1.5 max-h-72 overflow-y-auto overscroll-contain p-1">
      {groups.map(({ source, options }) => (
        <div key={source.id} role="group" aria-label={source.label}>
          <div className="flex h-6 items-center px-2 text-2xs font-medium text-faint">{source.label}</div>
          {options.map((option) => {
            index++
            const at = index
            return (
              <div
                key={option.id}
                id={`${id}-${at}`}
                role="option"
                aria-selected={at === active}
                onMouseEnter={() => onActive(at)}
                onMouseDown={(e) => { e.preventDefault(); onPick(option) }}
                className={cn('flex min-h-8 cursor-default items-center gap-2 rounded-sm px-2 py-1 text-sm', at === active ? 'bg-hover text-ink' : 'text-text')}
              >
                <Icon name={option.icon ?? 'file'} size={14} className="shrink-0 text-muted" />
                <span className="shrink-0 truncate">{option.label}</span>
                {option.detail && <span className="min-w-0 flex-1 truncate text-xs text-faint">{option.detail}</span>}
              </div>
            )
          })}
        </div>
      ))}
      {groups.length === 0 && (
        <div className="flex h-8 items-center gap-2 px-2 text-sm text-muted">
          {loading ? <><Spinner size={12} /> {t('Looking…')}</> : t('Nothing found')}
        </div>
      )}
    </div>
  )
}

export interface ComposerProps {
  placeholder: string
  /** Bumped to move the focus here. */
  focus: number
  onSubmit: () => void
  children: ReactNode
}

/**
 * Where a message is written: its chips, its text, and what @ and / offer as it is
 * typed. Enter sends, Shift+Enter breaks the line.
 */
export function Composer({ placeholder, focus, onSubmit, children }: ComposerProps) {
  const { drafts, conversation } = usePanel()
  const key = conversation ?? 'new'
  const draft = drafts[key] ?? ''
  const input = useRef<HTMLTextAreaElement>(null)
  const [caret, setCaret] = useState(0)
  const [closedAt, setClosedAt] = useState<number | null>(null)
  const [active, setActive] = useState(0)
  const listId = useId()

  const found = triggerAt(draft, caret)
  const trigger = found && found.start !== closedAt ? found : null
  const { groups, loading } = useOptions(trigger)
  const options = groups.flatMap((group) => group.options)
  const open = trigger !== null

  // A frame on: the panel may only now have come out, and what was clicked has just taken the focus.
  useEffect(() => {
    if (!focus) return
    const frame = requestAnimationFrame(() => input.current?.focus())
    return () => cancelAnimationFrame(frame)
  }, [focus])
  useEffect(() => setActive(0), [trigger?.char, trigger?.query])
  // Writing away from a closed picker opens it again the next time.
  useEffect(() => { if (!found) setClosedAt(null) }, [found])

  const write = (text: string, at: number) => {
    panel.draft(text)
    setCaret(at)
    requestAnimationFrame(() => {
      input.current?.focus()
      input.current?.setSelectionRange(at, at)
    })
  }
  const pick = (option: ComposerOption) => {
    if (!trigger) return
    const before = draft.slice(0, trigger.start)
    const after = draft.slice(trigger.start + 1 + trigger.query.length)
    if (option.insert !== undefined) {
      write(before + option.insert + after, before.length + option.insert.length)
      return
    }
    if (option.perform || option.skill) {
      write(before + after, before.length)
      if (option.perform) option.perform()
      else panel.set({ skills: { ...panel.get().skills, [key]: option } })
      return
    }
    // The message keeps its sense: "compare with @Blog". The chip is what carries it.
    const mention = `@${option.label} `
    write(before + mention + after, before.length + mention.length)
    panel.set({ attached: { ...panel.get().attached, [key]: [...(panel.get().attached[key] ?? []).filter((item) => item.id !== option.id), option] } })
  }
  const addContext = () => {
    const at = input.current?.selectionStart ?? draft.length
    const space = at > 0 && !/\s/.test(draft[at - 1]) ? ' ' : ''
    write(`${draft.slice(0, at)}${space}@${draft.slice(at)}`, at + space.length + 1)
  }
  const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.nativeEvent.isComposing) return
    if (open && options.length > 0) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault()
        setActive((index) => (index + (e.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length)
        return
      }
      if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault()
        pick(options[Math.min(active, options.length - 1)])
        return
      }
    }
    if (open && e.key === 'Escape') {
      e.preventDefault()
      setClosedAt(trigger.start)
      return
    }
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      onSubmit()
    }
  }

  return (
    <div className="relative">
      {open && <Picker id={listId} trigger={trigger} groups={groups} loading={loading} active={active} onActive={setActive} onPick={pick} />}
      <form onSubmit={(e) => { e.preventDefault(); onSubmit() }} className="cms-assistant-composer overflow-hidden rounded-lg border border-border bg-bg transition-colors focus-within:border-border-strong">
        <Attachments onAdd={addContext} />
        <Textarea
          ref={input}
          value={draft}
          onChange={(e) => { panel.draft(e.target.value); setCaret(e.target.selectionStart) }}
          onSelect={(e) => setCaret(e.currentTarget.selectionStart)}
          onKeyDown={onKeyDown}
          rows={2}
          placeholder={placeholder}
          aria-label={t('Message {brand}')}
          role="combobox"
          aria-autocomplete="list"
          aria-expanded={open}
          aria-controls={open ? listId : undefined}
          aria-activedescendant={open && options.length > 0 ? `${listId}-${active}` : undefined}
          className="max-h-52 min-h-0 resize-none border-0 bg-transparent px-3 pt-2.5 text-sm shadow-none focus:ring-0 focus-visible:outline-none"
        />
        {children}
      </form>
    </div>
  )
}
