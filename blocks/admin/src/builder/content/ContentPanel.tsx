import { navigate, t, useAbility, type FieldDef } from '@letterncms/sdk'
import { fieldControlId, Button, Fields, Icon, Tooltip, cn } from '@letterncms/ui'
import type { ReactNode } from 'react'
import { useAnchors } from '../../core/anchors'
import { useEditorContext } from '../../core/context'
import type { PatternDef } from '../../core/Inserter'
import * as tree from '../../core/tree'
import type { BlockNode } from '../../core/types'
import { AddButton, Section } from '../controls'
import { pairOp } from '../fields/composite'
import { useDataPaths, type DataPath } from './scope'

type Pattern = PatternDef & { id: string | null; props: FieldDef[] }

/** Field types that need the panel's full width (label above). */
const WIDE = ['richtext', 'markdown', 'code', 'json', 'textarea', 'media', 'repeater', 'tags', 'entries', 'group', 'properties', 'fields']

/** Text-like property types: an empty value shows the label as placeholder. */
const TEXTUAL = ['text', 'textarea', 'richtext', 'markdown']

/** Conditional fields (`if: { tag: 'button' }`), judged against the block's props and defaults. */
const applies = (field: FieldDef, props: Record<string, unknown>) =>
  !field.if || Object.entries(field.if as Record<string, unknown>).every(([handle, expected]) => {
    const value = props[handle] ?? null
    return Array.isArray(expected) ? expected.includes(value) : value === expected
  })

export { useAnchors }

/** One row: the label, the control, and a button to connect it to data instead. */
function Prop({ field, value, bound, paths, onValue, onBind, extra }: { field: FieldDef; value: unknown; bound?: string; paths: DataPath[]; onValue: (v: unknown) => void; onBind: (path: string | null) => void; extra?: Partial<FieldDef> }) {
  const wide = !bound && WIDE.includes(field.type) && !field.component
  const toggle = !bound && field.type === 'toggle'
  const def: FieldDef = bound
    ? { handle: field.handle, type: 'text', component: 'blocks-bind', label: field.label, paths }
    : { ...field, size: 'xs', width: 'full', ...(field.type === 'url' && !field.component ? { component: 'link' } : {}), ...extra }
  const control = <Fields layout={wide ? 'stacked' : 'inline'} bare fields={[def]} values={{ [field.handle]: bound ?? value }} onChange={(_, v) => (bound ? onBind(v) : onValue(v))} className="min-w-0 flex-1" />
  const connect = (
    <Tooltip content={bound ? t('Disconnect from Data') : t('Connect to Data')}>
      <button
        type="button"
        onClick={() => onBind(bound ? null : paths[0]?.path ?? 'entry.title')}
        aria-label={bound ? t('Disconnect from Data') : t('Connect to Data')}
        className={cn('grid size-5 shrink-0 place-items-center rounded-xs hover:bg-hover', bound ? 'text-focus' : 'text-faint opacity-0 hover:text-text focus-visible:opacity-100 group-hover/prop:opacity-100')}
      >
        <Icon name="b-database" size={11} />
      </button>
    </Tooltip>
  )
  const label = (
    <label htmlFor={fieldControlId(field)} className={cn('truncate text-2xs', bound ? 'text-focus' : 'text-muted')} title={field.instructions}>
      {field.label}
    </label>
  )

  if (wide) {
    return (
      <div className="group/prop space-y-1">
        <div className="flex h-5 items-center gap-1">
          <span className="flex-1 truncate">{label}</span>
          {connect}
        </div>
        {control}
      </div>
    )
  }

  return (
    <div className="group/prop flex min-h-7 items-center gap-2">
      {!toggle && <span className="w-20 shrink-0 truncate">{label}</span>}
      {control}
      {connect}
    </div>
  )
}

