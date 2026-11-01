import { navigate, t, useAbility, type FieldDef } from '@letterncms/sdk'
import { Button, Fields, Icon, IconButton, Popover, cn } from '@letterncms/ui'
import { useState } from 'react'
import { useEditorContext } from '../core/context'
import { InlineEditable } from '../core/InlineEditable'
import { insertionPoint } from '../core/insert'
import { useAnchors } from '../core/anchors'
import { linkField } from '../core/link'
import { useEditor } from '../core/store'
import * as tree from '../core/tree'
import type { BlockNode, BlockTypeDef } from '../core/types'
import { useWrite } from './doc'

/** Inline views for media, actions, tables, components and plugin blocks. */

export function Divider() {
  return <hr className="my-3 border-0 border-t border-border-strong" />
}

// ── Buttons and links ─────────────────────────────────────

/** Buttons and links that sit together, each with its label editable and its link in a picker. */
export function Actions({ nodes }: { nodes: BlockNode[] }) {
  const { store, types, allowed } = useEditorContext()
  const { readOnly } = useWrite()
  const last = nodes[nodes.length - 1]

  const add = () => {
    const doc = store.get().nodes
    const loc = insertionPoint(doc, last.id, 'button', types, allowed)
    const def = types.get('button')
    if (!loc || !def) return
    const button = tree.create(def, { label: '', variant: last.type === 'button' && (last.props.variant ?? 'primary') === 'primary' ? 'secondary' : last.props.variant ?? 'primary' }, types)
    store.insert(loc.parentId, loc.index, [button])
    store.focus(button.id, 'end', 'label')
  }

  return (
    <div className="group/actions flex flex-wrap items-center gap-x-3 gap-y-2 py-1">
      {nodes.map((node) => (
        <Action key={node.id} node={node} />
      ))}
      {!readOnly && last.type === 'button' && types.has('button') && (
        <IconButton icon="plus" label={t('Add a Button')} size="xs" className="opacity-0 transition-opacity group-hover/actions:opacity-100 focus-visible:opacity-100" onClick={add} />
      )}
    </div>
  )
}

const VARIANTS: Record<string, string> = {
  primary: 'h-9 rounded-md bg-ink px-4 text-ink-contrast',
  secondary: 'h-9 rounded-md border border-border-strong bg-bg px-4 text-text',
  ghost: 'h-9 rounded-md px-3 text-text bg-sunken/60',
  link: 'h-9 text-text underline decoration-border-strong underline-offset-4',
}

