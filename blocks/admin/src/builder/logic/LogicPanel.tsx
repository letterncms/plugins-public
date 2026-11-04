import { t, type FieldDef } from '@letterncms/sdk'
import { Icon, Menu, cn } from '@letterncms/ui'
import { useEditorContext } from '../../core/context'
import { useEditor } from '../../core/store'
import * as tree from '../../core/tree'
import type { BlockNode, LogicAction, LogicMap } from '../../core/types'
import { useAnchors } from '../content/ContentPanel'
import { AddButton, PanelFields, RemoveButton, Section, SubHeading, inputClass } from '../controls'

const EVENTS = () => [
  { value: 'click', label: t('Click') },
  { value: 'mouseenter', label: t('Pointer Enters') },
  { value: 'mouseleave', label: t('Pointer Leaves') },
  { value: 'focus', label: t('Gets Focus') },
  { value: 'blur', label: t('Loses Focus') },
  { value: 'input', label: t('Typing') },
  { value: 'change', label: t('Value Changes') },
  { value: 'submit', label: t('Form Submit') },
  { value: 'keydown', label: t('Key Pressed') },
  { value: 'enter', label: t('Scrolls into View') },
  { value: 'leave', label: t('Scrolls Out of View') },
  { value: 'scroll', label: t('Page Scrolls') },
  { value: 'resize', label: t('Window Resizes') },
  { value: 'init', label: t('Page Loads') },
]

const MODS = () => [
  { value: 'prevent', label: t('Prevent Default') },
  { value: 'stop', label: t('Stop Propagation') },
  { value: 'outside', label: t('Outside This Element') },
  { value: 'window', label: t('Anywhere on the Page') },
  { value: 'once', label: t('Only Once') },
  { value: 'self', label: t('On This Element Only') },
  { value: 'debounce', label: t('Debounce') },
]

const ACTIONS = (): { value: string; label: string; icon: string }[] => [
  { value: 'set', label: t('Set State'), icon: 'b-pencil-simple' },
  { value: 'toggle', label: t('Toggle State'), icon: 'b-toggle-left' },
  { value: 'add', label: t('Add to Number'), icon: 'b-plus' },
  { value: 'if', label: t('If … Then … Else'), icon: 'b-git-branch' },
  { value: 'play', label: t('Play Animation'), icon: 'b-play' },
  { value: 'scroll', label: t('Scroll To'), icon: 'b-mouse-scroll' },
  { value: 'fetch', label: t('Load Page Part'), icon: 'b-arrow-square-out' },
  { value: 'copy', label: t('Copy to Clipboard'), icon: 'b-copy' },
  { value: 'go', label: t('Go to Link'), icon: 'b-link' },
  { value: 'theme', label: t('Light or Dark Mode'), icon: 'b-moon' },
  { value: 'focus', label: t('Focus Element'), icon: 'b-crosshair' },
  { value: 'lock', label: t('Lock Page Scrolling'), icon: 'b-lock-simple' },
  { value: 'wait', label: t('Wait'), icon: 'b-timer' },
  { value: 'dispatch', label: t('Send Event'), icon: 'b-broadcast' },
  { value: 'run', label: t('Expression'), icon: 'b-brackets-curly' },
]

/** Variables in scope for an element: its own state and every ancestor's. */
function useVariables(node: BlockNode): string[] {
  const { store } = useEditorContext()
  const ancestors = useEditor(store, (s) => tree.ancestors(s.nodes, node.id))
  return [...new Set([...ancestors, node].flatMap((n) => Object.keys(n.logic?.data ?? {})))]
}