/** The Content tab: what the block shows (props, or data it is connected to), and element settings. */
export function ContentPanel({ node }: { node: BlockNode }) {
  const { store, types } = useEditorContext()
  const type = types.get(node.type)
  const paths = useDataPaths(node)
  const anchors = useAnchors().filter((a) => a.id !== (node.anchor ?? node.props?.anchor))
  const bind = node.bind ?? {}
  const props = { ...Object.fromEntries((type?.fields ?? []).map((f) => [f.handle, f.default ?? null])), ...(type?.defaults ?? {}), ...(node.props ?? {}) }
  const setBind = (key: string, path: string | null) => {
    const next = { ...bind }
    if (path) next[key] = path
    else delete next[key]
    store.updateNode(node.id, { bind: Object.keys(next).length ? next : undefined }, `bind:${node.id}:${key}`)
  }
  const setProp = (handle: string, v: unknown) => store.updateProps(node.id, { [handle]: v }, `inspector:${node.id}:${handle}`)

  const all = (type?.fields ?? []).filter((f) => !(f.type === 'json' && ['items', 'rows', 'values'].includes(f.handle)))
  // "Open in New Tab" lives in the link picker when the block has both.
  const merged = all.some((f) => f.component === 'link') && all.some((f) => f.handle === 'new_tab')
  const fields = all.filter((f) => !(merged && f.handle === 'new_tab') && applies(f, props))
  const linkExtra = (f: FieldDef): Partial<FieldDef> | undefined =>
    f.component === 'link' || f.type === 'url' ? { anchors, ...(merged ? { newTab: !!node.props?.new_tab, onNewTab: (on: boolean) => setProp('new_tab', on || undefined) } : {}) } : undefined

  return (
    <div>
      {node.type === 'pattern' ? (
        <ComponentInstance node={node} paths={paths} setBind={setBind} anchors={anchors} />
      ) : node.type === 'repeat' ? (
        <RepeatSource node={node} />
      ) : (
        <Section title={t('Content')} empty={fields.length === 0}>
          {fields.length === 0 && <p className="m-0 text-2xs text-faint">{t('Nothing to set here. Style it in Design.')}</p>}
          {fields.map((f) => (
            <Prop key={f.handle} field={f} value={node.props?.[f.handle]} bound={bind[f.handle]} paths={paths} onValue={(v) => setProp(f.handle, v)} onBind={(p) => setBind(f.handle, p)} extra={linkExtra(f)} />
          ))}
        </Section>
      )}

      <Section title={t('Visibility')} open={!!(bind.visible || bind.hidden || node.hidden)} empty={!bind.visible && !bind.hidden && !node.hidden}>
        <Fields
          layout="inline"
          fields={[
            { handle: 'shown', type: 'toggle', size: 'xs', label: t('Show on the Site') },
            { handle: 'visible', type: 'text', component: 'blocks-bind', label: t('Only If'), paths, placeholder: t('Always'), status: bind.visible ? 'own' : undefined, reset: () => setBind('visible', null), instructions: t('Shown only when this data has a value') },
            { handle: 'hidden', type: 'text', component: 'blocks-bind', label: t('Hide If'), paths, placeholder: t('Never'), status: bind.hidden ? 'own' : undefined, reset: () => setBind('hidden', null) },
          ]}
          values={{ shown: !node.hidden, visible: bind.visible, hidden: bind.hidden }}
          onChange={(h, v) => (h === 'shown' ? store.updateNode(node.id, { hidden: v ? undefined : true }) : setBind(h, v))}
        />
      </Section>

      <Section title={t('Element')} open={false} empty={!node.anchor && !node.attrs && !node.class}>
        <Fields
          layout="inline"
          fields={[
            { handle: 'label', type: 'text', size: 'xs', commit: 'blur', label: t('Layer Name'), placeholder: type?.title },
            { handle: 'anchor', type: 'text', size: 'xs', commit: 'blur', label: t('Anchor'), placeholder: t('None'), mono: true, instructions: t('Link to it with #anchor') },
            { handle: 'class', type: 'text', size: 'xs', commit: 'blur', label: t('Classes'), placeholder: t('None'), mono: true, instructions: t('Extra CSS classes, styled in custom CSS') },
          ]}
          values={{ label: node.label, anchor: node.anchor, class: node.class }}
          onChange={(h, v: string) => store.updateNode(node.id, { [h]: (h === 'anchor' ? v.toLowerCase().replace(/[^a-z0-9-]/g, '') : v) || undefined })}
        />
        <SubHeading title={t('Attributes')} action={<AddButton label={t('Add Attribute')} onClick={() => store.updateNode(node.id, { attrs: { ...(node.attrs ?? {}), 'aria-label': '' } })} />} />
        <Fields
          layout="inline"
          bare
          fields={[
            {
              handle: 'attrs',
              type: 'json',
              component: 'blocks-pairs',
              className: 'items-start',
              label: t('Attributes'),
              key: { lower: true },
              item: { handle: 'value', type: 'text', size: 'xs', commit: 'blur', label: t('Value') },
              items: Object.fromEntries(Object.keys(node.attrs ?? {}).filter((name) => bind[`attr:${name}`]).map((name) => [name, { handle: 'bind', type: 'text', component: 'blocks-bind', label: t('Data'), paths }])),
            },
          ]}
          values={{ attrs: Object.fromEntries(Object.entries(node.attrs ?? {}).map(([name, v]) => [name, bind[`attr:${name}`] ?? v])) }}
          onChange={(_, next) => {
            const attrs = node.attrs ?? {}
            const op = pairOp(Object.fromEntries(Object.entries(attrs).map(([name, v]) => [name, bind[`attr:${name}`] ?? v])), next)
            if (op?.op === 'rename') store.updateNode(node.id, { attrs: Object.fromEntries(Object.entries(attrs).map(([k, v]) => (k === op.from ? [op.to, v] : [k, v]))) })
            else if (op?.op === 'set' && bind[`attr:${op.key}`]) setBind(`attr:${op.key}`, (op.value as string | null) ?? null)
            else if (op?.op === 'set') store.updateNode(node.id, { attrs: { ...attrs, [op.key]: (op.value as string) ?? '' } })
            else if (op?.op === 'remove') {
              const rest = { ...attrs }
              delete rest[op.key]
              store.updateNode(node.id, { attrs: Object.keys(rest).length ? rest : undefined })
              setBind(`attr:${op.key}`, null)
            }
          }}
        />
        <SubHeading title={t('States from Data')} action={<AddButton label={t('Add State from Data')} onClick={() => setBind('state:active', paths[0]?.path ?? 'item.active')} />} />
        <Fields
          layout="inline"
          bare
          fields={[
            {
              handle: 'states',
              type: 'json',
              component: 'blocks-pairs',
              className: 'items-start',
              label: t('States from Data'),
              key: { prefix: 'is-', lower: true, strip: '[^a-z0-9-]', className: '!w-[30%] !flex-none' },
              item: { handle: 'path', type: 'text', component: 'blocks-bind', label: t('Data'), paths },
            },
          ]}
          values={{ states: Object.fromEntries(Object.entries(bind).filter(([k]) => k.startsWith('state:')).map(([k, path]) => [k.slice(6), path])) }}
          onChange={(_, next: Record<string, string | null>) => {
            const rest = Object.fromEntries(Object.entries(bind).filter(([k]) => !k.startsWith('state:')))
            const states = Object.fromEntries(Object.entries(next).filter(([, path]) => path).map(([name, path]) => [`state:${name}`, path as string]))
            const all = { ...rest, ...states }
            store.updateNode(node.id, { bind: Object.keys(all).length ? all : undefined }, `bind:${node.id}:states`)
          }}
        />
        <p className="m-0 select-all pt-1 font-mono text-[10px] text-faint">{node.id}</p>
      </Section>
    </div>
  )
}

