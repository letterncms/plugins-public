import { can, t } from '@letterncms/sdk'
import { Fields, Icon, IconButton, Menu, Tabs, Tooltip } from '@letterncms/ui'
import { Fragment, useId, useLayoutEffect, useRef, useState } from 'react'
import { useEditorContext } from '../core/context'
import { savePattern } from '../core/SavePattern'
import { useEditor } from '../core/store'
import * as tree from '../core/tree'
import type { BlockNode } from '../core/types'
import { patternFits } from '../core/insert'
import { ContentPanel } from './content/ContentPanel'
import { DesignPanel } from './design/DesignPanel'
import { LogicPanel } from './logic/LogicPanel'
import { MotionPanel } from './motion/MotionPanel'

const tabIds = ['content', 'design', 'interact'] as const
type Tab = typeof tabIds[number]
const tabKey = 'cms.block.tab'

/** Where the inspector was scrolled to: a section and how far its top sat from the panel's top. */
interface Anchor {
  section?: string
  offset: number
  scrollTop: number
}

function anchorOf(panel: HTMLElement): Anchor | null {
  if (!panel.scrollTop) return null
  const top = panel.getBoundingClientRect().top
  const section = [...panel.querySelectorAll<HTMLElement>('[data-section]')].find((el) => el.getBoundingClientRect().bottom > top)
  return { section: section?.dataset.section, offset: section ? section.getBoundingClientRect().top - top : 0, scrollTop: panel.scrollTop }
}

/** Brings the anchored section back to the same place, or the same scroll when the block has no such section. */
function restore(panel: HTMLElement, anchor: Anchor) {
  const section = anchor.section && panel.querySelector(`[data-section="${CSS.escape(anchor.section)}"]`)
  if (section) panel.scrollTop += section.getBoundingClientRect().top - panel.getBoundingClientRect().top - anchor.offset
  else panel.scrollTop = anchor.scrollTop
}

function initialTab(): Tab {
  try {
    const saved = sessionStorage.getItem(tabKey)
    return tabIds.find((id) => id === saved) ?? 'content'
  } catch { return 'content' }
}

