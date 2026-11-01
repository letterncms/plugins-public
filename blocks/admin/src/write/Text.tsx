import { t } from '@letterncms/sdk'
import { Icon, Menu, cn } from '@letterncms/ui'
import type { KeyboardEvent, ReactNode } from 'react'
import { atEnd, atStart, caretOffset, split, textLength } from '../core/caret'
import { useEditorContext } from '../core/context'
import { InlineEditable } from '../core/InlineEditable'
import { accepts, insertionPoint } from '../core/insert'
import { useEditor } from '../core/store'
import * as tree from '../core/tree'
import { newId, type BlockNode } from '../core/types'
import { focusStop, moveBetweenStops, paragraph, sameStop, useWrite } from './doc'
import type { Stop } from './outline'
import { textDefersEnter } from '../core/slashKeys'
import { openSlash, slashFor } from './slash'

const HEADING_CLASS: Record<string, string> = {
  '1': 'text-[30px] leading-[38px] font-semibold tracking-[-0.02em]',
  '2': 'text-[24px] leading-[32px] font-semibold tracking-[-0.015em]',
  '3': 'text-[20px] leading-[28px] font-semibold tracking-[-0.01em]',
  '4': 'text-[17px] leading-[26px] font-semibold',
  '5': 'text-[16px] leading-[26px] font-semibold',
  '6': 'text-[13px] leading-[22px] font-semibold uppercase tracking-[0.04em] text-muted',
}

export const PROSE = 'text-[16px] leading-[27px]'

const TONES: Record<string, { icon: string; className: string; label: () => string }> = {
  note: { icon: 'info', className: 'bg-surface', label: () => t('Note') },
  tip: { icon: 'sparkle', className: 'bg-success/7', label: () => t('Tip') },
  warning: { icon: 'warning', className: 'bg-warning/9', label: () => t('Warning') },
  danger: { icon: 'error', className: 'bg-danger/7', label: () => t('Danger') },
}

/** Paragraphs, headings, quotes and callouts: one editable text, Notion-style keys. */
export function TextBlock({ node }: { node: BlockNode }) {
  if (node.type === 'quote') {
    return (
      <blockquote className="m-0 border-l-[3px] border-text/80 py-0.5 pl-4">
        <EditableText node={node} prop="text" className="text-[17px] leading-[28px]" placeholder={t('Quote')} />
        <EditableText node={node} prop="cite" className="cms-block-cite mt-1 text-sm text-muted" placeholder={t('Attribution')} quiet />
      </blockquote>
    )
  }
  if (node.type === 'callout') return <Callout node={node} />
  if (node.type === 'heading') {
    const level = String(node.props.level ?? 2)
    return <EditableText node={node} prop="text" tag={`h${level}`} className={HEADING_CLASS[level] ?? HEADING_CLASS['2']} placeholder={t('Heading {level}', { level })} />
  }
  return <Paragraph node={node} />
}

/** Its hint shows where you are writing, or always when it is all there is. */
function Paragraph({ node }: { node: BlockNode }) {
  const { store } = useEditorContext()
  const only = useEditor(store, (s) => s.nodes.length === 1 && s.nodes[0].id === node.id)
  return <EditableText node={node} prop="text" tag="p" className={PROSE} placeholder={t('Write, or press “/” for blocks')} quiet={!only} />
}

function Callout({ node }: { node: BlockNode }) {
  const { store } = useEditorContext()
  const { readOnly } = useWrite()
  const tone = TONES[node.props.tone] ?? TONES.note
  return (
    <div className={cn('flex gap-3 rounded-md px-4 py-3', tone.className)}>
      <Menu
        align="start"
        trigger={
          <button type="button" disabled={readOnly} aria-label={t('Callout Style')} className="grid h-[27px] w-5 shrink-0 place-items-center rounded-xs text-muted hover:text-text">
            <Icon name={tone.icon} size={17} />
          </button>
        }
        items={Object.entries(TONES).map(([value, def]) => ({ label: def.label(), icon: def.icon, checked: value === (node.props.tone ?? 'note'), onSelect: () => store.updateProps(node.id, { tone: value }) }))}
      />
      <EditableText node={node} prop="text" className={cn(PROSE, 'min-w-0 flex-1')} placeholder={t('Write a note')} />
    </div>
  )
}