/** A small heading inside a section, with an add button. */
export function SubHeading({ title, action }: { title: string; action?: ReactNode }) {
  return (
    <div className="flex h-6 items-center justify-between pt-1">
      <span className="text-2xs font-medium text-muted">{title}</span>
      {action}
    </div>
  )
}

/** A component instance: which component, its property values (or data for them). */
function ComponentInstance({ node, paths, setBind, anchors }: { node: BlockNode; paths: DataPath[]; setBind: (key: string, path: string | null) => void; anchors: { id: string; label: string }[] }) {
  const { store } = useEditorContext()
  const list = useAbility<Pattern[]>('patterns/list', { working: true }, { staleTime: 30_000 })
  const pattern = list.data?.find((p) => p.handle === node.props?.ref)
  const values = (node.props?.values ?? {}) as Record<string, unknown>
  const bind = node.bind ?? {}

  const detach = () => {
    if (!pattern) return
    const loc = tree.locate(store.get().nodes, node.id)
    if (!loc) return
    const copies = pattern.blocks.map((b) => tree.clone(b))
    store.commit(tree.insert(tree.remove(store.get().nodes, node.id), loc.parentId, loc.index, copies))
    store.select(copies[0]?.id ?? null)
  }

  return (
    <Section
      title={t('Component')}
      actions={
        pattern?.id ? (
          <Tooltip content={t('Edit Component')}>
            <button type="button" onClick={() => navigate(`/content/components/${pattern.id}`)} className="grid size-5 place-items-center rounded-xs text-muted hover:bg-hover hover:text-text" aria-label={t('Edit Component')}>
              <Icon name="b-pencil-simple" size={12} />
            </button>
          </Tooltip>
        ) : undefined
      }
    >
      <Fields
        layout="inline"
        fields={[{ handle: 'ref', type: 'select', size: 'xs', label: t('Component'), required: true, options: (list.data ?? []).filter((p) => p.synced).map((p) => ({ value: p.handle, label: p.title })) }]}
        values={{ ref: node.props?.ref }}
        onChange={(_, ref) => ref && store.updateProps(node.id, { ref, values: {} })}
      />
      {(pattern?.props ?? []).map((f) => (
        <Prop
          key={f.handle}
          field={{ ...f, placeholder: (f.placeholder as string | undefined) ?? (TEXTUAL.includes(f.type) ? f.label : undefined) }}
          value={values[f.handle] ?? f.default}
          bound={bind[`values.${f.handle}`]}
          paths={paths}
          onValue={(v) => store.updateProps(node.id, { values: { ...values, [f.handle]: v } }, `values:${node.id}:${f.handle}`)}
          onBind={(p) => setBind(`values.${f.handle}`, p)}
          extra={f.type === 'url' ? { component: 'link', reference: true, anchors } : undefined}
        />
      ))}
      {pattern && (pattern.props ?? []).length === 0 && <p className="m-0 text-2xs text-faint">{t('No properties. Add them in the component to vary each instance.')}</p>}
      <div className="pt-1">
        <Button size="xs" variant="ghost" icon="b-scissors" onClick={detach} disabled={!pattern}>
          {t('Detach from Component')}
        </Button>
      </div>
    </Section>
  )
}