function Action({ node }: { node: BlockNode }) {
  const { store } = useEditorContext()
  const { readOnly } = useWrite()
  const [open, setOpen] = useState(false)
  const focus = useEditor(store, (s) => (s.focus?.id === node.id && s.focus.prop === 'label' ? s.focus : null))
  const isLink = node.type === 'link'
  const url = String(node.props.url ?? '')
  const look = isLink ? VARIANTS.link : VARIANTS[node.props.variant ?? 'primary'] ?? VARIANTS.primary
  const anchors = useAnchors()

  const fields: FieldDef[] = [
    // A new button opens straight into the picker; one with a link shows its settings first.
    linkField({ handle: 'url', label: t('Link'), placeholder: t('Paste a link or search pages'), autofocus: !url, anchors }),
    ...(isLink ? [] : [{ handle: 'variant', type: 'select', label: t('Style'), display: 'segmented', block: true, options: [{ value: 'primary', label: t('Primary') }, { value: 'secondary', label: t('Secondary') }, { value: 'ghost', label: t('Ghost') }, { value: 'link', label: t('Text') }] } as FieldDef]),
    { handle: 'new_tab', type: 'toggle', label: t('Open in New Tab') },
  ]

  return (
    <span className="group/action relative inline-flex">
      <span className={cn('inline-flex items-center text-sm font-medium', look)}>
        <InlineEditable
          html={String(node.props.label ?? '')}
          singleLine
          quiet
          disabled={readOnly}
          placeholder={isLink ? t('Link Text') : t('Button Text')}
          className="min-w-8 whitespace-nowrap"
          focus={focus}
          onChange={(label) => store.updateProps(node.id, { label })}
          onFocus={() => store.get().selected !== node.id && store.select(node.id)}
          onKeyDown={(e, el) => {
            if (e.key === 'Enter' || e.key === 'Escape') {
              e.preventDefault()
              el.blur()
              if (e.key === 'Escape') store.select(node.id, true)
            }
          }}
        />
      </span>
      {!url && <span className="pointer-events-none absolute -right-0.5 -top-0.5 size-2 rounded-full bg-warning ring-2 ring-bg" title={t('No link yet')} />}
      {!readOnly && (
        <Popover
          open={open}
          onOpenChange={setOpen}
          align="start"
          className="w-80 !p-3"
          trigger={
            <button
              type="button"
              aria-label={t('Edit Link')}
              title={url || t('Add a Link')}
              className={cn('absolute -right-2.5 -top-2.5 grid size-6 place-items-center rounded-full border border-border bg-bg text-muted shadow-pop transition-opacity hover:text-text', open ? 'opacity-100' : 'opacity-0 group-hover/action:opacity-100 focus-visible:opacity-100')}
            >
              <Icon name="link" size={12} />
            </button>
          }
        >
          <div className="space-y-3" onKeyDown={(e) => e.key === 'Enter' && !e.defaultPrevented && (e.target as HTMLElement).tagName === 'INPUT' && setOpen(false)}>
            <Fields fields={fields} values={node.props} onChange={(h, v) => (h !== 'variant' || v) && store.updateProps(node.id, { [h]: v ?? (h === 'url' ? '' : v) }, `${h}:${node.id}`)} className="!gap-y-3" />
            <div className="flex justify-end border-t border-border pt-2.5">
              <Button size="xs" variant="ghost" icon="trash" onClick={() => { setOpen(false); store.remove(node.id) }}>
                {isLink ? t('Remove Link') : t('Remove Button')}
              </Button>
            </div>
          </div>
        </Popover>
      )}
    </span>
  )
}

// ── Media ─────────────────────────────────────────────────

/** Resolve an image prop (media id(s) or URL) to a preview URL. */
export function useImageUrl(value: unknown): string | null {
  const id = Array.isArray(value) ? value[0] : value
  const isUrl = typeof id === 'string' && /^(https?:|\/)/.test(id)
  const media = useAbility<{ items: { id: string; url: string; thumb?: string }[] }>('media/lookup', { ids: [id] }, { enabled: typeof id === 'string' && !isUrl && id.length > 0, staleTime: 300_000, retry: false })
  if (isUrl) return id as string
  const item = media.data?.items?.[0]
  return item ? item.url ?? item.thumb : null
}

export function ImageBlock({ node }: { node: BlockNode }) {
  const { store } = useEditorContext()
  const { settings, readOnly } = useWrite()
  const src = useImageUrl(node.props.image)
  return (
    <figure className="cms-block-figure m-0 py-1">
      {src ? (
        <div className="group/image relative">
          <img src={src} alt={node.props.alt ?? ''} className="block max-h-[520px] w-full rounded-md bg-sunken object-cover" style={node.props.aspect && node.props.aspect !== 'auto' ? { aspectRatio: node.props.aspect } : undefined} />
          {!readOnly && (
            <div className="absolute right-2 top-2 flex gap-1 opacity-0 transition-opacity group-hover/image:opacity-100">
              <Button size="xs" variant="secondary" icon="settings" onClick={() => settings(node.id)}>{t('Edit')}</Button>
            </div>
          )}
          {!node.props.alt && !readOnly && <span className="absolute bottom-2 left-2 rounded-xs bg-bg/90 px-1.5 py-0.5 text-2xs text-muted opacity-0 shadow-pop transition-opacity group-hover/image:opacity-100">{t('No alt text')}</span>}
        </div>
      ) : (
        <div className="flex h-36 flex-col items-center justify-center gap-3 rounded-md bg-surface text-faint">
          <Icon name="image" size={22} />
          <Fields bare disabled={readOnly} fields={[{ handle: 'image', type: 'media', label: t('Image'), max: 1, accept: 'image/*' } as FieldDef]} values={{ image: node.props.image }} onChange={(_, image) => store.updateProps(node.id, { image })} />
        </div>
      )}
      <Caption node={node} />
    </figure>
  )
}

