import { t } from '@letterncms/sdk'
import { Icon, Tooltip, cn } from '@letterncms/ui'
import { useState, type ReactNode } from 'react'

/* Inspector layout: collapsible sections and small add/remove buttons. The controls
   themselves are field components (./fields), rendered by `<Fields layout="inline">`. */

const COLLAPSED = 'cms.inspector.collapsed'

function collapsed(): Record<string, boolean> {
  try {
    const saved: unknown = JSON.parse(localStorage.getItem(COLLAPSED) ?? '{}')
    return saved && typeof saved === 'object' && !Array.isArray(saved) ? Object.fromEntries(Object.entries(saved).filter(([, value]) => typeof value === 'boolean')) : {}
  } catch {
    return {}
  }
}

/**
 * A titled group of rows. Whether it is open is remembered per title; `open` is the
 * default. `empty` (nothing set) dims the title; `actions` sit before the caret.
 */
export function Section({ title, children, open: initial = true, actions, empty }: { title: string; children: ReactNode; open?: boolean; actions?: ReactNode; empty?: boolean }) {
  const [open, setOpenState] = useState(() => collapsed()[title] === undefined ? initial : !collapsed()[title])
  const setOpen = (next: boolean) => {
    setOpenState(next)
    try {
      localStorage.setItem(COLLAPSED, JSON.stringify({ ...collapsed(), [title]: !next }))
    } catch {
      // Private mode: remember for this session only.
    }
  }
  return (
    <section data-section={title} className="min-w-0 border-b border-border last:border-b-0">
      <header className="group/section flex min-h-9 items-center gap-1 pl-3 pr-2">
        <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className={cn('flex min-h-9 min-w-0 flex-1 items-center gap-1.5 py-2 text-left text-xs font-semibold', empty ? 'text-muted' : 'text-ink')}>
          <span className="min-w-0 [overflow-wrap:anywhere]">{title}</span>
          {!empty && !open && <span className="size-1 shrink-0 rounded-full bg-focus" aria-hidden />}
        </button>
        {actions}
        <button type="button" onClick={() => setOpen(!open)} className="grid size-5 shrink-0 place-items-center rounded-xs text-faint hover:bg-hover hover:text-text" aria-label={open ? t('Collapse') : t('Expand')}>
          <Icon name="b-caret-down" size={10} className={cn('transition-transform duration-120', !open && '-rotate-90')} />
        </button>
      </header>
      {open && <div className="space-y-1 px-3 pb-3">{children}</div>}
    </section>
  )
}

/** The look of core inputs at `size: 'xs'`, for the controls blocks fields draw themselves. */
export const inputClass = 'cms-focus-ring h-6 w-full min-w-0 rounded-sm border border-border-strong bg-bg px-1.5 text-2xs text-text placeholder:text-faint transition-[border-color,box-shadow] duration-120'

export function display(v: unknown): string {
  if (Array.isArray(v)) return String(v[0])
  if (v && typeof v === 'object' && 'min' in (v as any)) return `${(v as any).min}–${(v as any).max}`
  return String(v)
}

/** A color chip on a checkerboard (so transparency shows). `dark` shows the dark-mode value in its corner. */
export function Swatch({ color, dark, className }: { color: string; dark?: string | null; className?: string }) {
  return (
    <span className={cn('relative inline-block size-3.5 shrink-0 overflow-hidden rounded-[3px] align-middle shadow-[inset_0_0_0_1px_rgb(0_0_0/0.12)] [background:repeating-conic-gradient(#ddd_0_25%,#fff_0_50%)_0_0/6px_6px]', className)}>
      <span className="absolute inset-0" style={{ background: color }} />
      {dark && <span className="absolute inset-0 [clip-path:polygon(100%_50%,100%_100%,50%_100%)]" style={{ background: dark }} />}
      <span className="absolute inset-0 rounded-[inherit] shadow-[inset_0_0_0_1px_rgb(0_0_0/0.1)]" />
    </span>
  )
}

export function AddButton({ onClick, label = t('Add') }: { onClick: () => void; label?: string }) {
  return (
    <Tooltip content={label}>
      <button type="button" onClick={onClick} className="grid size-5 shrink-0 place-items-center rounded-xs text-muted hover:bg-hover hover:text-text" aria-label={label}>
        <Icon name="b-plus" size={12} />
      </button>
    </Tooltip>
  )
}

export function RemoveButton({ onClick, label = t('Remove') }: { onClick: () => void; label?: string }) {
  return (
    <button type="button" onClick={onClick} className="grid size-5 shrink-0 place-items-center rounded-xs text-faint hover:bg-hover hover:text-danger" aria-label={label}>
      <Icon name="b-minus" size={12} />
    </button>
  )
}