/** Shared block inspector: content first, with design and interactions available in the same frame. */
export function Inspector({ node, onClose }: { node: BlockNode; onClose?: () => void }) {
  const { store, types, allowed, readOnly } = useEditorContext()
  const type = types.get(node.type)
  const ancestors = useEditor(store, (s) => tree.ancestors(s.nodes, node.id))
  const breakpoint = useEditor(store, (state) => state.breakpoint)
  const screens = [{ value: 'base', label: t('Desktop'), icon: 'b-monitor' }, { value: 'tablet', label: t('Tablet'), icon: 'b-device-tablet' }, { value: 'mobile', label: t('Phone'), icon: 'b-device-mobile' }] as const
  const screen = screens.find((item) => item.value === breakpoint) ?? screens[0]
  const [tab, setTab] = useState<Tab>(initialTab)
  const tabsId = `block-inspector-${useId()}`
  const [renaming, setRenaming] = useState(false)
  // The panels remount per block (see below). Blocks show different sections, so keep the
  // section in view where it was rather than the pixel offset. The old panels are measured
  // while rendering the new block, the last moment they are still on screen.
  const panel = useRef<HTMLFieldSetElement>(null)
  const shown = useRef(node.id)
  const anchor = useRef<Anchor | null>(null)
  if (shown.current !== node.id) {
    shown.current = node.id
    anchor.current = panel.current && anchorOf(panel.current)
  }
  useLayoutEffect(() => {
    if (panel.current && anchor.current) restore(panel.current, anchor.current)
    anchor.current = null
  }, [node.id])
  const choose = (next: Tab) => {
    try { sessionStorage.setItem(tabKey, next) } catch { /* Optional preference. */ }
    setTab(next)
  }
  const badges = { design: Object.keys(node.style ?? {}).length > 0, content: Object.keys(node.bind ?? {}).length > 0, interact: !!node.motion?.length || !!node.logic }

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-col">
      <div className="border-b border-border px-3 pb-2 pt-2.5">
        {ancestors.length > 0 && (
          <div className="mb-1 flex items-center gap-0.5 overflow-hidden text-[10px] text-faint">
            {ancestors.slice(-3).map((a) => (
              <button key={a.id} type="button" onClick={() => store.select(a.id)} className="max-w-[80px] truncate hover:text-text">
                {a.label || types.get(a.type)?.title || a.type} ›
              </button>
            ))}
          </div>
        )}
        <div className="flex items-center gap-2">
          <span className="grid size-6 shrink-0 place-items-center rounded-xs bg-focus/12 text-focus">
            <Icon name={type?.icon ?? 'square'} size={13} />
          </span>
          {renaming ? (
            <div className="min-w-0 flex-1" onBlur={() => setRenaming(false)} onKeyUp={(e) => e.key === 'Escape' && (e.target as HTMLElement).blur()}>
              <Fields
                layout="inline"
                bare
                fields={[{ handle: 'label', type: 'text', size: 'sm', commit: 'blur', label: t('Name'), autofocus: true, placeholder: type?.title }]}
                values={{ label: node.label ?? '' }}
                onChange={(_, v: string) => store.updateNode(node.id, { label: v.trim() || undefined })}
              />
            </div>
          ) : (
            <button type="button" onDoubleClick={() => !readOnly && setRenaming(true)} className="min-w-0 flex-1 truncate text-left text-sm font-semibold text-ink" title={readOnly ? undefined : t('Double-Click to Rename')}>
              {node.label || type?.title || node.type}
            </button>
          )}
          {!readOnly && node.type !== 'pattern' && can('entries.components.create') && (
            <Tooltip content={t('Make a Component')}>
              <IconButton
                icon="b-diamonds-four"
                label={t('Make a Component')}
                size="xs"
                onClick={() => {
                  if (!store.selection().includes(node.id)) store.select(node.id)
                  savePattern(store, patternFits(store, types, allowed))
                }}
              />
            </Tooltip>
          )}
          <Tooltip content={t('Duplicate')} shortcut="mod+d">
            <IconButton icon="b-copy" label={t('Duplicate')} disabled={readOnly} size="xs" onClick={() => store.duplicate(node.id)} />
          </Tooltip>
          <Tooltip content={t('Delete')} shortcut="backspace">
            <IconButton icon="b-trash" label={t('Delete')} disabled={readOnly} size="xs" onClick={() => store.remove(node.id)} />
          </Tooltip>
          {onClose && <IconButton icon="b-x" label={t('Close')} size="xs" onClick={onClose} />}
        </div>
      </div>
      <div className="flex h-10 shrink-0 items-center gap-1 border-b border-border px-3">
        <Tabs<Tab>
          id={tabsId}
          value={tab}
          onChange={choose}
          bordered={false}
          size="sm"
          className="h-full min-w-0 flex-1"
          tabs={tabIds.map((id) => ({ value: id, label: { content: t('Content'), design: t('Design'), interact: t('Interact') }[id], indicator: badges[id] }))}
        />
        <Tooltip content={`${t('Breakpoint')}: ${screen.label}`}>
          <span><Menu align="end" trigger={<IconButton size="xs" icon={screen.icon} label={`${t('Breakpoint')}: ${screen.label}`} />} items={screens.map((item) => ({ label: item.label, icon: item.icon, checked: item.value === breakpoint, onSelect: () => store.setBreakpoint(item.value) }))} /></span>
        </Tooltip>
      </div>
      <fieldset ref={panel} role="tabpanel" id={`${tabsId}-${tab}-panel`} aria-labelledby={`${tabsId}-${tab}`} disabled={readOnly} className="m-0 min-h-0 min-w-0 flex-1 overflow-y-auto border-0 p-0">
        {/* Keyed by block so panel state (chosen border side, open rows, drafts) starts fresh per selection. */}
        <Fragment key={node.id}>
          {tab === 'design' && <DesignPanel node={node} />}
          {tab === 'content' && <ContentPanel node={node} />}
          {tab === 'interact' && (
            <>
              <MotionPanel node={node} />
              <LogicPanel node={node} />
            </>
          )}
        </Fragment>
      </fieldset>
    </div>
  )
}