/** One text prop of a block, with the keyboard behavior of a single document. */
export function EditableText({ node, prop, tag = 'div', className, placeholder, quiet }: { node: BlockNode; prop: string; tag?: string; className?: string; placeholder: string; quiet?: boolean }) {
  const { store, types, allowed } = useEditorContext()
  const { stops, readOnly } = useWrite()
  const focus = useEditor(store, (s) => (s.focus?.id === node.id && (s.focus.prop ?? 'text') === prop ? s.focus : null))
  const html = String(node.props[prop] ?? '')

  const onChange = (next: string, el: HTMLElement) => {
    if (node.type === 'paragraph' && prop === 'text' && shortcut(next, el)) return
    store.updateProps(node.id, { [prop]: next }, `text:${node.id}:${prop}`)
    const slash = node.type === 'paragraph' ? next.replace(/<[^>]+>/g, '').match(/^\/([\w-]*)$/) : null
    openSlash(slash ? { id: node.id, query: slash[1] } : null)
  }

  /**
   * Markdown typed at the start of a paragraph turns it into another block. The caret
   * keeps its place in the text: typing fast, the next letters can reach this element
   * before the new block is drawn, and they then belong after the ones already typed.
   */
  const shortcut = (next: string, el: HTMLElement): boolean => {
    const text = next.replace(/&nbsp;/g, ' ')
    const into = (type: string, props: Record<string, any>, marker: string) => {
      const location = tree.locate(store.get().nodes, node.id)
      const parent = location?.parentId ? tree.find(store.get().nodes, location.parentId) : null
      if (!types.has(type) || !accepts(parent, type, types, parent ? [] : allowed)) return false
      const at = Math.max(0, caretOffset(el) - textLength(marker))
      store.updateNode(node.id, { type, props })
      store.focus(node.id, at, type === 'list' ? 'items' : 'text', type === 'list' ? 0 : undefined)
      return true
    }
    let m: RegExpMatchArray | null
    if ((m = text.match(/^(#{1,4}) /))) return into('heading', { text: text.slice(m[0].length), level: String(m[1].length) }, m[0])
    if ((m = text.match(/^[-*] /))) return into('list', { items: [text.slice(2)], ordered: false }, m[0])
    if ((m = text.match(/^1[.)] /))) return into('list', { items: [text.slice(3)], ordered: true }, m[0])
    if ((m = text.match(/^(>|&gt;) /))) return into('quote', { text: text.slice(m[0].length), cite: '' }, m[0])
    if ((m = text.match(/^! /))) return into('callout', { text: text.slice(2), tone: 'note' }, m[0])
    if (text === '```') return into('code', { code: '', language: 'plain' }, text)
    if (text === '---' && types.has('divider') && (allowed.length === 0 || allowed.includes('divider'))) {
      const p = paragraph()
      const { nodes } = store.get()
      const loc = tree.locate(nodes, node.id)
      store.commit(tree.insert(tree.update(nodes, node.id, (n) => ({ ...n, type: 'divider', props: {} })), loc?.parentId ?? null, (loc?.index ?? nodes.length) + 1, [p]))
      store.focus(p.id, 'start')
      return true
    }
    return false
  }

  const onKeyDown = (e: KeyboardEvent<HTMLElement>, el: HTMLElement) => {
    if (e.defaultPrevented) return // the slash menu took it
    const all = stops()
    const at = all.findIndex((s) => sameStop(s, node.id, prop))
    const here = all[at]
    const { nodes } = store.get()

    if (e.key === 'Enter' && !e.shiftKey && !e.metaKey && !e.ctrlKey && !e.altKey) {
      e.preventDefault()
      // "/image" + Enter faster than the menu can draw: the menu takes it when it does.
      const slash = slashFor(node.id)
      if (slash && textDefersEnter(true, !!document.querySelector('[data-cms-slash]'))) return openSlash({ ...slash, enter: true })
      if (prop === 'cite') {
        const p = paragraph()
        const loc = insertionPoint(nodes, node.id, 'paragraph', types, allowed)
        if (!loc) return
        store.insert(loc.parentId, loc.index, [p])
        store.focus(p.id, 'start')
        return
      }
      const [before, after] = split(el)
      if (tree.isEmptyHtml(before) && !tree.isEmptyHtml(after)) {
        // At the start of a block: open an empty line above it.
        const loc = tree.locate(nodes, node.id)
        if (!loc) return
        store.insert(loc.parentId, loc.index, [paragraph()])
        store.focus(node.id, 'start')
        return
      }
      // A paragraph continues as its sibling would (same tag and design); anything else continues as text.
      const next: BlockNode =
        node.type === 'paragraph'
          ? { id: newId(), type: 'paragraph', props: { ...node.props, text: after }, ...(node.style ? { style: structuredClone(node.style) } : {}), ...(node.class ? { class: node.class } : {}) }
          : paragraph(after)
      const loc = insertionPoint(nodes, node.id, 'paragraph', types, allowed)
      if (!loc) return
      store.commit(tree.insert(tree.update(nodes, node.id, (n) => ({ ...n, props: { ...n.props, [prop]: before } })), loc.parentId, loc.index, [next]))
      store.focus(next.id, 'start')
      return
    }

    if (e.key === 'Backspace' && atStart(el)) {
      if (prop === 'cite') {
        if (tree.isEmptyHtml(html)) {
          e.preventDefault()
          store.focus(node.id, 'end', 'text')
        }
        return
      }
      if (node.type !== 'paragraph') {
        // A heading, quote or callout becomes plain text first.
        e.preventDefault()
        store.updateNode(node.id, { type: 'paragraph', props: { text: html } })
        store.focus(node.id, 'start')
        return
      }
      const prev = all[at - 1]
      if (!prev) return
      e.preventDefault()
      if (tree.isEmptyHtml(html)) {
        store.remove(node.id)
        focusStop(store, prev, 'end')
        return
      }
      if (prev.parent === here?.parent && mergeInto(prev, html)) return
      focusStop(store, prev, 'end')
      return
    }

    if (e.key === 'Delete' && atEnd(el) && prop === 'text') {
      const next = all[at + 1]
      const target = next && next.prop === 'text' && next.parent === here?.parent ? tree.find(nodes, next.id) : null
      if (!target || !['paragraph', 'heading'].includes(target.type)) return
      e.preventDefault()
      const offset = textLength(html)
      store.commit(tree.remove(tree.update(nodes, node.id, (n) => ({ ...n, props: { ...n.props, text: html + String(target.props.text ?? '') } })), target.id))
      store.focus(node.id, offset)
      return
    }

    if (moveBetweenStops(e, el, store, all, at)) return

    if (e.key === 'Escape') {
      e.preventDefault()
      el.blur()
      store.select(node.id, true)
    }
  }

  /** Append this paragraph's text to the previous text and drop the paragraph. */
  const mergeInto = (prev: Stop, text: string): boolean => {
    const { nodes } = store.get()
    const target = tree.find(nodes, prev.id)
    if (!target) return false
    if (prev.prop === 'items') {
      const items: string[] = [...(target.props.items ?? [''])]
      const before = items[prev.index ?? 0] ?? ''
      items[prev.index ?? 0] = before + text
      store.commit(tree.remove(tree.update(nodes, target.id, (n) => ({ ...n, props: { ...n.props, items } })), node.id))
      focusStop(store, prev, textLength(before))
      return true
    }
    if (prev.prop !== 'text' || !['paragraph', 'heading', 'quote', 'callout'].includes(target.type)) return false
    const before = String(target.props.text ?? '')
    store.commit(tree.remove(tree.update(nodes, target.id, (n) => ({ ...n, props: { ...n.props, text: before + text } })), node.id))
    focusStop(store, prev, textLength(before))
    return true
  }

  return (
    <InlineEditable
      tag={tag}
      html={html}
      onChange={onChange}
      placeholder={placeholder}
      quiet={quiet}
      className={className}
      focus={focus}
      disabled={readOnly}
      onKeyDown={onKeyDown}
      onFocus={() => store.get().selected !== node.id && store.select(node.id)}
      onPaste={(text) => {
        if (!text.includes('\n') || prop !== 'text') return false
        void import('./paste').then((m) => m.pasteMarkdown(store, node, text, types, allowed))
        return true
      }}
    />
  )
}

/** Bulleted or numbered list: one editable line per item. */
export function ListBlock({ node, renderItem }: { node: BlockNode; renderItem?: (index: number, editable: ReactNode) => ReactNode }) {
  const { store, types, allowed } = useEditorContext()
  const { stops, readOnly } = useWrite()
  const items: string[] = Array.isArray(node.props.items) && node.props.items.length ? node.props.items : ['']
  const focus = useEditor(store, (s) => (s.focus?.id === node.id && s.focus.prop === 'items' ? s.focus : null))
  const ordered = !!node.props.ordered

  const setItems = (next: string[], group = `list:${node.id}`) => store.updateProps(node.id, { items: next }, group)

  const onKeyDown = (i: number) => (e: KeyboardEvent<HTMLElement>, el: HTMLElement) => {
    if (e.defaultPrevented) return
    const { nodes } = store.get()
    const all = stops()
    const at = all.findIndex((s) => sameStop(s, node.id, 'items', i))

    if (e.key === 'Enter' && !e.shiftKey && !e.metaKey && !e.ctrlKey) {
      e.preventDefault()
      if (tree.isEmptyHtml(items[i]) && i === items.length - 1) {
        // An empty last item leaves the list.
        const p = paragraph()
        const rest = items.slice(0, -1)
        const loc = insertionPoint(nodes, node.id, 'paragraph', types, allowed)
        if (!loc) return
        const doc = rest.length ? tree.update(nodes, node.id, (n) => ({ ...n, props: { ...n.props, items: rest } })) : nodes
        const placed = tree.insert(doc, loc.parentId, loc.index, [p])
        store.commit(rest.length ? placed : tree.remove(placed, node.id))
        store.focus(p.id, 'start')
        return
      }
      const [before, after] = split(el)
      setItems([...items.slice(0, i), before, after, ...items.slice(i + 1)], '')
      store.focus(node.id, 'start', 'items', i + 1)
      return
    }

    if (e.key === 'Backspace' && atStart(el)) {
      e.preventDefault()
      if (i === 0) {
        // The first item becomes a paragraph above the list.
        const p = paragraph(items[0])
        const loc = tree.locate(nodes, node.id)
        const rest = items.slice(1)
        let doc = tree.insert(nodes, loc?.parentId ?? null, loc?.index ?? 0, [p])
        doc = rest.length ? tree.update(doc, node.id, (n) => ({ ...n, props: { ...n.props, items: rest } })) : tree.remove(doc, node.id)
        store.commit(doc)
        store.focus(p.id, 'start')
        return
      }
      setItems([...items.slice(0, i - 1), items[i - 1] + items[i], ...items.slice(i + 1)], '')
      store.focus(node.id, textLength(items[i - 1]), 'items', i - 1)
      return
    }

    if (moveBetweenStops(e, el, store, all, at)) return

    if (e.key === 'Escape') {
      e.preventDefault()
      el.blur()
      store.select(node.id, true)
    }
  }

  const editable = (item: string, i: number) => <InlineEditable
    html={item}
    className={renderItem ? undefined : 'min-w-0 flex-1 pl-1'}
    tag={renderItem ? 'span' : 'div'}
    placeholder={t('List')}
    quiet
    disabled={readOnly}
    focus={focus && (focus.index ?? 0) === i ? focus : null}
    onChange={(html) => setItems(items.map((x, j) => (j === i ? html : x)))}
    onKeyDown={onKeyDown(i)}
    onFocus={() => store.get().selected !== node.id && store.select(node.id)}
  />
  if (renderItem) return <>{items.map((item, i) => renderItem(i, editable(item, i)))}</>
  return (
    <div className={cn(PROSE, 'space-y-0.5')}>
      {items.map((item, i) => (
        <div key={i} className="flex">
          <span className={cn('w-6 shrink-0 select-none text-muted', ordered ? 'pr-1.5 text-right tabular-nums' : 'pl-1.5')} aria-hidden>
            {ordered ? `${i + 1}.` : '•'}
          </span>
          {editable(item, i)}
        </div>
      ))}
    </div>
  )
}