function Caption({ node }: { node: BlockNode }) {
  const { store } = useEditorContext()
  const { readOnly } = useWrite()
  const focus = useEditor(store, (s) => (s.focus?.id === node.id && s.focus.prop === 'caption' ? s.focus : null))
  if (readOnly && !node.props.caption) return null
  return (
    <figcaption>
      <InlineEditable html={String(node.props.caption ?? '')} quiet disabled={readOnly} placeholder={t('Add a Caption')} focus={focus} className="mt-2 text-sm text-muted" onChange={(caption) => store.updateProps(node.id, { caption })} onFocus={() => store.select(node.id)} />
    </figcaption>
  )
}

/** Video and embeds: a quiet card with where it comes from; the link is set in its settings. */
export function MediaCard({ node, type }: { node: BlockNode; type?: BlockTypeDef }) {
  const { settings } = useWrite()
  const url = String(node.props.url ?? '')
  const host = url ? url.replace(/^https?:\/\/(www\.)?/, '').split(/[/?#]/)[0] : ''
  return (
    <figure className="cms-block-figure m-0 py-1">
      <button type="button" onClick={() => settings(node.id)} className="flex h-14 w-full items-center gap-3 rounded-md bg-surface px-4 text-left hover:bg-hover">
        <Icon name={type?.icon ?? 'embed'} size={18} className="text-muted" />
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm text-text">{node.props.title || host || (node.type === 'video' ? t('Add a video link') : t('Add a link to embed'))}</span>
          {host && node.props.title && <span className="block truncate text-xs text-faint">{host}</span>}
        </span>
      </button>
      {type?.inline?.includes('caption') && <Caption node={node} />}
    </figure>
  )
}

// ── Code and tables ───────────────────────────────────────

export function CodeBlock({ node }: { node: BlockNode }) {
  const { store } = useEditorContext()
  const { settings, readOnly } = useWrite()
  return (
    <div className="group/code relative py-1" onFocus={() => store.select(node.id)}>
      <Fields bare disabled={readOnly} fields={[{ handle: 'code', type: 'code', label: t('Code'), placeholder: t('Code'), rows: 3 }]} values={{ code: node.props.code ?? '' }} onChange={(_, code) => store.updateProps(node.id, { code: code ?? '' }, `code:${node.id}`)} />
      <button type="button" onClick={() => settings(node.id)} className="absolute right-2 top-2.5 rounded-xs px-1.5 font-mono text-2xs text-faint hover:bg-hover hover:text-text">
        {node.props.language && node.props.language !== 'plain' ? node.props.language : t('Plain Text')}
      </button>
    </div>
  )
}

export function TableBlock({ node }: { node: BlockNode }) {
  const { store } = useEditorContext()
  const { readOnly } = useWrite()
  const rows: string[][] = Array.isArray(node.props.rows) && node.props.rows.length ? node.props.rows : [['', ''], ['', '']]
  const width = Math.max(...rows.map((r) => r.length))
  const set = (r: number, c: number, html: string) => store.updateProps(node.id, { rows: rows.map((row, i) => (i === r ? Array.from({ length: width }, (_, j) => (j === c ? html : row[j] ?? '')) : row)) }, `table:${node.id}`)
  const edit = (next: string[][]) => store.updateProps(node.id, { rows: next })

  return (
    <div className="group/table py-1">
      <div className="overflow-x-auto rounded-md border border-border">
        <table className="w-full border-collapse text-sm">
          <tbody>
            {rows.map((row, r) => (
              <tr key={r} className={cn('border-b border-border last:border-b-0', r === 0 && node.props.header !== false && 'bg-surface font-medium')}>
                {Array.from({ length: width }, (_, c) => (
                  <td key={c} className="min-w-24 border-r border-border px-3 py-2 align-top last:border-r-0">
                    <InlineEditable html={row[c] ?? ''} singleLine disabled={readOnly} onChange={(html) => set(r, c, html)} onFocus={() => store.select(node.id)} />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!readOnly && (
        <div className="mt-1 flex gap-1 opacity-0 transition-opacity group-hover/table:opacity-100 group-focus-within/table:opacity-100">
          <Button size="xs" variant="ghost" icon="plus" onClick={() => edit([...rows, Array(width).fill('')])}>{t('Row')}</Button>
          <Button size="xs" variant="ghost" icon="plus" onClick={() => edit(rows.map((r) => [...r, '']))}>{t('Column')}</Button>
          {rows.length > 1 && <Button size="xs" variant="ghost" icon="minus" onClick={() => edit(rows.slice(0, -1))}>{t('Row')}</Button>}
          {width > 1 && <Button size="xs" variant="ghost" icon="minus" onClick={() => edit(rows.map((r) => r.slice(0, width - 1)))}>{t('Column')}</Button>}
        </div>
      )}
    </div>
  )
}

/** Questions and answers, each editable in place. */
export function AccordionBlock({ node }: { node: BlockNode }) {
  const { store } = useEditorContext()
  const { readOnly } = useWrite()
  const items: { question?: string; answer?: string }[] = Array.isArray(node.props.items) ? node.props.items : []
  const set = (i: number, patch: Record<string, string>) => store.updateProps(node.id, { items: items.map((it, j) => (j === i ? { ...it, ...patch } : it)) }, `faq:${node.id}:${i}`)
  const shown = items.length ? items : [{ question: '', answer: '' }]

  return (
    <div className="group/faq py-1">
      <div className="divide-y divide-border border-y border-border">
        {shown.map((item, i) => (
          <div key={i} className="py-3">
            <div className="flex items-start gap-2">
              <Icon name="chevron-down" size={14} className="mt-1 shrink-0 text-faint" />
              <InlineEditable html={escapeHtml(item.question ?? '')} singleLine disabled={readOnly} placeholder={t('Question')} className="min-w-0 flex-1 text-[15px] font-medium leading-6" onChange={(html) => set(i, { question: toText(html) })} onFocus={() => store.select(node.id)} />
              {!readOnly && items.length > 1 && (
                <IconButton icon="close" label={t('Remove Question')} size="xs" className="opacity-0 group-hover/faq:opacity-100" onClick={() => store.updateProps(node.id, { items: items.filter((_, j) => j !== i) })} />
              )}
            </div>
            <InlineEditable html={item.answer ?? ''} quiet disabled={readOnly} placeholder={t('Answer')} className="mt-1 pl-[22px] text-[15px] leading-6 text-muted" onChange={(answer) => set(i, { answer })} onFocus={() => store.select(node.id)} />
          </div>
        ))}
      </div>
      {!readOnly && (
        <Button size="xs" variant="ghost" icon="plus" className="mt-1 opacity-0 transition-opacity group-hover/faq:opacity-100" onClick={() => store.updateProps(node.id, { items: [...items, { question: '', answer: '' }] })}>
          {t('Question')}
        </Button>
      )}
    </div>
  )
}

// ── Components ─────────────────────

interface ComponentDef {
  handle: string
  id?: string | null
  title: string
  synced: boolean
  props?: FieldDef[]
}

/** A component instance with properties editable inline. */
export function ComponentBlock({ node }: { node: BlockNode }) {
  const { store } = useEditorContext()
  const { readOnly } = useWrite()
  const list = useAbility<ComponentDef[]>('patterns/list', { working: true }, { staleTime: 30_000 })
  const pattern = list.data?.find((p) => p.handle === node.props.ref)
  const values = (node.props.values ?? {}) as Record<string, unknown>
  const bind = node.bind ?? {}
  const props = (pattern?.props ?? []).filter((f) => f.type !== 'json')
  const set = (handle: string, value: unknown) => store.updateProps(node.id, { values: { ...values, [handle]: value } }, `values:${node.id}:${handle}`)

  const header = (
    <div className="flex h-8 items-center gap-2 text-xs">
      <Icon name="symbol" size={13} className="text-faint" />
      <span className="truncate font-medium text-muted">{pattern?.title ?? (list.isLoading ? '…' : t('Missing component'))}</span>
      <span className="flex-1" />
      {pattern?.id && (
        <button type="button" onClick={() => navigate(`/content/components/${pattern.id}`)} className="rounded-xs px-1.5 text-faint opacity-0 transition-opacity hover:bg-hover hover:text-text group-hover/component:opacity-100">
          {t('Edit Component')}
        </button>
      )}
    </div>
  )

  // Instances without properties remain selectable.
  if (props.length === 0) {
    return (
      <div className="flex h-9 items-center gap-2 rounded-md px-1 text-sm text-faint">
        <Icon name="symbol" size={14} />
        <span className="truncate text-muted">{pattern?.title ?? (list.isLoading ? '…' : t('Missing component'))}</span>
        {pattern?.id && (
          <button type="button" onClick={() => navigate(`/content/components/${pattern.id}`)} className="shrink-0 rounded-xs px-1 text-xs text-faint hover:bg-hover hover:text-text">
            {t('Edit Component')}
          </button>
        )}
      </div>
    )
  }

  return (
    <div className="group/component rounded-md border border-border px-3 pb-1">
      {header}
      {props.length > 0 && (
        <div className="space-y-2.5 border-t border-border py-2.5">
          {props.map((f) => {
            const value = values[f.handle] ?? f.default
            const bound = bind[`values.${f.handle}`]
            return (
              <div key={f.handle} className="grid grid-cols-[120px_minmax(0,1fr)] items-start gap-3">
                <span className="truncate pt-[3px] text-xs text-faint" title={f.instructions}>{f.label}</span>
                {bound ? (
                  <span className="pt-[3px] font-mono text-xs text-faint">{bound}</span>
                ) : ['text', 'textarea', 'richtext'].includes(f.type) ? (
                  <InlineEditable
                    html={f.type === 'richtext' ? String(value ?? '') : escapeHtml(String(value ?? ''))}
                    singleLine={f.type === 'text'}
                    disabled={readOnly}
                    placeholder={f.placeholder ?? t('Empty')}
                    className="text-[15px] leading-6"
                    onChange={(html) => set(f.handle, f.type === 'richtext' ? html : toText(html))}
                    onFocus={() => store.select(node.id)}
                  />
                ) : (
                  <Fields bare disabled={readOnly} fields={[f.type === 'url' ? linkField({ ...f, label: f.label }) : { ...f, size: 'sm' }]} values={{ [f.handle]: value }} onChange={(_, v) => set(f.handle, v)} />
                )}
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

/** Any other block (a plugin's, or one whose plugin is gone): what it is, settings on click. */
export function BlockCard({ node, type }: { node: BlockNode; type?: BlockTypeDef }) {
  const { settings } = useWrite()
  const text = tree.summary(node, 80)
  return (
    <button type="button" onClick={() => settings(node.id)} className="my-1 flex w-full items-center gap-3 rounded-md bg-surface px-3 py-2.5 text-left hover:bg-hover">
      <Icon name={type?.icon ?? 'square'} size={16} className="shrink-0 text-muted" />
      <span className="min-w-0 flex-1">
        <span className="block text-sm text-text">{type?.title ?? node.type}</span>
        {(text || !type) && <span className="block truncate text-xs text-faint">{type ? text : t('Its plugin is off — the content is kept')}</span>}
      </span>
    </button>
  )
}

function escapeHtml(text: string): string {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>')
}

function toText(html: string): string {
  const div = document.createElement('div')
  div.innerHTML = html.replace(/<br\s*\/?>/g, '\n')
  return div.textContent ?? ''
}