function parseLiteral(text: string): unknown {
  const v = text.trim()
  if (v === 'true' || v === 'false') return v === 'true'
  if (v === '' || v === 'null') return null
  if (/^-?\d*\.?\d+$/.test(v)) return Number(v)
  if (/^[[{"]/.test(v)) {
    try {
      return JSON.parse(v)
    } catch {
      return v
    }
  }
  return v.replace(/^'(.*)'$/, '$1')
}

const literal = (v: unknown) => (typeof v === 'string' ? `'${v}'` : JSON.stringify(v))

/** An Alpine expression field; the panel's datalist suggests the element's variables. */
const expr = (handle: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({
  handle,
  type: 'text',
  size: 'sm',
  commit: 'blur',
  label,
  mono: true,
  max: 10000,
  list: VARIABLES,
  placeholder: "open, count > 3, tab === 'a'",
  ...extra,
})

const VARIABLES = 'cms-logic-variables'

/** Fields shown only when `on`. */
const when = (on: unknown, ...fields: FieldDef[]): FieldDef[] => (on ? fields : [])

const ATTRS = ['aria-expanded', 'aria-pressed', 'aria-selected', 'aria-hidden', 'aria-current', 'hidden', 'disabled', 'inert', 'href', 'src', 'title', 'value', 'tabindex']

/** Scripts: state, events and actions, conditional display, states for styling, behaviors. */
export function LogicPanel({ node }: { node: BlockNode }) {
  const { store, types } = useEditorContext()
  const logic: LogicMap = node.logic ?? {}
  const variables = useVariables(node)
  const save = (patch: Partial<LogicMap>) => {
    const next: LogicMap = { ...logic, ...patch }
    for (const k of Object.keys(next) as (keyof LogicMap)[]) {
      const v = next[k]
      if (v === undefined || v === '' || (Array.isArray(v) && v.length === 0) || (v && typeof v === 'object' && !Array.isArray(v) && Object.keys(v).length === 0)) delete next[k]
    }
    store.updateNode(node.id, { logic: Object.keys(next).length ? next : undefined }, `logic:${node.id}`)
  }
  const data = logic.data ?? {}
  const linkish = ['link', 'button', 'box'].includes(node.type) || (types.get(node.type)?.html ?? '') === 'a'
  const behaviors = logic.behaviors ?? {}

  return (
    <>
      <datalist id={VARIABLES}>{[...variables, ...variables.map((v) => `!${v}`)].map((v) => <option key={v} value={v} />)}</datalist>
      <Section title={t('State')} empty={!logic.data} open={!!logic.data} actions={<AddButton label={t('Add Variable')} onClick={() => { let n = 'open'; let i = 1; while (n in data) n = `value${i++}`; save({ data: { ...data, [n]: n === 'open' ? false : '' } }) }} />}>
        <PanelFields
          bare
          fields={[{ handle: 'data', type: 'json', component: 'blocks-pairs', className: 'items-start', label: t('Variables'), key: { strip: '[^a-zA-Z0-9_]' }, join: '=', item: { handle: 'value', type: 'text', size: 'sm', commit: 'blur', label: t('Value'), mono: true } }]}
          values={{ data: Object.fromEntries(Object.entries(data).map(([k, v]) => [k, literal(v)])) }}
          onChange={(_, next: Record<string, string | null>) => save({ data: Object.fromEntries(Object.entries(next).map(([k, text]) => [k, k in data && literal(data[k]) === text ? data[k] : parseLiteral(text ?? '')])) })}
        />
      </Section>

      <Section title={t('Code Component')} open={!!logic.component} empty={!logic.component}>
        <PanelFields
          fields={[{ handle: 'component', type: 'text', size: 'sm', commit: 'blur', label: t('Alpine Name'), placeholder: 'pricingCalculator', mono: true, status: logic.component ? 'own' : undefined, reset: () => save({ component: undefined }), instructions: t('An Alpine component from the site’s custom JavaScript. Replaces State.') }]}
          values={{ component: logic.component }}
          onChange={(_, v) => save({ component: String(v ?? '').replace(/[^a-zA-Z0-9_$]/g, '') || undefined })}
        />
      </Section>

      <Section title={t('Events')} empty={!logic.on?.length && !logic.init?.length} open={!!logic.on?.length || !!logic.init?.length} actions={<AddButton label={t('Add Event')} onClick={() => save({ on: [...(logic.on ?? []), { event: 'click', actions: [] }] })} />}>
        {logic.init && (
          <Handler
            title={t('Page Loads')}
            actions={logic.init}
            variables={variables}
            node={node}
            onActions={(actions) => save({ init: actions })}
            onRemove={() => save({ init: undefined })}
          />
        )}
        {(logic.on ?? []).map((h, i) => {
          const set = (patch: Partial<typeof h>) => save({ on: logic.on!.map((x, j) => (j === i ? { ...x, ...patch } : x)) })
          return (
            <Handler
              key={i}
              title={
                <PanelFields
                  bare
                  className="min-w-0 flex-1"
                  fields={[
                    {
                      handle: 'trigger',
                      type: 'group',
                      component: 'blocks-group',
                      label: t('Event'),
                      fields: [
                        { handle: 'event', type: 'select', size: 'sm', label: t('Event'), required: true, options: EVENTS().filter((e) => e.value !== 'init') },
                        { handle: 'key', type: 'text', size: 'sm', commit: 'blur', label: t('Keyboard Key'), placeholder: 'escape', mono: true, className: '!w-20 !flex-none', if: { event: ['keydown', 'keyup'] } },
                      ],
                    },
                  ]}
                  values={{ trigger: { event: h.event, key: h.key } }}
                  onChange={(_, v) => v.event !== 'init' && set({ event: v.event ?? 'click', key: v.key || undefined })}
                />
              }
              mods={h.mods}
              onMods={(mods) => set({ mods: mods.length ? mods : undefined })}
              actions={h.actions}
              variables={variables}
              node={node}
              onActions={(actions) => set({ actions })}
              onRemove={() => save({ on: logic.on!.filter((_, j) => j !== i) })}
            />
          )
        })}
        {!logic.init && (logic.on?.length ?? 0) > 0 && (
          <button type="button" onClick={() => save({ init: [] })} className="flex h-7 w-full items-center gap-1.5 rounded-sm px-1.5 text-xs text-muted hover:bg-hover hover:text-ink">
            <Icon name="b-plus" size={14} /> {t('When the Page Loads')}
          </button>
        )}
      </Section>

      <Section title={t('Bindings')} empty={!logic.show && !logic.states && !logic.text && !logic.attrs} open={!!(logic.show || logic.states || logic.text || logic.attrs)}>
        <PanelFields
          fields={[
            expr('show', t('Show When'), { placeholder: t('Always'), status: logic.show ? 'own' : undefined, reset: () => save({ show: undefined, transition: undefined }) }),
            ...when(logic.show, { handle: 'transition', type: 'select', display: 'segmented', block: true, size: 'sm', label: t('Transition'), options: [{ value: 'none', label: t('None') }, { value: 'fade', label: t('Fade') }, { value: 'scale', label: t('Scale') }, { value: 'collapse', label: t('Collapse') }] }),
          ]}
          values={{ show: logic.show, transition: logic.transition ?? 'none' }}
          onChange={(h, v) => save(h === 'show' ? { show: v || undefined } : { transition: v && v !== 'none' ? v : undefined })}
        />
        <SubHeading title={t('States')} action={<AddButton label={t('Add State')} onClick={() => save({ states: { ...(logic.states ?? {}), [Object.keys(logic.states ?? {}).length ? `state${Object.keys(logic.states ?? {}).length + 1}` : 'open']: variables[0] ?? 'open' } })} />} />
        <PanelFields
          bare
          fields={[{ handle: 'states', type: 'json', component: 'blocks-pairs', className: 'items-start', label: t('States'), key: { prefix: 'is-', lower: true, strip: '[^a-z0-9-]', className: '!w-[34%] !flex-none' }, join: t('when'), item: expr('expr', t('When')) }]}
          values={{ states: logic.states }}
          onChange={(_, states) => save({ states })}
        />
        <PanelFields
          fields={[expr('text', t('Text'), { placeholder: t('As Designed'), status: logic.text ? 'own' : undefined, reset: () => save({ text: undefined }), instructions: t('Replace the text with a value, e.g. count') })]}
          values={{ text: logic.text }}
          onChange={(_, v) => save({ text: v || undefined })}
        />
        <SubHeading title={t('Attributes')} action={<AddButton label={t('Bind Attribute')} onClick={() => save({ attrs: { ...(logic.attrs ?? {}), 'aria-expanded': variables[0] ?? 'open' } })} />} />
        <PanelFields
          bare
          fields={[{ handle: 'attrs', type: 'json', component: 'blocks-pairs', className: 'items-start', label: t('Attributes'), key: { options: ATTRS, className: '' }, item: expr('expr', t('Value')) }]}
          values={{ attrs: logic.attrs }}
          onChange={(_, attrs) => save({ attrs })}
        />
        {node.type === 'input' && (
          <PanelFields
            fields={[{ handle: 'model', type: 'select', size: 'sm', label: t('Input Value'), placeholder: t('Not kept'), options: variables, status: logic.model ? 'own' : undefined, reset: () => save({ model: undefined }), instructions: t('Keep a variable in sync with what is typed') }]}
            values={{ model: logic.model }}
            onChange={(_, v) => save({ model: v || undefined })}
          />
        )}
      </Section>

      <Section title={t('Behaviors')} empty={!logic.behaviors} open={!!logic.behaviors}>
        <Behavior
          on={!!behaviors.scroll}
          label={t('Scroll States')}
          help={t('Adds is-scrolled once the page scrolls, for headers that change.')}
          onToggle={(on) => save({ behaviors: { ...behaviors, scroll: on ? { threshold: 20 } : undefined } })}
          fields={[
            { handle: 'threshold', type: 'text', component: 'blocks-value', label: t('After'), unit: '', prefix: 'px' },
            { handle: 'hide', type: 'toggle', size: 'sm', label: t('Add is-hidden While Scrolling Down') },
          ]}
          values={{ threshold: String(behaviors.scroll?.threshold ?? 20), hide: !!behaviors.scroll?.hide }}
          onChange={(h, v) => save({ behaviors: { ...behaviors, scroll: { ...behaviors.scroll, [h]: h === 'threshold' ? Number(v ?? 20) : v } } })}
        />
        <Behavior on={!!behaviors.spy} label={t('Scroll Spy')} help={t('Links to #sections inside get is-current while in view.')} onToggle={(on) => save({ behaviors: { ...behaviors, spy: on ? { offset: 96 } : undefined } })} />
        <Behavior on={!!behaviors.group} label={t('Hover Group')} help={t('Children react when this whole element is hovered.')} onToggle={(on) => save({ behaviors: { ...behaviors, group: on || undefined } })} />
        <Behavior on={!!behaviors.menu} label={t('Menu')} help={t('One panel moves between items: each child is a link or button, with an optional panel after it.')} onToggle={(on) => save({ behaviors: { ...behaviors, menu: on ? {} : undefined } })} />
        <Behavior on={!!behaviors.carousel} label={t('Carousel')} help={t('The first child scrolls sideways. The first two buttons after it go back and forward.')} onToggle={(on) => save({ behaviors: { ...behaviors, carousel: on ? {} : undefined } })} />
        <Behavior
          on={!!behaviors.marquee}
          label={t('Marquee')}
          help={t('Children scroll sideways in an endless loop.')}
          onToggle={(on) => save({ behaviors: { ...behaviors, marquee: on ? { speed: 60 } : undefined } })}
          fields={[
            { handle: 'speed', type: 'text', component: 'blocks-value', label: t('Speed'), unit: '', prefix: 'px/s', title: t('Pixels per Second') },
            { handle: 'reverse', type: 'toggle', size: 'sm', label: t('Reverse') },
            { handle: 'pause', type: 'toggle', size: 'sm', label: t('Pause on Hover') },
          ]}
          values={{ speed: String(behaviors.marquee?.speed ?? 60), reverse: !!behaviors.marquee?.reverse, pause: !!behaviors.marquee?.pause }}
          onChange={(h, v) => save({ behaviors: { ...behaviors, marquee: { ...behaviors.marquee, [h]: h === 'speed' ? Number(v ?? 60) : v } } })}
        />
      </Section>

      {linkish && (
        <Section title={t('Load Into')} open={!!logic.target} empty={!logic.target && !logic.merge}>
          <PanelFields
            fields={[
              { handle: 'target', type: 'json', component: 'blocks-nodes', label: t('Update'), exclude: node.id, add: t('Element to Update'), addValue: node.id, instructions: t('Load the link in the background and swap only these elements') },
              ...when(logic.target, { handle: 'push', type: 'toggle', size: 'sm', label: t('Update the Address Bar') }),
              { handle: 'merge', type: 'select', size: 'sm', label: t('When Loaded'), placeholder: t('Replace It'), instructions: t('How this element takes new content loaded into it'), options: [{ value: 'replace', label: t('Replace It') }, { value: 'update', label: t('Replace What Is Inside') }, { value: 'append', label: t('Add to the End') }, { value: 'prepend', label: t('Add to the Start') }, { value: 'morph', label: t('Morph (Keep State)') }] },
            ]}
            values={{ target: logic.target, push: !!logic.push, merge: logic.merge }}
            onChange={(h, v) => save({ [h]: h === 'push' ? v || undefined : v ?? undefined })}
          />
        </Section>
      )}
    </>
  )
}

/** A behavior switched on and off, with its settings while on. */
function Behavior({ on, label, help, onToggle, fields, values, onChange }: { on: boolean; label: string; help: string; onToggle: (on: boolean) => void; fields?: FieldDef[]; values?: Record<string, any>; onChange?: (handle: string, v: any) => void }) {
  return (
    <div className={cn('-mx-1.5 rounded-sm px-1.5 py-1', on && 'bg-sunken/60')}>
      <PanelFields fields={[{ handle: 'on', type: 'toggle', size: 'sm', label, instructions: help }]} values={{ on }} onChange={(_, v) => onToggle(!!v)} />
      {on && fields && <PanelFields className="mt-1 space-y-1 pl-10" fields={fields} values={values ?? {}} onChange={onChange ?? (() => {})} />}
    </div>
  )
}

function Handler({ title, mods, onMods, actions, onActions, onRemove, variables, node }: { title: React.ReactNode; mods?: string[]; onMods?: (mods: string[]) => void; actions: LogicAction[]; onActions: (a: LogicAction[]) => void; onRemove: () => void; variables: string[]; node: BlockNode }) {
  return (
    <div className="space-y-1.5 rounded-sm border border-border p-1.5">
      <div className="flex items-center gap-1">
        <Icon name="b-lightning" size={14} className="shrink-0 text-muted" />
        {typeof title === 'string' ? <span className="min-w-0 flex-1 text-xs font-medium [overflow-wrap:anywhere]">{title}</span> : title}
        {onMods && (
          <Menu
            align="end"
            trigger={<button type="button" className={cn('grid size-6 shrink-0 place-items-center rounded-sm hover:bg-hover hover:text-ink', mods?.length ? 'text-ink' : 'text-muted')} aria-label={t('Event Options')}><Icon name="b-faders" size={14} /></button>}
            items={MODS().map((m) => ({ label: m.label, checked: mods?.includes(m.value), onSelect: () => onMods(mods?.includes(m.value) ? mods.filter((x) => x !== m.value) : [...(mods ?? []), m.value]) }))}
          />
        )}
        <RemoveButton onClick={onRemove} label={t('Remove Event')} />
      </div>
      {mods && mods.length > 0 && <p className="m-0 pl-5 text-2xs text-faint [overflow-wrap:anywhere]">{mods.map((m) => MODS().find((x) => x.value === m)?.label).join(' · ')}</p>}
      <Actions actions={actions} onChange={onActions} variables={variables} node={node} />
    </div>
  )
}

function Actions({ actions, onChange, variables, node, depth = 0 }: { actions: LogicAction[]; onChange: (a: LogicAction[]) => void; variables: string[]; node: BlockNode; depth?: number }) {
  const add = (kind: string) => {
    const defaults: Record<string, LogicAction> = {
      set: { do: 'set', var: variables[0] ?? 'open', value: 'true' },
      toggle: { do: 'toggle', var: variables[0] ?? 'open' },
      add: { do: 'add', var: variables[0] ?? 'count', value: '1' },
      if: { do: 'if', test: variables[0] ?? 'open', then: [], else: [] },
      play: { do: 'play', node: node.id, mode: 'play' },
      scroll: { do: 'scroll', to: 'top' },
      fetch: { do: 'fetch', url: '/', target: [] },
      copy: { do: 'copy', value: "'Text'" },
      go: { do: 'go', url: '/' },
      theme: { do: 'theme', mode: 'toggle' },
      focus: { do: 'focus', node: node.id },
      lock: { do: 'lock', on: true },
      wait: { do: 'wait', ms: 300 },
      dispatch: { do: 'dispatch', event: 'changed' },
      run: { do: 'run', expr: '' },
    }
    onChange([...actions, defaults[kind]])
  }
  const set = (i: number, patch: Partial<LogicAction>) => onChange(actions.map((a, j) => (j === i ? { ...a, ...patch } : a)))

  return (
    <div className={cn('space-y-1', depth > 0 && 'border-l border-border pl-2')}>
      {actions.map((a, i) => (
        <div key={i} className="space-y-1 rounded-md bg-active/60 p-1.5">
          <div className="flex items-center gap-1">
            <Icon name={ACTIONS().find((x) => x.value === a.do)?.icon ?? 'b-dot-outline'} size={14} className="shrink-0 text-muted" />
            <span className="min-w-0 flex-1 text-xs font-medium text-text [overflow-wrap:anywhere]">{ACTIONS().find((x) => x.value === a.do)?.label ?? a.do}</span>
            <RemoveButton onClick={() => onChange(actions.filter((_, j) => j !== i))} label={t('Remove Action')} />
          </div>
          <ActionFields action={a} onChange={(patch) => set(i, patch)} variables={variables} node={node} depth={depth} />
        </div>
      ))}
      <Menu
        align="start"
        trigger={<button type="button" className="flex h-7 w-full items-center gap-1.5 rounded-sm px-1.5 text-xs text-muted hover:bg-hover hover:text-ink"><Icon name="b-plus" size={14} /> {t('Add Action')}</button>}
        items={ACTIONS().map((x) => ({ label: x.label, icon: x.icon, onSelect: () => add(x.value) }))}
      />
    </div>
  )
}

const scrollKind = (to?: string) => (to === 'top' || to === 'bottom' ? to : to?.startsWith('#') ? 'anchor' : 'node')

/** One action's settings, as fields; `apply` turns a field's change into the action's patch. */
function ActionFields({ action: a, onChange, variables, node, depth }: { action: LogicAction; onChange: (patch: Partial<LogicAction>) => void; variables: string[]; node: BlockNode; depth: number }) {
  const { store } = useEditorContext()
  const anchors = useAnchors()
  const target = useEditor(store, (s) => (a.do === 'play' && a.node ? tree.find(s.nodes, a.node) : null))
  const variable: FieldDef = { handle: 'var', type: 'select', size: 'sm', label: t('Variable'), options: variables, placeholder: t('Variable') }
  const element = (handle: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ handle, type: 'select', component: 'blocks-node', label, ...extra })
  const seg = (handle: string, label: string, options: { value: string; label: string }[]): FieldDef => ({ handle, type: 'select', display: 'segmented', block: true, size: 'sm', label, options, required: true })

  let fields: FieldDef[] = []
  let values: Record<string, any> = a
  let apply: (handle: string, v: any) => Partial<LogicAction> = (handle, v) => ({ [handle]: v ?? '' })

  switch (a.do) {
    case 'set':
    case 'add':
      fields = [variable, expr('value', a.do === 'set' ? t('To') : t('Add'), { placeholder: a.do === 'set' ? "true, 'text', count + 1" : '1' })]
      break
    case 'toggle':
      fields = [variable]
      break
    case 'if':
      fields = [expr('test', t('If'), { placeholder: 'open' })]
      break
    case 'play':
      fields = [
        element('node', t('Element')),
        { handle: 'motion', type: 'select', size: 'sm', label: t('Animation'), options: (target?.motion ?? []).map((m) => ({ value: m.id, label: m.name || m.id })), placeholder: target?.motion?.length ? t('First Animation') : t('No animations on it') },
        seg('mode', t('Play'), [{ value: 'play', label: t('Play') }, { value: 'reverse', label: t('Reverse') }, { value: 'toggle', label: t('Toggle') }, { value: 'restart', label: t('Restart') }]),
      ]
      values = { ...a, mode: a.mode ?? 'play' }
      apply = (h, v) => (h === 'node' ? { node: v ?? node.id, motion: undefined } : h === 'motion' ? { motion: v ?? undefined } : { mode: v ?? 'play' })
      break
    case 'scroll':
      fields = [
        { handle: 'kind', type: 'select', size: 'sm', label: t('To'), required: true, options: [{ value: 'top', label: t('Top of Page') }, { value: 'bottom', label: t('Bottom of Page') }, { value: 'anchor', label: t('Section') }, { value: 'node', label: t('Element') }] },
        { handle: 'anchor', type: 'select', size: 'sm', label: t('Section'), options: anchors.map((x) => ({ value: `#${x.id}`, label: x.label })), placeholder: t('Choose a Section'), if: { kind: 'anchor' } },
        element('node', t('Element'), { if: { kind: 'node' } }),
        { handle: 'offset', type: 'text', component: 'blocks-value', label: t('Offset'), unit: '', prefix: 'px', placeholder: '0', title: t('Offset in pixels, e.g. a sticky header’s height') },
      ]
      values = { kind: scrollKind(a.to), anchor: a.to, node: a.to, offset: a.offset ? String(a.offset) : undefined }
      apply = (h, v) => (h === 'kind' ? { to: v === 'anchor' ? `#${anchors[0]?.id ?? 'top'}` : v === 'node' ? node.id : v ?? 'top' } : h === 'anchor' ? { to: v } : h === 'node' ? { to: v ?? 'top' } : { offset: v ? parseInt(v) : undefined })
      break
    case 'fetch':
      fields = [
        { handle: 'url', type: 'text', size: 'sm', commit: 'blur', label: t('Page'), placeholder: '/blog/page/2', mono: true },
        { handle: 'target', type: 'json', component: 'blocks-nodes', label: t('Update'), instructions: t('Elements replaced with the same ones from that page'), addValue: node.id },
      ]
      break
    case 'copy':
      fields = [expr('value', t('Text'), { placeholder: `'${t('Text to Copy')}'` })]
      break
    case 'go':
      fields = [{ handle: 'url', type: 'url', component: 'link', reference: true, size: 'sm', label: t('Link'), anchors, newTab: !!a.newTab, onNewTab: (on: boolean) => onChange({ newTab: on || undefined }) }]
      apply = (_, v) => ({ url: v ?? '' })
      break
    case 'theme':
      fields = [seg('mode', t('Mode'), [{ value: 'toggle', label: t('Switch') }, { value: 'light', label: t('Light') }, { value: 'dark', label: t('Dark') }, { value: 'system', label: t('System') }])]
      values = { mode: a.mode ?? 'toggle' }
      break
    case 'focus':
      fields = [element('node', t('Element'))]
      apply = (_, v) => ({ node: v ?? undefined })
      break
    case 'lock':
      fields = [seg('on', t('Scrolling'), [{ value: 'on', label: t('Lock') }, { value: 'off', label: t('Unlock') }])]
      values = { on: a.on === false ? 'off' : 'on' }
      apply = (_, v) => ({ on: v !== 'off' })
      break
    case 'wait':
      fields = [{ handle: 'ms', type: 'text', component: 'blocks-value', label: t('Wait'), unit: '', step: 50, min: 0, prefix: 'ms' }]
      values = { ms: String(a.ms ?? 300) }
      apply = (_, v) => ({ ms: Number(v ?? 300) })
      break
    case 'dispatch':
      fields = [
        { handle: 'event', type: 'text', size: 'sm', commit: 'blur', label: t('Event'), placeholder: 'event-name', mono: true },
        expr('detail', t('Detail'), { placeholder: 'detail' }),
      ]
      apply = (h, v) => ({ [h]: h === 'detail' ? v || undefined : v ?? '' })
      break
    case 'run':
      fields = [expr('expr', t('Run'), { placeholder: 'count = count * 2' })]
      break
    default:
      return <p className={cn(inputClass, 'h-auto whitespace-pre-wrap font-mono [overflow-wrap:anywhere]')}>{JSON.stringify(a)}</p>
  }

  return (
    <div className="space-y-1">
      <PanelFields fields={fields} values={values} onChange={(h, v) => onChange(apply(h, v))} />
      {a.do === 'if' && (
        <>
          <p className="cms-label m-0 pt-1">{t('Then')}</p>
          <Actions actions={a.then ?? []} onChange={(then) => onChange({ then })} variables={variables} node={node} depth={depth + 1} />
          <p className="cms-label m-0 pt-1">{t('Else')}</p>
          <Actions actions={a.else ?? []} onChange={(otherwise) => onChange({ else: otherwise })} variables={variables} node={node} depth={depth + 1} />
        </>
      )}
    </div>
  )
}