/** Repeat: which list it loops over, and that source's settings. */
function RepeatSource({ node }: { node: BlockNode }) {
  const { store, catalog } = useEditorContext()
  const source = catalog.sources?.find((s) => s.name === node.props?.source)
  const set = (handle: string, v: unknown) => store.updateProps(node.id, { [handle]: v }, `repeat:${node.id}:${handle}`)

  return (
    <Section title={t('Repeat')}>
      <Fields
        layout="inline"
        fields={[{ handle: 'source', type: 'select', size: 'xs', label: t('List'), required: true, options: (catalog.sources ?? []).map((s) => ({ value: s.name, label: s.label })), instructions: source?.description }]}
        values={{ source: node.props?.source }}
        onChange={(_, v) => v && set('source', v)}
      />
      <Fields
        layout="inline"
        fields={(source?.fields ?? []).map((f) => ({ ...f, size: 'xs' as const, width: 'full' as const }))}
        values={Object.fromEntries((source?.fields ?? []).map((f) => [f.handle, node.props?.[f.handle]]))}
        onChange={(h, v) => set(h, v)}
      />
      <Fields
        layout="inline"
        fields={[
          { handle: 'tag', type: 'select', size: 'xs', label: t('HTML Tag'), required: true, options: ['div', 'ul', 'ol', 'section', 'nav'] },
          { handle: 'as', type: 'text', size: 'xs', commit: 'blur', label: t('Item Name'), placeholder: 'item', mono: true, instructions: t('For nested lists: inner blocks read it as name.title') },
          { handle: 'empty', type: 'text', size: 'xs', commit: 'blur', label: t('When Empty'), placeholder: t('Show Nothing') },
        ]}
        values={{ tag: node.props?.tag ?? 'div', as: node.props?.as, empty: node.props?.empty }}
        onChange={(h, v) => set(h, h === 'tag' ? v ?? 'div' : (h === 'as' ? String(v).replace(/[^a-z_]/gi, '') : v) || undefined)}
      />
    </Section>
  )
}
