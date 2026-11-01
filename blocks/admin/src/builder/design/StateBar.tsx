import { t } from '@letterncms/sdk'
import { Icon, Menu, cn, prompt } from '@letterncms/ui'
import { useEditorContext } from '../../core/context'
import { useEditor } from '../../core/store'
import * as tree from '../../core/tree'
import type { BlockNode } from '../../core/types'

const BASE_STATES = () => [
  { value: '', label: t('Default') },
  { value: 'hover', label: t('Hover') },
  { value: 'active', label: t('Pressed') },
  { value: 'focus-visible', label: t('Focus') },
]

const MORE = () => [
  { value: 'before', label: t('Before (::before)') },
  { value: 'after', label: t('After (::after)') },
  { value: 'placeholder', label: t('Placeholder') },
  { value: 'first', label: t('First Item') },
  { value: 'last', label: t('Last Item') },
  { value: 'odd', label: t('Odd Items') },
  { value: 'even', label: t('Even Items') },
  { value: 'current', label: t('Current Page (aria-current)') },
  { value: 'group-hover', label: t('Hover on Hover Group') },
  { value: 'dark', label: t('Dark Mode') },
  { value: 'open', label: t('Open (Details, Dialog)') },
]

/**
 * Which state the Design panel edits. Custom states come from scripts (is-open…)
 * on this element, and ancestors' states (in-open: "while the header is open").
 */
export function StateBar({ node }: { node: BlockNode }) {
  const { store } = useEditorContext()
  const state = useEditor(store, (s) => s.state)
  const breakpoint = useEditor(store, (s) => s.breakpoint)
  const ancestors = useEditor(store, (s) => tree.ancestors(s.nodes, node.id))

  const own = new Set<string>([
    ...Object.keys(node.logic?.states ?? {}).map((s) => `is-${s}`),
    ...Object.keys(node.bind ?? {}).filter((k) => k.startsWith('state:')).map((k) => `is-${k.slice(6)}`),
    ...(node.logic?.behaviors?.scroll ? ['is-scrolled', ...(node.logic.behaviors.scroll.hide ? ['is-hidden'] : [])] : []),
    ...Object.keys(node.style ?? {}).map((v) => v.split(':').pop()!).filter((v) => v.startsWith('is-') || v.startsWith('in-')),
  ])
  const inherited = new Set<string>()
  for (const a of ancestors) {
    Object.keys(a.logic?.states ?? {}).forEach((s) => inherited.add(`in-${s}`))
    if (a.logic?.behaviors?.scroll) inherited.add('in-scrolled')
  }
  const custom = [...own, ...inherited].filter((v, i, all) => all.indexOf(v) === i)
  const label = (v: string) => (v.startsWith('is-') ? v.slice(3) : v.startsWith('in-') ? t('in {state}', { state: v.slice(3) }) : MORE().find((m) => m.value === v)?.label ?? v)
  const set = (v: string) => store.setStyleState(v)
  const used = (v: string) => Object.keys(node.style ?? {}).some((k) => k === v || k.endsWith(`:${v}`))
  const name = async () => {
    const values = await prompt({
      title: t('Custom State'),
      description: t('A state a script, behavior or data sets (is-open). Style it here, set it in Interact → States.'),
      fields: [{ handle: 'name', type: 'text', label: t('Name'), placeholder: 'open, active…', mono: true }],
      confirm: t('Style It'),
    })
    const clean = String(values?.name ?? '').trim().toLowerCase().replace(/[^a-z0-9-]/g, '')
    if (clean) set(`is-${clean}`)
  }

  const states = [...BASE_STATES(), ...custom.map((v) => ({ value: v, label: label(v) })), ...(state && !BASE_STATES().some((b) => b.value === state) && !custom.includes(state) ? [{ value: state, label: label(state) }] : [])]
  const where = breakpoint === 'base' ? t('All Screens') : breakpoint === 'tablet' ? t('Tablets and Phones') : breakpoint === 'mobile' ? t('Phones') : breakpoint

  return (
    <div className="border-b border-border px-3 py-2">
      <div className="flex items-start gap-1">
        <div className="flex min-w-0 flex-1 flex-wrap gap-0.5" role="radiogroup" aria-label={t('State')}>
          {states.map((st) => (
            <button
              key={st.value || 'default'}
              type="button"
              role="radio"
              aria-checked={state === st.value}
              onClick={() => set(st.value)}
              className={cn('relative h-6 rounded-xs px-2 text-2xs transition-colors duration-120', state === st.value ? 'bg-active font-medium text-ink' : 'text-muted hover:bg-hover hover:text-text')}
            >
              {st.label}
              {st.value && used(st.value) && state !== st.value && <span className="absolute right-0.5 top-1 size-1 rounded-full bg-focus" />}
            </button>
          ))}
        </div>
        <Menu
          align="end"
          trigger={
            <button type="button" className="grid size-6 shrink-0 place-items-center rounded-xs text-muted hover:bg-hover hover:text-text" aria-label={t('More States')} title={t('More States')}>
              <Icon name="b-plus" size={12} />
            </button>
          }
          items={[
            ...MORE().map((m) => ({ label: m.label, onSelect: () => set(m.value), checked: state === m.value })),
            { separator: true, label: '' },
            { label: t('Custom State…'), onSelect: () => void name() },
          ]}
        />
      </div>
      {(breakpoint !== 'base' || state) && (
        <div className="mt-1.5 flex h-6 items-center gap-1.5 rounded-xs bg-focus/8 pl-2 pr-1 text-2xs text-focus">
          <Icon name={breakpoint === 'mobile' ? 'b-device-mobile' : breakpoint === 'tablet' ? 'b-device-tablet' : 'b-monitor'} size={12} className="shrink-0" />
          <span className="min-w-0 flex-1 truncate">
            {state ? t('{screens} · {state}', { screens: where, state: label(state) }) : where}
          </span>
          {state && (
            <button type="button" onClick={() => set('')} className="grid size-5 shrink-0 place-items-center rounded-xs hover:bg-focus/12" aria-label={t('Back to Default State')} title={t('Back to Default State')}>
              <Icon name="b-x" size={10} />
            </button>
          )}
        </div>
      )}
    </div>
  )
}
